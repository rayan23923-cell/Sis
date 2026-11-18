<?php

namespace App\Services;

use App\AcademicRules\Data\DecisionItem;
use App\AcademicRules\Executors\Decision;
use App\AcademicRules\Executors\GradeScale;
use App\AcademicRules\PolicySet;
use App\Models\AcademicYear;
use App\Models\CourseOffering;
use App\Models\CurriculumCourse;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\StudentProgram;
use Illuminate\Support\Collection;

/**
 * The master sheet (الماستر شيت) of a program stage for a year, computed the way the Iraqi annual-system
 * master sheet does it:
 *  - each course: coursework (السعي), final exam, round-1 mark, round-2 mark;
 *  - the decision (قرار) lifts failed courses to the pass mark in the policy's priority order;
 *  - a course passed in round 2, and a carried course, counts mark − 10 (never below 50) — الاستحقاق;
 *  - the year average is the units-weighted average of the entitlement marks, for students who passed;
 *  - the result: ناجح دور أول (بقرار) / راسب دور أول / ناجح دور ثاني (بقرار، بالعبور) / راسب دور ثاني.
 * Nothing is written: it reads the recorded results and the approved policies.
 */
class MasterSheetService
{
    public function __construct(private readonly PolicyResolver $policies) {}

    /**
     * @param  int  $round  1 = after round 1, 2 = after round 2 (final)
     * @return array{courses: list<array>, students: list<array>, carried: list<array>, stats: array, policy: ?array, official: bool, problems: list<string>}
     */
    public function build(Program $program, int $stage, AcademicYear $year, int $round = 2): array
    {
        $policies = $this->policies->forProgram($program, $year->ends_on);
        $decision = $policies->has('decision') ? new Decision($policies->get('decision')) : null;
        $problems = [];
        if (! $decision) {
            $problems[] = 'لا توجد سياسة «القرار» لنظام هذا البرنامج، فلا يُحتسب قرار ولا خصم للدور الثاني.';
        }
        $maxCarried = $policies->has('progression') ? (int) ($policies->get('progression')->parameters['max_carried'] ?? 0) : 0;
        $finalStage = $stage >= $program->duration_years;

        $offerings = CourseOffering::where('program_id', $program->id)->where('stage', $stage)
            ->whereHas('term', fn ($t) => $t->where('academic_year_id', $year->id))
            ->with(['course', 'components'])->get();
        $courses = $this->courses($program, $stage, $offerings);

        // every enrollment in this stage's offerings, grouped by student program
        $enrollments = Enrollment::whereIn('course_offering_id', $offerings->pluck('id'))->where('status', '!=', 'withdrawn')
            ->with(['marks', 'results' => fn ($q) => $q->where('is_current', true), 'student:id,name_ar,university_number,status', 'offering.components'])
            ->get();

        $current = StudentProgram::where('program_id', $program->id)->where('stage', $stage)->where('is_current', true)
            ->with('student:id,name_ar,university_number,status')->get()->keyBy('id');

        $students = [];
        foreach ($current as $sp) {
            $own = $enrollments->where('student_program_id', $sp->id)->where('is_carried', false);
            // courses of a lower stage this student carries this year
            $carriedEnrollments = Enrollment::where('student_program_id', $sp->id)->where('is_carried', true)->where('status', '!=', 'withdrawn')
                ->whereHas('offering.term', fn ($t) => $t->where('academic_year_id', $year->id))
                ->with(['marks', 'results' => fn ($q) => $q->where('is_current', true), 'offering.course', 'offering.components'])->get();
            $students[] = $this->student($sp, $own, $carriedEnrollments, $courses, $decision, $policies, $round, $maxCarried, $finalStage);
        }
        usort($students, fn ($a, $b) => strcmp($a['name'], $b['name']));

        // students of higher stages carrying a course of this stage
        $carried = $enrollments->where('is_carried', true)->groupBy('student_id')->map(fn ($es) => [
            'student_id' => $es->first()->student_id, 'name' => $es->first()->student->name_ar, 'number' => $es->first()->student->university_number,
            'courses' => $es->map(function ($e) use ($courses, $decision, $round) {
                $c = $courses->firstWhere('course_id', $e->offering->course_id);
                $cell = $this->cell($e, $c, $round);
                $cell['entitlement'] = $decision && $cell['mark'] !== null ? $decision->entitlement($cell['mark'], true) : $cell['mark'];

                return ['code' => $c['code'] ?? '', 'name' => $c['name'] ?? ''] + $cell;
            })->values()->all(),
        ])->values()->all();

        return [
            'courses' => $courses->values()->all(),
            'students' => $students,
            'carried' => $carried,
            'stats' => $this->stats($students, $courses),
            'policy' => $decision ? [
                'amount1' => $decision->amount(1), 'amount2' => $decision->amount(2), 'mode1' => $decision->mode(1), 'mode2' => $decision->mode(2),
                'priorities' => $decision->priorities(), 'deduction' => $decision->resitDeduction(), 'max_carried' => $maxCarried,
                'official' => $policies->get('decision')->isOfficial,
            ] : null,
            'official' => $policies->allOfficial(),
            'problems' => $problems,
        ];
    }

    /**
     * The outcome of one student for the year (used by the end-of-year decisions).
     *
     * @return array{failed: list<array{code: string, name: string}>, gpa: ?float, awards: array<string, float>, complete: bool}
     */
    public function outcomeFor(StudentProgram $sp, AcademicYear $year): array
    {
        $program = $sp->program;
        $sheet = $this->build($program, $sp->stage, $year, 2);
        $row = collect($sheet['students'])->firstWhere('student_program_id', $sp->id);

        return [
            'failed' => $row ? $row['failed'] : [],
            'gpa' => $row['gpa'] ?? null,
            'awards' => $row['awards'] ?? [],
            'complete' => (bool) ($row['complete'] ?? false),
        ];
    }

    /** Columns: one per course of the stage, ordered by closeness to the specialty. */
    private function courses(Program $program, int $stage, Collection $offerings): Collection
    {
        $ranks = CurriculumCourse::whereHas('curriculum', fn ($c) => $c->where('program_id', $program->id))->where('stage', $stage)
            ->orderBy('id')->get(['course_id', 'specialty_rank', 'credit_hours'])->keyBy('course_id');
        $i = 0;

        return $offerings->groupBy('course_id')->map(function (Collection $os, $courseId) use ($ranks, &$i) {
            $course = $os->first()->course;
            $comp = $os->first()->components;
            $i++;

            return [
                'course_id' => (int) $courseId, 'offering_ids' => $os->pluck('id')->all(), 'code' => $course->code, 'name' => $course->name_ar,
                'units' => (float) ($ranks[$courseId]->credit_hours ?? $course->credit_hours),
                'specialty_rank' => (int) ($ranks[$courseId]->specialty_rank ?? (100 + $i)),
                'coursework_max' => (float) $comp->where('exam_round', 1)->reject(fn ($c) => str_starts_with($c->code, 'final'))->sum('weight'),
            ];
        })->sortBy('specialty_rank')->values();
    }

    /** One course cell for an enrollment: coursework, final, round marks and the mark that counts in $round. */
    private function cell(Enrollment $e, ?array $course, int $round): array
    {
        $components = $e->offering->components;
        $marks = $e->marks->keyBy('assessment_component_id');
        $coursework = null;
        $final = null;
        foreach ($components->where('exam_round', 1) as $c) {
            $m = $marks->get($c->id);
            if (! $m || $m->mark === null) {
                continue;
            }
            $points = $c->max_mark > 0 ? $m->mark / $c->max_mark * $c->weight : 0;
            if (str_starts_with($c->code, 'final')) {
                $final = ($final ?? 0) + $points;
            } else {
                $coursework = ($coursework ?? 0) + $points;
            }
        }
        $r1 = $e->results->first(fn ($r) => $r->exam_round === 1);
        $r2 = $e->results->first(fn ($r) => $r->exam_round === 2);
        $m1 = $r1 ? (float) $r1->rounded_mark : null;
        $m2 = $r2 ? (float) $r2->rounded_mark : null;
        $useR2 = $round >= 2 && $r2 !== null && $m1 !== null && $m1 < 50;

        return [
            'enrollment_id' => $e->id,
            'coursework' => $coursework !== null ? round($coursework, 1) : null,
            'final' => $final !== null ? round($final, 1) : null,
            'r1' => $m1, 'r2' => $m2,
            'mark' => $useR2 ? $m2 : $m1,
            'in_r2' => $useR2,
            'locked' => ($useR2 ? $r2 : $r1)?->status === 'locked',
        ];
    }

    private function student(StudentProgram $sp, Collection $own, Collection $carriedEnrollments, Collection $courses, ?Decision $decision, PolicySet $policies, int $round, int $maxCarried, bool $finalStage): array
    {
        $cells = [];
        foreach ($courses as $c) {
            $e = $own->first(fn ($e) => in_array($e->course_offering_id, $c['offering_ids'], true));
            $cells[$c['code']] = $e ? $this->cell($e, $c, $round) + ['units' => $c['units'], 'specialty_rank' => $c['specialty_rank'], 'carried' => false, 'name' => $c['name']] : null;
        }
        foreach ($carriedEnrollments as $e) {
            $course = $e->offering->course;
            $cells['~'.$course->code] = $this->cell($e, null, $round) + ['units' => (float) $course->credit_hours, 'specialty_rank' => 0, 'carried' => true, 'name' => $course->name_ar];
        }

        $present = array_filter($cells);

        // ---- the decision ----
        $awards = [];
        $used1 = 0.0;
        if ($decision && $present) {
            $items1 = [];
            foreach ($present as $key => $c) {
                if ($c['r1'] !== null) {
                    $items1[] = new DecisionItem((string) $key, $c['r1'], (float) ($c['coursework'] ?? 0), $c['units'], $c['specialty_rank'], $c['carried']);
                }
            }
            $first = $decision->apply($items1, $decision->amount(1), $decision->mode(1));
            $used1 = $first['used'];
            foreach ($first['awards'] as $k => $v) {
                $awards[$k] = ['marks' => $v, 'round' => 1];
            }
            if ($round >= 2) {
                $items2 = [];
                foreach ($present as $key => $c) {
                    if (! isset($awards[$key]) && $c['in_r2'] && $c['mark'] !== null) {
                        $items2[] = new DecisionItem((string) $key, $c['mark'], (float) ($c['coursework'] ?? 0), $c['units'], $c['specialty_rank'], $c['carried']);
                    }
                }
                $second = $decision->apply($items2, $decision->amount(1) - $used1 + $decision->amount(2), $decision->mode(2));
                foreach ($second['awards'] as $k => $v) {
                    $awards[$k] = ['marks' => $v, 'round' => 2];
                }
            }
        }

        // complete = every course has its mark; after round 2, a round-1 failure the decision did not lift needs its round-2 mark
        $complete = $present !== [] && count($present) === count($cells) && collect($present)->every(fn ($c, $k) => $c['mark'] !== null
            && ($round < 2 || ! ($c['r1'] < 50 && $c['r2'] === null && ($awards[$k]['round'] ?? null) !== 1)));

        // ---- entitlement, grades and failures ----
        $scale = $policies->has('grade_scale') ? new GradeScale($policies->get('grade_scale')) : null;
        $failed = [];
        $sum = 0.0;
        $units = 0.0;
        foreach ($cells as $key => &$c) {
            if (! $c) {
                continue;
            }
            if (($awards[$key]['round'] ?? null) === 1) {
                // lifted by the round-1 decision: the round-1 mark stands, any round-2 sitting is ignored
                $c['mark'] = $c['r1'];
                $c['in_r2'] = false;
            }
            $mark = $c['mark'];
            $award = $awards[$key]['marks'] ?? 0;
            $c['award'] = $award ?: null;
            if ($mark === null) {
                $c['entitlement'] = null;
                $c['grade'] = null;

                continue;
            }
            $after = $mark + $award;
            $deducted = $decision && ! $award && ($c['in_r2'] || $c['carried']) && $after >= $decision->passMark();
            $c['entitlement'] = $award ? $decision->passMark() : ($decision ? $decision->entitlement($after, $c['in_r2'] || $c['carried']) : $after);
            $c['deducted'] = $deducted && $c['entitlement'] < $after;
            $c['passed'] = $after >= ($decision?->passMark() ?? 50);
            $c['grade'] = ! $c['passed'] ? 'ضعيف' : ($award ? 'مقبول بقرار' : $this->gradeName($scale, $c['entitlement']));
            if (! $c['passed']) {
                $failed[] = ['code' => ltrim((string) $key, '~'), 'name' => $c['name'], 'carried' => $c['carried']];
            }
            if (! $c['carried']) {
                $sum += $c['entitlement'] * $c['units'];
                $units += $c['units'];
            }
        }
        unset($c);

        $anyAward = $awards !== [];
        $n = count($failed);
        $result = ! $complete ? 'النتائج غير مكتملة'
            : ($round < 2 || collect($cells)->filter()->every(fn ($c) => ! $c['in_r2'])
                ? ($n ? 'راسب دور أول' : ($anyAward ? 'ناجح دور أول بقرار' : 'ناجح دور أول'))
                : ($n === 0 ? ($anyAward ? 'ناجح دور ثاني بقرار' : 'ناجح دور ثاني')
                    : (! $finalStage && $n <= $maxCarried && ! collect($failed)->contains('carried', true)
                        ? ($anyAward ? 'ناجح دور ثاني بالعبور بقرار' : 'ناجح دور ثاني بالعبور')
                        : 'راسب دور ثاني')));

        return [
            'student_program_id' => $sp->id, 'student_id' => $sp->student_id, 'name' => $sp->student->name_ar, 'number' => $sp->student->university_number,
            'status' => $sp->student->status,
            'cells' => $cells,
            'awards' => collect($awards)->mapWithKeys(fn ($a, $k) => [ltrim((string) $k, '~') => $a['marks']])->all(),
            'decision_used' => round(collect($awards)->sum('marks'), 2),
            'failed' => $failed,
            'complete' => $complete,
            'gpa' => $complete && $n === 0 && $units > 0 ? round($sum / $units, 2) : null,
            'result' => $result,
        ];
    }

    private function gradeName(?GradeScale $scale, float $mark): ?string
    {
        if (! $scale) {
            return null;
        }
        try {
            return $scale->band(round($mark))->nameAr;
        } catch (\Throwable) {
            return null;
        }
    }

    private function stats(array $students, Collection $courses): array
    {
        $done = array_filter($students, fn ($s) => $s['complete']);
        $passed = array_filter($done, fn ($s) => str_starts_with($s['result'], 'ناجح'));
        $perCourse = $courses->map(function ($c) use ($students) {
            $cells = array_filter(array_map(fn ($s) => $s['cells'][$c['code']] ?? null, $students), fn ($x) => $x && $x['mark'] !== null);
            $pass = array_filter($cells, fn ($x) => $x['passed'] ?? false);

            return ['code' => $c['code'], 'sat' => count($cells), 'passed' => count($pass), 'rate' => count($cells) ? round(count($pass) / count($cells) * 100) : null];
        })->values()->all();

        return [
            'students' => count($students), 'complete' => count($done), 'passed' => count($passed),
            'with_decision' => count(array_filter($done, fn ($s) => str_contains($s['result'], 'بقرار'))),
            'pass_through' => count(array_filter($done, fn ($s) => str_contains($s['result'], 'بالعبور'))),
            'rate' => count($done) ? round(count($passed) / count($done) * 100) : null,
            'courses' => $perCourse,
        ];
    }
}
