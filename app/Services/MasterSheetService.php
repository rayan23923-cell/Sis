<?php

namespace App\Services;

use App\AcademicRules\Data\DecisionItem;
use App\AcademicRules\Evaluative;
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
 *  - the result: ناجح دور أول (بقرار) / راسب دور أول / ناجح دور ثاني (بقرار، بالعبور) / راسب دور ثاني;
 *  - an evaluative-exam course (الامتحان التقويمي) fails below the evaluative threshold in its final exam, unless the
 *    separate evaluative treatment covers the gap; such a failure is ضعيف and the ordinary decision cannot lift it.
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
        $collegeCode = $program->loadMissing('department.college')->department?->college?->code;
        $evaluative = $decision?->evaluative;

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
            $students[] = $this->student($sp, $own, $carriedEnrollments, $courses, $decision, $policies, $round, $maxCarried, $finalStage, $collegeCode);
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
                'final_stage_extra' => $finalStage ? $decision->finalStageExtra() : 0, 'no_fail_year' => $decision->noFailYear(),
                'evaluative' => [
                    'fail_below' => $evaluative->failBelow(), 'out_of' => $evaluative->outOf(),
                    'treatment' => $evaluative->allowance(100, $finalStage, $collegeCode), 'scale' => $evaluative->treatmentScale(),
                    'excluded' => $collegeCode !== null && in_array($collegeCode, $evaluative->excludedColleges(), true),
                ],
                'source' => $policies->get('decision')->sourceReference,
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
                'evaluative' => $os->contains('is_evaluative', true),
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
        $final2 = null;
        foreach ($components as $c) {
            $m = $marks->get($c->id);
            if (! $m || $m->mark === null || $c->exam_round > 2) {
                continue;
            }
            $points = $c->max_mark > 0 ? $m->mark / $c->max_mark * $c->weight : 0;
            if ($c->exam_round === 2) {
                if (str_starts_with($c->code, 'final')) {
                    $final2 = ($final2 ?? 0) + $points;
                }
            } elseif (str_starts_with($c->code, 'final')) {
                $final = ($final ?? 0) + $points;
            } else {
                $coursework = ($coursework ?? 0) + $points;
            }
        }
        $isFinal = fn ($c) => str_starts_with($c->code, 'final');
        $r1 = $e->results->first(fn ($r) => $r->exam_round === 1);
        $r2 = $e->results->first(fn ($r) => $r->exam_round === 2);
        $m1 = $r1 ? (float) $r1->rounded_mark : null;
        $m2 = $r2 ? (float) $r2->rounded_mark : null;
        $useR2 = $round >= 2 && $r2 !== null && $m1 !== null && $m1 < 50;

        return [
            'enrollment_id' => $e->id,
            'coursework' => $coursework !== null ? round($coursework, 1) : null,
            'final' => $final !== null ? round($final, 1) : null,
            'final2' => $final2 !== null ? round($final2, 1) : null,
            'final_weight' => (float) $components->where('exam_round', 1)->filter($isFinal)->sum('weight'),
            'final2_weight' => (float) $components->where('exam_round', 2)->filter($isFinal)->sum('weight'),
            'r1' => $m1, 'r2' => $m2,
            'mark' => $useR2 ? $m2 : $m1,
            'in_r2' => $useR2,
            'locked' => ($useR2 ? $r2 : $r1)?->status === 'locked',
            'locked1' => $r1?->status === 'locked', 'locked2' => $r2?->status === 'locked',
            'evaluative' => (bool) $e->offering->is_evaluative,
        ];
    }

    private function student(StudentProgram $sp, Collection $own, Collection $carriedEnrollments, Collection $courses, ?Decision $decision, PolicySet $policies, int $round, int $maxCarried, bool $finalStage, ?string $collegeCode = null): array
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

        $pass = $decision?->passMark() ?? 50;
        $evaluative = $decision?->evaluative ?? new Evaluative(null);

        // ---- the evaluative exam, per round: still failing after its own treatment → blocked from the decision ----
        foreach ($cells as &$c) {
            if (! $c) {
                continue;
            }
            $c['ev1'] = $c['evaluative'] && $c['r1'] !== null ? $evaluative->assess($c['final'], $c['final_weight'], $finalStage, $collegeCode) : null;
            $c['ev2'] = $c['evaluative'] && $c['r2'] !== null ? $evaluative->assess($c['final2'], $c['final2_weight'] ?: $c['final_weight'], $finalStage, $collegeCode) : null;
        }
        unset($c);
        $evFail = fn (array $c, int $r) => (bool) ($c['ev'.$r]['failed'] ?? false);
        $evAdd = fn (array $c, int $r) => (float) ($c['ev'.$r]['treated'] ?? 0);

        $present = array_filter($cells);

        // ---- the decision ----
        $awards = [];
        $used1 = 0.0;
        $extra = $finalStage && $decision ? $decision->finalStageExtra() : 0.0;
        if ($decision && $present) {
            $items1 = [];
            foreach ($present as $key => $c) {
                if ($c['r1'] !== null) {
                    $items1[] = new DecisionItem((string) $key, $c['r1'] + $evAdd($c, 1), (float) ($c['coursework'] ?? 0), $c['units'], $c['specialty_rank'], $c['carried'], $evFail($c, 1));
                }
            }
            $first = $decision->apply($items1, $decision->amount(1) + $extra, $decision->mode(1));
            $used1 = $first['used'];
            foreach ($first['awards'] as $k => $v) {
                $awards[$k] = ['marks' => $v, 'round' => 1];
            }
        }

        // a course passed in round 1 (alone, by the evaluative treatment or by the decision) keeps its round-1 mark;
        // any other course with a round-2 mark is judged on round 2
        foreach ($cells as $key => &$c) {
            if (! $c) {
                continue;
            }
            $passed1 = $c['r1'] !== null && ! $evFail($c, 1) && $pass - 1e-9 <= $c['r1'] + $evAdd($c, 1) + ($awards[$key]['marks'] ?? 0);
            $c['in_r2'] = $round >= 2 && ! $passed1 && $c['r2'] !== null && $c['r1'] !== null;
            $c['mark'] = $c['in_r2'] ? $c['r2'] : $c['r1'];
            $c['locked'] = $c['in_r2'] ? $c['locked2'] : $c['locked1'];
            $c['passed1'] = $passed1;
        }
        unset($c);
        $present = array_filter($cells);

        if ($decision && $present && $round >= 2) {
            $items2 = [];
            foreach ($present as $key => $c) {
                if (! isset($awards[$key]) && $c['in_r2'] && $c['mark'] !== null) {
                    $items2[] = new DecisionItem((string) $key, $c['mark'] + $evAdd($c, 2), (float) ($c['coursework'] ?? 0), $c['units'], $c['specialty_rank'], $c['carried'], $evFail($c, 2));
                }
            }
            $second = $decision->apply($items2, $decision->amount(1) + $extra - $used1 + $decision->amount(2), $decision->mode(2));
            foreach ($second['awards'] as $k => $v) {
                $awards[$k] = ['marks' => $v, 'round' => 2];
            }
        }

        // complete = every course has its mark; after round 2, a course not passed in round 1 needs its round-2 mark
        $complete = $present !== [] && count($present) === count($cells) && collect($present)->every(fn ($c) => $c['mark'] !== null
            && ($round < 2 || $c['passed1'] || $c['r2'] !== null));

        // ---- entitlement, grades and failures ----
        $scale = $policies->has('grade_scale') ? new GradeScale($policies->get('grade_scale')) : null;
        $failed = [];
        $sum = 0.0;
        $units = 0.0;
        foreach ($cells as $key => &$c) {
            if (! $c) {
                continue;
            }
            $r = $c['in_r2'] ? 2 : 1;
            $mark = $c['mark'];
            $award = $awards[$key]['marks'] ?? 0;
            $c['award'] = $award ?: null;
            $c['ev_failed'] = $mark !== null && $evFail($c, $r);
            $c['ev_treated'] = $mark !== null && $evAdd($c, $r) > 0 ? $evAdd($c, $r) : null;
            unset($c['ev1'], $c['ev2']);
            if ($mark === null) {
                $c['entitlement'] = null;
                $c['grade'] = null;

                continue;
            }
            $after = $mark + ($c['ev_treated'] ?? 0) + $award;
            $c['passed'] = $after >= $pass - 1e-9 && ! $c['ev_failed'];
            $deducted = $decision && ! $award && ($c['in_r2'] || $c['carried']) && $c['passed'];
            $c['entitlement'] = $c['ev_failed'] ? min($mark, $pass - 1)
                : ($award ? $pass : ($decision ? $decision->entitlement(min(100, $after), $c['in_r2'] || $c['carried']) : $after));
            $c['deducted'] = $deducted && $c['entitlement'] < $after;
            $c['grade'] = ! $c['passed'] ? 'ضعيف' : ($award ? 'مقبول بقرار' : $this->gradeName($scale, $c['entitlement']));
            if (! $c['passed']) {
                $failed[] = ['code' => ltrim((string) $key, '~'), 'name' => $c['name'], 'carried' => $c['carried'], 'evaluative' => $c['ev_failed']];
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
            'evaluative_failed' => count(array_filter($failed, fn ($f) => $f['evaluative'])),
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
