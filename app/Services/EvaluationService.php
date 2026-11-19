<?php

namespace App\Services;

use App\Models\CourseOffering;
use App\Models\Enrollment;
use App\Models\EvaluationRound;
use App\Models\Student;
use App\Models\User;
use App\Support\AuditContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Students evaluate each course and its teacher once per round, anonymously: the record of who answered and
 * the answers are kept apart, with nothing (no id order, no time) that joins them. Results of a course are
 * shown only once it has at least the round's minimum number of answers, so no single student can be singled out.
 */
class EvaluationService
{
    /** @return Collection<int, EvaluationRound> */
    public function openRounds(): Collection
    {
        return EvaluationRound::with('form')->whereDate('opens_on', '<=', today())->whereDate('closes_on', '>=', today())->get();
    }

    /**
     * The courses this student still has to evaluate in the open rounds.
     *
     * @return list<array{round: EvaluationRound, offering: CourseOffering}>
     */
    public function pendingFor(Student $student): array
    {
        $out = [];
        foreach ($this->openRounds() as $round) {
            $offerings = $this->studentOfferings($student, $round);
            $done = DB::table('evaluation_submissions')->where('evaluation_round_id', $round->id)->where('student_id', $student->id)->pluck('course_offering_id')->all();
            foreach ($offerings as $o) {
                if (! in_array($o->id, $done, true)) {
                    $out[] = ['round' => $round, 'offering' => $o];
                }
            }
        }

        return $out;
    }

    /** @param  array<int|string, int|string|null>  $answers  question index => 1..5 (scale) or text */
    public function submit(Student $student, EvaluationRound $round, CourseOffering $offering, array $answers): void
    {
        if (! $round->isOpen()) {
            throw new WorkflowException('فترة التقييم مغلقة.');
        }
        if (! $this->studentOfferings($student, $round)->contains('id', $offering->id)) {
            throw new WorkflowException('هذه المادة ليست ضمن موادك في فترة التقييم.');
        }
        $scores = [];
        $comments = [];
        foreach ($round->form->questions as $i => $q) {
            $v = $answers[$i] ?? null;
            if (($q['kind'] ?? 'scale') === 'scale') {
                $iv = filter_var($v, FILTER_VALIDATE_INT);
                if ($iv === false || $iv < 1 || $iv > 5) {
                    throw new WorkflowException('أجب عن جميع الأسئلة.');
                }
                $scores[$i] = $iv;
            } elseif (is_string($v) && trim($v) !== '') {
                $comments[] = mb_substr(trim($v), 0, 1000);
            }
        }

        DB::transaction(function () use ($student, $round, $offering, $scores, $comments) {
            $exists = DB::table('evaluation_submissions')->where(['evaluation_round_id' => $round->id, 'course_offering_id' => $offering->id, 'student_id' => $student->id])->lockForUpdate()->exists();
            if ($exists) {
                throw new WorkflowException('قيّمت هذه المادة مسبقاً.');
            }
            DB::table('evaluation_submissions')->insert(['evaluation_round_id' => $round->id, 'course_offering_id' => $offering->id, 'student_id' => $student->id]);
            DB::table('evaluation_answers')->insert([
                'id' => (string) Str::uuid(), 'evaluation_round_id' => $round->id, 'course_offering_id' => $offering->id,
                'teacher_id' => $offering->teacher_id, 'scores' => json_encode($scores), 'comment' => $comments ? implode("\n", $comments) : null,
                'answered_on' => today()->toDateString(),
            ]);
        });
    }

    /**
     * Results of one round for the courses the viewer may see.
     *
     * @return array{questions: list<array{index: int, text: string}>, rows: list<array>, overall: ?float, answers: int, enrolled: int}
     */
    public function results(EvaluationRound $round, User $viewer, bool $withComments = true): array
    {
        $round->loadMissing('form');
        $offerings = $this->roundOfferings($round)->filter(fn (CourseOffering $o) => CourseOffering::visibleTo($viewer)->whereKey($o->id)->exists() || $viewer->hasRole('quality'))->values();

        return $this->summarise($round, $offerings, $withComments);
    }

    /** A teacher's own results, for rounds already closed. @return list<array> */
    public function forTeacher(User $teacher): array
    {
        $out = [];
        $rounds = EvaluationRound::with('form', 'term')->whereDate('closes_on', '<', today())->latest('closes_on')->limit(10)->get();
        foreach ($rounds as $round) {
            $offerings = $this->roundOfferings($round)->where('teacher_id', $teacher->id)->values();
            if ($offerings->isEmpty()) {
                continue;
            }
            $out[] = ['round' => ['id' => $round->id, 'name' => $round->name, 'term' => $round->term?->name_ar]] + $this->summarise($round, $offerings, true);
        }

        return $out;
    }

    /** @return Collection<int, CourseOffering> */
    public function roundOfferings(EvaluationRound $round): Collection
    {
        return CourseOffering::with(['course:id,code,name_ar,department_id', 'course.department:id,college_id,name_ar', 'teacher:id,name', 'program:id,name_ar'])
            ->where('academic_term_id', $round->academic_term_id)
            ->get()
            ->filter(fn (CourseOffering $o) => $round->college_id === null || $o->collegeId() === $round->college_id)
            ->values();
    }

    /** @return Collection<int, CourseOffering> */
    private function studentOfferings(Student $student, EvaluationRound $round): Collection
    {
        $ids = Enrollment::where('student_id', $student->id)->where('status', '!=', 'withdrawn')->pluck('course_offering_id');

        return CourseOffering::with(['course:id,code,name_ar,department_id', 'course.department:id,college_id', 'teacher:id,name'])
            ->whereIn('id', $ids)->where('academic_term_id', $round->academic_term_id)->get()
            ->filter(fn (CourseOffering $o) => $round->college_id === null || $o->collegeId() === $round->college_id)
            ->values();
    }

    private function summarise(EvaluationRound $round, Collection $offerings, bool $withComments): array
    {
        $scale = $round->form->scaleIndexes();
        $answers = DB::table('evaluation_answers')->where('evaluation_round_id', $round->id)->whereIn('course_offering_id', $offerings->pluck('id'))->get()->groupBy('course_offering_id');
        $enrolled = Enrollment::whereIn('course_offering_id', $offerings->pluck('id'))->where('status', '!=', 'withdrawn')->selectRaw('course_offering_id, count(*) as n')->groupBy('course_offering_id')->pluck('n', 'course_offering_id');

        $rows = [];
        $all = [];
        foreach ($offerings as $o) {
            $list = $answers->get($o->id, collect());
            $n = $list->count();
            $shown = $n >= $round->min_responses;
            $per = [];
            $avg = null;
            if ($shown) {
                foreach ($scale as $i) {
                    $vals = $list->map(fn ($a) => json_decode($a->scores, true)[$i] ?? null)->filter()->values();
                    $per[$i] = $vals->isEmpty() ? null : round($vals->avg(), 2);
                }
                $avg = collect($per)->filter()->avg();
                $all[] = [$avg, $n];
            }
            $rows[] = [
                'offering_id' => $o->id, 'course' => $o->course->name_ar, 'code' => $o->course->code, 'section' => $o->section,
                'department' => $o->course->department?->name_ar, 'teacher' => $o->teacher?->name, 'teacher_id' => $o->teacher_id,
                'answers' => $n, 'enrolled' => (int) ($enrolled[$o->id] ?? 0), 'shown' => $shown,
                'average' => $avg !== null ? round($avg, 2) : null, 'per_question' => $shown ? $per : null,
                'comments' => $shown && $withComments ? $list->pluck('comment')->filter()->shuffle()->values() : [],
            ];
        }
        $weight = array_sum(array_column($all, 1));

        return [
            'questions' => array_map(fn ($i) => ['index' => $i, 'text' => $round->form->questions[$i]['text']], $scale),
            'rows' => $rows,
            'overall' => $weight ? round(array_sum(array_map(fn ($x) => $x[0] * $x[1], $all)) / $weight, 2) : null,
            'answers' => array_sum(array_column($rows, 'answers')),
            'enrolled' => array_sum(array_column($rows, 'enrolled')),
        ];
    }

    public function createRound(array $data, User $by): EvaluationRound
    {
        return AuditContext::as($by->id, 'فتح فترة تقييم', fn () => EvaluationRound::create($data + ['created_by' => $by->id]));
    }
}
