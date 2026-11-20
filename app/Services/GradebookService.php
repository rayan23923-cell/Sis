<?php

namespace App\Services;

use App\AcademicRules\CourseResultCalculator;
use App\AcademicRules\Data\ComponentMark;
use App\AcademicRules\Evaluative;
use App\AcademicRules\Executors\ExamRounds;
use App\AcademicRules\Executors\GradeScale;
use App\AcademicRules\PolicySet;
use App\AcademicRules\RulesException;
use App\Models\AdministrativeOrder;
use App\Models\AssessmentComponent;
use App\Models\AssessmentMark;
use App\Models\CourseOffering;
use App\Models\Enrollment;
use App\Models\FinalResult;
use App\Models\Student;
use App\Models\User;
use App\Notifications\Notice;
use App\Support\AuditContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The marks-to-locked-result workflow of one course offering, one exam round at a time:
 *   open (teacher enters marks, computes drafts) → submitted → approved (committee) → locked.
 * After a lock, students with a resit outcome can sit the next round, if the rules allow one.
 * A locked result is never edited: a correction is a new version backed by an administrative order.
 */
class GradebookService
{
    public function __construct(
        private readonly PolicyResolver $policies,
        private readonly CourseResultCalculator $calculator = new CourseResultCalculator,
    ) {}

    public function ensureComponents(CourseOffering $offering): void
    {
        if ($offering->components()->exists()) {
            return;
        }
        foreach (config('sis.default_components') as $i => $c) {
            $offering->components()->create($c + ['sort' => $i]);
        }
    }

    /** Components that make up the total in a round: a round-2 "X_r2" replaces round-1 "X". */
    public function componentsForRound(CourseOffering $offering, int $round): Collection
    {
        $all = $offering->components()->get();
        $set = $all->where('exam_round', 1)->keyBy('code');
        for ($r = 2; $r <= $round; $r++) {
            foreach ($all->where('exam_round', $r) as $c) {
                $set->forget(preg_replace('/_r\d+$/', '', $c->code));
                $set->put($c->code, $c);
            }
        }

        return $set->values()->sortBy(fn ($c) => [$c->exam_round, $c->sort])->values();
    }

    /** Components a teacher fills in this round (round 1 = all round-1 ones; later = the new ones). */
    public function editableComponents(CourseOffering $offering, int $round): Collection
    {
        return $offering->components()->where('exam_round', $round)->orderBy('sort')->get();
    }

    /** Enrollments that sit this round: everyone enrolled in round 1; only resits afterwards. */
    public function eligible(CourseOffering $offering, int $round): Collection
    {
        $q = $offering->enrollments()->where('status', '!=', 'withdrawn')
            ->with(['student', 'marks', 'results.order', 'studentProgram.program.department']);

        $rows = $q->get()->sortBy(fn ($e) => $e->student->name_ar)->values();
        if ($round === 1) {
            return $rows;
        }

        return $rows->filter(function (Enrollment $e) use ($round) {
            $prev = $e->results->first(fn ($r) => $r->exam_round === $round - 1 && $r->is_current);

            return $prev && $prev->status === 'locked' && $prev->outcome === 'resit';
        })->values();
    }

    /**
     * @param  array<int|string, array<int|string, array{mark?: mixed, absent?: mixed}>>  $rows  enrollment id → component id → value
     */
    public function saveMarks(CourseOffering $offering, User $user, array $rows): int
    {
        $saved = 0;

        $this->transition($offering, 'open', 'لا يمكن تعديل الدرجات بعد رفعها. اطلب إعادتها من اللجنة الامتحانية.', function (CourseOffering $offering) use ($rows, $user, &$saved) {
            $components = $this->editableComponents($offering, $offering->current_round)->keyBy('id');
            $eligible = $this->eligible($offering, $offering->current_round)->keyBy('id');
            foreach ($rows as $enrollmentId => $values) {
                if (! $eligible->has((int) $enrollmentId)) {
                    continue;
                }
                foreach ($values as $componentId => $v) {
                    $component = $components->get((int) $componentId);
                    if (! $component) {
                        continue;
                    }
                    $absent = filter_var($v['absent'] ?? false, FILTER_VALIDATE_BOOL);
                    $raw = $v['mark'] ?? null;
                    $mark = ($raw === null || $raw === '') ? null : (float) $raw;
                    if ($mark !== null && ($mark < 0 || $mark > $component->max_mark)) {
                        throw new WorkflowException("الدرجة {$mark} في «{$component->name_ar}» خارج المدى 0–".(float) $component->max_mark.'.');
                    }
                    $row = AssessmentMark::firstOrNew(['enrollment_id' => (int) $enrollmentId, 'assessment_component_id' => $component->id]);
                    $row->fill(['mark' => $absent ? null : $mark, 'is_absent' => $absent, 'entered_by' => $user->id]);
                    if ($row->isDirty()) {
                        $row->save();
                        $saved++;
                    }
                }
            }
        });

        return $saved;
    }

    /**
     * Compute draft results for everyone in the current round who has complete marks.
     *
     * @return array{computed: int, problems: array<int, string>}
     */
    public function compute(CourseOffering $offering, User $user): array
    {
        $computed = 0;
        $problems = [];

        $this->transition($offering, 'open', 'الاحتساب متاح فقط والمادة مفتوحة لإدخال الدرجات.', function (CourseOffering $offering) use ($user, &$computed, &$problems) {
            $round = $offering->current_round;
            $components = $this->componentsForRound($offering, $round);
            $credits = (float) $offering->course->credit_hours;
            foreach ($this->eligible($offering, $round) as $enrollment) {
                try {
                    $this->computeOne($enrollment, $round, $components, $credits, $user);
                    $computed++;
                } catch (RulesException $e) {
                    $problems[$enrollment->id] = $this->explain($e->getMessage(), $components);
                }
            }
        });

        return ['computed' => $computed, 'problems' => $problems];
    }

    public function submit(CourseOffering $offering, User $user): void
    {
        $this->transition($offering, 'open', 'المادة ليست في مرحلة إدخال الدرجات.', function (CourseOffering $offering) use ($user) {
            $round = $offering->current_round;
            $eligible = $this->eligible($offering, $round);
            $missing = $eligible->filter(fn (Enrollment $e) => ! $this->draftFor($e, $round));
            if ($missing->isNotEmpty()) {
                throw new WorkflowException('لم تُحتسب نتائج '.$missing->count().' طالب بعد. أكمل الدرجات ثم اضغط «احتساب النتائج».');
            }
            // F-G3-1: every draft must still be what the current marks and the year's policies give. A mark saved
            // after the last computation, or a policy approved since, makes a draft stale; submitting it would send
            // the committee a result that does not match the gradebook.
            $components = $this->componentsForRound($offering, $round);
            $credits = (float) $offering->course->credit_hours;
            $stale = $eligible->filter(function (Enrollment $e) use ($round, $components, $credits) {
                $draft = $this->draftFor($e, $round);
                try {
                    $now = $this->resultData($e, $round, $components, $credits);
                } catch (RulesException) {
                    return true;
                }

                return $draft->input_hash !== $now['input_hash'] || $draft->policy_snapshot != $now['policy_snapshot'];
            });
            if ($stale->isNotEmpty()) {
                throw new WorkflowException('تغيرت درجات '.$stale->count().' طالب أو السياسات المعتمدة بعد آخر احتساب. اضغط «احتساب النتائج» ثم ارفع.');
            }
            AuditContext::as($user->id, 'رفع النتائج إلى اللجنة الامتحانية', fn () => $offering->update(['status' => 'submitted']));
        });
    }

    public function returnToTeacher(CourseOffering $offering, User $user, string $reason): void
    {
        $this->transition($offering, 'submitted', 'لا يمكن إعادة مادة لم تُرفع.',
            fn (CourseOffering $offering) => AuditContext::as($user->id, $reason, fn () => $offering->update(['status' => 'open'])));
    }

    public function approve(CourseOffering $offering, User $user): void
    {
        $this->transition($offering, 'submitted', 'المصادقة متاحة بعد رفع النتائج فقط.', fn (CourseOffering $offering) => AuditContext::as($user->id, 'مصادقة اللجنة الامتحانية', function () use ($offering, $user) {
            $this->roundResults($offering)->where('status', 'draft')->each(fn (FinalResult $r) => $r->update([
                'status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now(),
            ]));
            $offering->update(['status' => 'approved']);
        }));
    }

    public function lock(CourseOffering $offering, User $user): void
    {
        $this->transition($offering, 'approved', 'القفل متاح بعد المصادقة فقط.', fn (CourseOffering $offering) => AuditContext::as($user->id, 'قفل النتائج', function () use ($offering) {
            foreach ($this->roundResults($offering)->where('status', 'approved') as $r) {
                $r->update(['status' => 'locked', 'locked_at' => now()]);
                if ($r->outcome !== 'resit') {
                    $r->enrollment->update(['status' => 'completed']);
                }
            }
            $offering->update(['status' => 'locked']);
        }));
        $this->announce($offering);
    }

    /** The students of a locked course see their result in the portal: tell them. */
    private function announce(CourseOffering $offering): void
    {
        $offering->loadMissing('course');
        $users = User::whereIn('id', Student::whereIn('id', $offering->enrollments()->select('student_id'))->whereNotNull('user_id')->select('user_id'))->get();
        app(Notifier::class)->send($users, new Notice('أُعلنت نتيجة', $offering->course->name_ar.' — الدور '.$offering->current_round.'. افتح البوابة لمعرفة درجتك.', route('portal.home'), 'success'));
    }

    public function canOpenNextRound(CourseOffering $offering): bool
    {
        if ($offering->status !== 'locked') {
            return false;
        }
        $next = $offering->current_round + 1;

        return $offering->components()->where('exam_round', $next)->exists()
            && $this->eligible($offering, $next)->isNotEmpty();
    }

    public function openNextRound(CourseOffering $offering, User $user): void
    {
        $refused = 'لا يوجد طلبة مؤهلون للدور التالي، أو لم تُقفل نتائج الدور الحالي بعد.';
        $this->transition($offering, 'locked', $refused, function (CourseOffering $offering) use ($user, $refused) {
            if (! $this->canOpenNextRound($offering)) {
                throw new WorkflowException($refused);
            }
            AuditContext::as($user->id, 'فتح الدور الامتحاني التالي', fn () => $offering->update([
                'current_round' => $offering->current_round + 1, 'status' => 'open',
            ]));
        });
    }

    /**
     * Correct a locked result: new marks, a new locked version, backed by an administrative order.
     *
     * @param  array<int|string, array{mark?: mixed, absent?: mixed}>  $marks  component id → value
     */
    public function correct(FinalResult $result, array $marks, AdministrativeOrder $order, User $user, string $reason): FinalResult
    {
        if ($result->status !== 'locked' || ! $result->is_current) {
            throw new WorkflowException('التصحيح يكون على النتيجة المقفلة الحالية فقط.');
        }
        if ($order->type !== 'result_correction') {
            throw new WorkflowException('يجب أن يكون الأمر الإداري من نوع «تصحيح نتيجة».');
        }

        return DB::transaction(fn () => AuditContext::as($user->id, $reason.' — '.$order->reference(), function () use ($result, $marks, $order, $user) {
            // B-07: re-read the result under a row lock; of two simultaneous corrections only the first finds it current
            $result = FinalResult::whereKey($result->id)->lockForUpdate()->firstOrFail();
            if ($result->status !== 'locked' || ! $result->is_current) {
                throw new WorkflowException('التصحيح يكون على النتيجة المقفلة الحالية فقط.');
            }
            $enrollment = $result->enrollment()->with(['studentProgram.program.department', 'offering.course', 'marks'])->first();
            $offering = $enrollment->offering;
            $components = $this->componentsForRound($offering, $result->exam_round)->keyBy('id');

            foreach ($marks as $componentId => $v) {
                $component = $components->get((int) $componentId);
                if (! $component) {
                    continue;
                }
                $absent = filter_var($v['absent'] ?? false, FILTER_VALIDATE_BOOL);
                $mark = ($v['mark'] ?? '') === '' ? null : (float) $v['mark'];
                if ($mark !== null && ($mark < 0 || $mark > $component->max_mark)) {
                    throw new WorkflowException("الدرجة {$mark} في «{$component->name_ar}» خارج المدى.");
                }
                AssessmentMark::updateOrCreate(
                    ['enrollment_id' => $enrollment->id, 'assessment_component_id' => $component->id],
                    ['mark' => $absent ? null : $mark, 'is_absent' => $absent, 'entered_by' => $user->id],
                );
            }
            $enrollment->load('marks');

            try {
                // a correction changes marks, not rules: it is computed with the policies of the result it corrects (FR-AR-19)
                $data = $this->resultData($enrollment, $result->exam_round, $components->values(), (float) $offering->course->credit_hours,
                    PolicySet::fromResultSnapshot($result->policy_snapshot));
            } catch (RulesException $e) {
                throw new WorkflowException($this->explain($e->getMessage()));
            }

            $result->update(['is_current' => false]);

            return FinalResult::create($data + [
                'enrollment_id' => $enrollment->id,
                'exam_round' => $result->exam_round,
                'version' => $result->version + 1,
                'status' => 'locked',
                'supersedes_id' => $result->id,
                'administrative_order_id' => $order->id,
                'is_current' => true,
                'computed_by' => $user->id,
                'approved_by' => $user->id,
                'approved_at' => now(),
                'locked_at' => now(),
            ]);
        }));
    }

    private function computeOne(Enrollment $enrollment, int $round, Collection $components, float $credits, User $user): void
    {
        $data = $this->resultData($enrollment, $round, $components, $credits) + ['computed_by' => $user->id];
        $draft = $this->draftFor($enrollment, $round);
        if ($draft) {
            if ($draft->input_hash !== $data['input_hash'] || $draft->policy_snapshot != $data['policy_snapshot']) {
                $draft->update($data);
            }

            return;
        }
        $version = (int) $enrollment->results->where('exam_round', $round)->max('version') + 1;
        FinalResult::create($data + [
            'enrollment_id' => $enrollment->id, 'exam_round' => $round, 'version' => $version,
            'status' => 'draft', 'is_current' => true,
        ]);
        $enrollment->unsetRelation('results');
    }

    /** @return array<string, mixed> */
    private function resultData(Enrollment $enrollment, int $round, Collection $components, float $credits, ?PolicySet $policies = null): array
    {
        $policies ??= $this->policies->forProgram($enrollment->studentProgram->program, $enrollment->offering->policyDate());
        $marks = $enrollment->marks->keyBy('assessment_component_id');
        $input = $components->map(fn (AssessmentComponent $c) => new ComponentMark(
            $c->code, (float) $c->weight, (float) $c->max_mark,
            $marks->get($c->id)?->mark, (bool) $marks->get($c->id)?->is_absent,
        ))->all();

        $r = $this->calculator->calculate($policies, $input, $credits);
        $rounded = $r->rounded;
        $band = $r->band;
        $snapshot = $r->policySnapshot;
        $outcome = $band->outcome;

        if ($policies->has('rounds')) {
            $rounds = new ExamRounds($policies->get('rounds'));
            $snapshot['rounds'] = $policies->get('rounds')->snapshot();
            $cap = $rounds->cap($round);
            if ($cap !== null && $rounded > $cap) {
                $rounded = $cap;
                $band = (new GradeScale($policies->get('grade_scale')))->band($rounded);
            }
            $outcome = $rounds->finalOutcome($band->outcome, $round);
        }

        // the national evaluative exam: below the threshold in the final exam the course fails, whatever the total
        if ($enrollment->offering->is_evaluative) {
            $decision = $policies->has('decision') ? $policies->get('decision') : null;
            $evaluative = new Evaluative($decision);
            $finals = $components->filter(fn (AssessmentComponent $c) => str_starts_with($c->code, 'final'));
            $weight = (float) $finals->sum('weight');
            $points = $finals->sum(fn (AssessmentComponent $c) => $c->max_mark > 0 ? (float) ($marks->get($c->id)?->mark ?? 0) / $c->max_mark * $c->weight : 0);
            $snapshot['evaluative'] = ['final' => round($points, 2), 'threshold' => $evaluative->threshold($weight), 'decision' => $decision?->snapshot()];
            if ($band->passed && $evaluative->fails($points, $weight)) {
                $scale = new GradeScale($policies->get('grade_scale'));
                $failing = collect($scale->bands())->filter(fn ($b) => ! $b->passed)->last();
                if ($failing) {
                    $band = $failing;
                    $outcome = isset($rounds) ? $rounds->finalOutcome($band->outcome, $round) : $band->outcome;
                }
            }
        }

        return [
            'total_mark' => $r->total,
            'rounded_mark' => $rounded,
            'grade_code' => $band->code,
            'grade_name_ar' => $band->nameAr,
            'grade_points' => $band->points,
            'passed' => $band->passed,
            'outcome' => $outcome,
            'credits_earned' => $band->passed ? $credits : 0,
            'policy_snapshot' => $snapshot,
            'input_hash' => $r->inputHash,
        ];
    }

    private function draftFor(Enrollment $enrollment, int $round): ?FinalResult
    {
        return $enrollment->results()->where('exam_round', $round)->where('is_current', true)->where('status', 'draft')->first();
    }

    private function roundResults(CourseOffering $offering): Collection
    {
        return FinalResult::whereIn('enrollment_id', $offering->enrollments()->select('id'))
            ->where('exam_round', $offering->current_round)->where('is_current', true)->with('enrollment')->get();
    }

    /**
     * B-07 (FR-CC-03): every status change of an offering runs in one transaction that first re-reads the offering
     * under a row lock and checks its status there. Two people pressing the same button at once: the second waits,
     * then sees the new status and is refused. The caller's model is refreshed to the locked row.
     *
     * @template T
     *
     * @param  callable(CourseOffering): T  $fn
     * @return T
     */
    private function transition(CourseOffering $offering, string $status, string $message, callable $fn): mixed
    {
        return DB::transaction(function () use ($offering, $status, $message, $fn) {
            $locked = CourseOffering::whereKey($offering->id)->lockForUpdate()->firstOrFail();
            $offering->setRawAttributes($locked->getAttributes(), true);
            if ($offering->status !== $status) {
                throw new WorkflowException($message);
            }

            return $fn($offering);
        });
    }

    private function explain(string $engineMessage, ?Collection $components = null): string
    {
        $code = preg_replace("/.*'(.+)'.*/", '$1', $engineMessage);
        $name = $components?->firstWhere('code', $code)?->name_ar ?? $code;

        return match (true) {
            str_contains($engineMessage, 'is missing') => 'درجة ناقصة: '.$name,
            str_contains($engineMessage, 'No approved') => 'لا توجد سياسة أكاديمية معتمدة تنطبق على هذا البرنامج.',
            str_contains($engineMessage, 'weights add up') => 'مجموع أوزان المكونات لا يساوي 100.',
            default => $engineMessage,
        };
    }
}
