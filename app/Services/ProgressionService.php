<?php

namespace App\Services;

use App\AcademicRules\Data\GpaItem;
use App\AcademicRules\Executors\Gpa;
use App\AcademicRules\Executors\Progression;
use App\AcademicRules\RulesException;
use App\Models\AcademicYear;
use App\Models\AdministrativeOrder;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\ProgressionDecision;
use App\Models\StudentProgram;
use App\Models\StudentStatusChange;
use App\Models\User;
use App\Support\AuditContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * End-of-year evaluation: for each student of a program stage, the year's final locked results give
 * the average (gpa policy) and the decision (progression policy). Nothing moves until applied.
 */
class ProgressionService
{
    public function __construct(private readonly PolicyResolver $policies, private readonly MasterSheetService $masterSheet) {}

    /**
     * Preview for every current student of the stage, without saving.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function preview(Program $program, int $stage, AcademicYear $year): Collection
    {
        $policies = $this->policies->forProgram($program, $year->ends_on);
        $finalStage = $stage >= $program->duration_years;
        $existing = ProgressionDecision::where('academic_year_id', $year->id)->get()->keyBy('student_program_id');
        // With a "decision" policy (annual master sheet) failures and the average come from the master sheet:
        // after the decision, with the second-round deduction.
        $sheet = $policies->has('decision') ? collect($this->masterSheet->build($program, $stage, $year, 2)['students'])->keyBy('student_program_id') : null;

        return StudentProgram::where('program_id', $program->id)->where('stage', $stage)->where('is_current', true)
            ->whereHas('student', fn ($s) => $s->where('status', 'active'))
            ->with('student:id,name_ar,university_number')->get()
            ->sortBy(fn ($sp) => $sp->student->name_ar)->values()
            ->map(function (StudentProgram $sp) use ($year, $policies, $finalStage, $existing, $sheet) {
                $enrollments = Enrollment::where('student_program_id', $sp->id)->where('status', '!=', 'withdrawn')
                    ->whereHas('offering.term', fn ($t) => $t->where('academic_year_id', $year->id))
                    ->with(['offering.course', 'currentResult'])->get();

                $pending = $enrollments->filter(fn ($e) => ! $e->currentResult || $e->currentResult->status !== 'locked' || $e->currentResult->outcome === 'resit');
                $done = $enrollments->diff($pending);
                $failed = $done->filter(fn ($e) => ! $e->currentResult->passed);

                $row = [
                    'student_program_id' => $sp->id, 'student_id' => $sp->student_id,
                    'name' => $sp->student->name_ar, 'number' => $sp->student->university_number,
                    'courses' => $enrollments->count(), 'pending' => $pending->map(fn ($e) => $e->offering->course->name_ar)->values(),
                    'failed' => $failed->map(fn ($e) => ['code' => $e->offering->course->code, 'name' => $e->offering->course->name_ar])->values(),
                    'credits' => (float) $done->sum(fn ($e) => $e->currentResult->credits_earned),
                    'gpa' => null, 'decision' => null, 'problem' => null,
                    'saved' => $existing->get($sp->id)?->only(['id', 'decision', 'status']),
                    'awards' => [], 'result' => null,
                ];

                if ($enrollments->isEmpty()) {
                    $row['problem'] = 'لا توجد مواد مسجلة في هذه السنة.';

                    return $row;
                }
                if ($sheet && ($ms = $sheet->get($sp->id))) {
                    $notLocked = collect($ms['cells'])->filter()->contains(fn ($c) => ! $c['locked']);
                    if (! $ms['complete'] || $notLocked) {
                        $row['problem'] = 'نتائج غير مكتملة أو غير مقفلة في الماستر شيت.';

                        return $row;
                    }
                    $row['failed'] = collect($ms['failed'])->map(fn ($f) => ['code' => $f['code'], 'name' => $f['name']])->values();
                    $row['gpa'] = $ms['gpa'];
                    $row['awards'] = $ms['awards'];
                    $row['result'] = $ms['result'];
                    if (! $policies->has('progression')) {
                        $row['problem'] = 'لا توجد سياسة ترفيع معتمدة لنظام هذا البرنامج. أدخلها من صفحة السياسات.';

                        return $row;
                    }
                    if (collect($ms['failed'])->contains('carried', true)) {
                        // the master sheet flags this as a possible dismissal (ترقين قيد); the committee decides
                        $row['problem'] = 'راسب في مادة محمّلة. يُعرض على اللجنة الامتحانية (احتمال ترقين القيد).';

                        return $row;
                    }
                    $row['decision'] = (new Progression($policies->get('progression')))->decide(count($ms['failed']), $finalStage);

                    return $row;
                }
                if ($pending->isNotEmpty()) {
                    $row['problem'] = 'نتائج غير مكتملة أو غير مقفلة: '.$pending->count();

                    return $row;
                }
                try {
                    if ($policies->has('gpa')) {
                        $row['gpa'] = (new Gpa($policies->get('gpa')))->compute($done->map(fn ($e) => new GpaItem(
                            $e->offering->course->code, $e->currentResult->rounded_mark, $e->currentResult->grade_points,
                            (float) $e->offering->course->credit_hours, $e->offering->course->ects !== null ? (float) $e->offering->course->ects : null,
                            (bool) $e->currentResult->passed,
                        ))->values()->all());
                    }
                    if (! $policies->has('progression')) {
                        $row['problem'] = 'لا توجد سياسة ترفيع معتمدة لنظام هذا البرنامج. أدخلها من صفحة السياسات.';

                        return $row;
                    }
                    $row['decision'] = (new Progression($policies->get('progression')))->decide($failed->count(), $finalStage);
                } catch (RulesException $e) {
                    $row['problem'] = $e->getMessage();
                }

                return $row;
            });
    }

    /** Saves the computable decisions as drafts (replacing earlier drafts). */
    public function save(Program $program, int $stage, AcademicYear $year, User $user): int
    {
        $policies = $this->policies->forProgram($program, $year->ends_on);
        $snapshot = $policies->snapshot();
        $n = 0;
        DB::transaction(function () use ($program, $stage, $year, $user, $snapshot, &$n) {
            foreach ($this->preview($program, $stage, $year) as $row) {
                if (! $row['decision'] || ($row['saved']['status'] ?? null) === 'applied') {
                    continue;
                }
                ProgressionDecision::updateOrCreate(
                    ['student_program_id' => $row['student_program_id'], 'academic_year_id' => $year->id],
                    ['stage' => $stage, 'gpa' => $row['gpa'], 'courses_count' => $row['courses'], 'failed_count' => count($row['failed']),
                        'credits_earned' => $row['credits'], 'failed_courses' => $row['failed']->all(), 'decision' => $row['decision'], 'decision_awards' => $row['awards'] ?: null,
                        'policy_snapshot' => $snapshot, 'status' => 'draft', 'created_by' => $user->id],
                );
                $n++;
            }
        });

        return $n;
    }

    /** Applies the saved drafts: moves stages, graduates final-stage students. */
    public function apply(Program $program, int $stage, AcademicYear $year, AdministrativeOrder $order, User $user): int
    {
        $drafts = ProgressionDecision::where('academic_year_id', $year->id)->where('stage', $stage)->where('status', 'draft')
            ->whereHas('studentProgram', fn ($sp) => $sp->where('program_id', $program->id)->where('is_current', true))
            ->with('studentProgram.student')->get();
        if ($drafts->isEmpty()) {
            throw new WorkflowException('لا توجد قرارات محفوظة بانتظار التطبيق.');
        }

        DB::transaction(fn () => AuditContext::as($user->id, 'تطبيق قرارات نهاية السنة — '.$order->reference(), function () use ($drafts, $order, $user) {
            foreach ($drafts as $d) {
                $sp = $d->studentProgram;
                if (in_array($d->decision, ['promoted', 'promoted_with_carried'], true)) {
                    $sp->update(['stage' => $sp->stage + 1]);
                } elseif ($d->decision === 'graduate') {
                    StudentStatusChange::create([
                        'student_id' => $sp->student_id, 'from_status' => $sp->student->status, 'to_status' => 'graduated',
                        'effective_on' => $order->issued_on, 'administrative_order_id' => $order->id, 'notes' => 'تخرج', 'created_by' => $user->id,
                    ]);
                    $sp->student->update(['status' => 'graduated']);
                }
                $d->update(['status' => 'applied', 'applied_at' => now(), 'administrative_order_id' => $order->id]);
            }
        }));

        return $drafts->count();
    }
}
