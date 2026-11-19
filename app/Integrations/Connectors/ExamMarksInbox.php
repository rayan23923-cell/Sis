<?php

namespace App\Integrations\Connectors;

use App\Models\ConnectorMarkBatch;
use App\Models\CourseOffering;
use App\Models\IntegrationLog;
use App\Models\User;
use App\Notifications\Notice;
use App\Services\GradebookService;
use App\Services\Notifier;
use App\Services\WorkflowException;
use Illuminate\Support\Facades\DB;

/**
 * Marks from the exams system never go straight into the gradebook. They wait as a batch on the course page;
 * the teacher accepts them (they then go through the same checks as marks typed by hand) or rejects them.
 */
class ExamMarksInbox
{
    public function __construct(private readonly GradebookService $gradebook) {}

    /**
     * @param  array<int, array{university_number: string, mark?: float|int|string|null, absent?: bool}>  $marks
     * @return array{batch: ConnectorMarkBatch, created: bool}
     */
    public function receive(CourseOffering $offering, string $componentCode, string $reference, array $marks): array
    {
        if ($existing = ConnectorMarkBatch::where('connector', 'exams')->where('reference', $reference)->first()) {
            return ['batch' => $existing, 'created' => false];
        }
        if ($offering->status !== 'open') {
            throw new WorkflowException('نتائج هذه الشعبة مرفوعة أو مقفلة، فلا تُستلم درجات لها الآن.');
        }
        $round = $offering->current_round;
        $component = $this->gradebook->editableComponents($offering, $round)->firstWhere('code', $componentCode);
        if (! $component) {
            throw new WorkflowException("المكوّن «{$componentCode}» غير موجود في الدور الحالي ({$round}) لهذه الشعبة.");
        }

        $eligible = $this->gradebook->eligible($offering, $round)->keyBy(fn ($e) => (string) $e->student->university_number);
        $rows = [];
        $unknown = [];
        $problems = [];
        foreach ($marks as $m) {
            $number = (string) ($m['university_number'] ?? '');
            $e = $eligible->get($number);
            if (! $e) {
                $unknown[] = $number;

                continue;
            }
            $absent = (bool) ($m['absent'] ?? false);
            $raw = $m['mark'] ?? null;
            $mark = ($absent || $raw === null || $raw === '') ? null : (float) $raw;
            if ($mark !== null && ($mark < 0 || $mark > (float) $component->max_mark)) {
                $problems[] = "{$number}: {$mark}";

                continue;
            }
            $rows[$e->id] = ['number' => $number, 'name' => $e->student->name_ar, 'mark' => $mark, 'absent' => $absent];
        }
        if ($problems) {
            throw (new WorkflowException('درجات خارج المدى 0–'.(float) $component->max_mark.'.'))->withProblems($problems);
        }
        if (! $rows) {
            throw (new WorkflowException('لا يوجد في الإرسال أي طالب من طلبة هذه الشعبة في الدور الحالي.'))->withProblems(array_slice($unknown, 0, 20));
        }

        $batch = DB::transaction(function () use ($offering, $component, $round, $reference, $rows, $unknown) {
            ConnectorMarkBatch::where('course_offering_id', $offering->id)->where('assessment_component_id', $component->id)
                ->where('status', 'pending')->update(['status' => 'superseded']);

            return ConnectorMarkBatch::create([
                'connector' => 'exams', 'reference' => $reference, 'course_offering_id' => $offering->id,
                'assessment_component_id' => $component->id, 'exam_round' => $round, 'rows' => $rows, 'unknown' => $unknown ?: null,
            ]);
        });

        IntegrationLog::create([
            'system' => 'examnest', 'operation' => 'marks_received', 'idempotency_key' => 'exams:'.$reference, 'status' => 'done', 'attempts' => 1,
            'payload' => ['offering' => $offering->id, 'component' => $componentCode, 'count' => count($marks)], 'response' => ['batch' => $batch->id, 'unknown' => count($unknown)],
        ]);

        if ($offering->teacher) {
            app(Notifier::class)->send($offering->teacher, new Notice(
                'درجات واردة من نظام الامتحانات',
                "{$component->name_ar} — {$offering->course->name_ar}: ".count($rows).' طالب. راجعها واعتمدها من صفحة المادة.',
                route('offerings.show', $offering), 'info'));
        }

        return ['batch' => $batch, 'created' => true];
    }

    public function apply(ConnectorMarkBatch $batch, User $user): int
    {
        $this->requirePending($batch);
        $offering = $batch->offering;
        if ($batch->exam_round !== $offering->current_round) {
            throw new WorkflowException('هذه الدرجات لدور سابق. اطلب من نظام الامتحانات إرسالها من جديد.');
        }
        $rows = [];
        foreach ($batch->rows as $enrollmentId => $r) {
            $rows[$enrollmentId] = [$batch->assessment_component_id => ['mark' => $r['mark'], 'absent' => $r['absent']]];
        }

        return DB::transaction(function () use ($batch, $offering, $user, $rows) {
            $saved = $this->gradebook->saveMarks($offering, $user, $rows);
            $batch->update(['status' => 'applied', 'reviewed_by' => $user->id, 'reviewed_at' => now()]);

            return $saved;
        });
    }

    public function reject(ConnectorMarkBatch $batch, User $user, ?string $note): void
    {
        $this->requirePending($batch);
        $batch->update(['status' => 'rejected', 'reviewed_by' => $user->id, 'reviewed_at' => now(), 'note' => $note]);
    }

    private function requirePending(ConnectorMarkBatch $batch): void
    {
        if ($batch->status !== 'pending') {
            throw new WorkflowException('عولجت هذه الدرجات من قبل.');
        }
    }
}
