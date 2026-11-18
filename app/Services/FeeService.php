<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\FeeSchedule;
use App\Models\InvoiceInstallment;
use App\Models\Payment;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentInvoice;
use App\Models\User;
use App\Support\AuditContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tuition fees: a fee schedule (set by the university for a year, college/program, admission channel,
 * shift and stage) becomes one invoice per student per year, split into installments. Payments are
 * receipts: never edited, only voided with a reason. Discounts carry a reason and are audited.
 */
class FeeService
{
    /** The most specific active schedule that applies to the student in that year, or null (no fee). */
    public function scheduleFor(Student $student, AcademicYear $year): ?FeeSchedule
    {
        $sp = $student->currentProgram()->with('program.department')->first();
        if (! $sp) {
            return null;
        }
        $program = $sp->program;

        return FeeSchedule::where('academic_year_id', $year->id)->where('is_active', true)->get()
            ->filter(fn (FeeSchedule $f) => ($f->program_id === null || $f->program_id === $program->id)
                && ($f->college_id === null || $f->college_id === $program->collegeId())
                && ($f->admission_channel === null || $f->admission_channel === $student->admission_channel)
                && ($f->study_shift === null || $f->study_shift === ($student->study_shift ?? 'morning'))
                && ($f->stage === null || $f->stage === $sp->stage))
            ->sortByDesc(fn (FeeSchedule $f) => sprintf('%03d-%09d', $f->specificity(), $f->id))
            ->first();
    }

    public function invoice(Student $student, AcademicYear $year, User $actor, ?FeeSchedule $schedule = null): StudentInvoice
    {
        if ($student->status !== 'active') {
            throw new WorkflowException('لا تُصدر مطالبة إلا لطالب مستمر.');
        }
        if (StudentInvoice::where('student_id', $student->id)->where('academic_year_id', $year->id)->where('status', '!=', 'cancelled')->exists()) {
            throw new WorkflowException('للطالب مطالبة قائمة لهذا العام.');
        }
        $schedule ??= $this->scheduleFor($student, $year);
        if (! $schedule) {
            throw new WorkflowException('لا توجد تعرفة رسوم تنطبق على هذا الطالب لهذا العام. أضف التعرفة من صفحة الرسوم أولاً.');
        }
        $sp = $student->currentProgram;

        return DB::transaction(fn () => AuditContext::as($actor->id, 'إصدار مطالبة رسوم', function () use ($student, $year, $schedule, $actor, $sp) {
            $invoice = StudentInvoice::create([
                'student_id' => $student->id, 'academic_year_id' => $year->id, 'fee_schedule_id' => $schedule->id,
                'program_id' => $sp?->program_id, 'admission_channel' => $student->admission_channel, 'study_shift' => $student->study_shift,
                'stage' => $sp?->stage, 'amount' => $schedule->amount, 'issued_by' => $actor->id,
            ]);
            $this->split($invoice, $schedule->installments);

            return $invoice;
        }));
    }

    /**
     * Invoices every active student a schedule applies to who has no invoice yet.
     *
     * @return array{created: int, skipped: int}
     */
    public function invoiceAll(AcademicYear $year, User $actor, ?Program $program = null): array
    {
        $created = $skipped = 0;
        $students = Student::where('status', 'active')
            ->whereDoesntHave('invoices', fn ($q) => $q->where('academic_year_id', $year->id)->where('status', '!=', 'cancelled'))
            ->when($program, fn ($q) => $q->whereHas('currentProgram', fn ($p) => $p->where('program_id', $program->id)))
            ->visibleTo($actor)
            ->with('currentProgram')
            ->get();
        foreach ($students as $s) {
            $schedule = $this->scheduleFor($s, $year);
            if (! $schedule) {
                $skipped++;

                continue;
            }
            $this->invoice($s, $year, $actor, $schedule);
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    public function discount(StudentInvoice $invoice, int $discount, string $reason, User $actor): void
    {
        $this->assertOpen($invoice);
        if ($discount > $invoice->amount) {
            throw new WorkflowException('التخفيض أكبر من مبلغ المطالبة.');
        }
        if ($invoice->amount - $discount < $invoice->paid()) {
            throw new WorkflowException('المبلغ بعد التخفيض أقل مما دفعه الطالب. ألغِ وصلاً أولاً.');
        }
        DB::transaction(fn () => AuditContext::as($actor->id, $reason, function () use ($invoice, $discount, $reason) {
            $invoice->update(['discount' => $discount, 'discount_reason' => $reason]);
            $invoice->installments()->delete();
            $this->split($invoice, $invoice->schedule?->installments ?? [['percent' => 100, 'due_on' => today()->toDateString()]]);
            $this->refreshStatus($invoice->fresh());
        }));
    }

    public function cancel(StudentInvoice $invoice, string $reason, User $actor): void
    {
        $this->assertOpen($invoice);
        if ($invoice->paid() > 0) {
            throw new WorkflowException('على المطالبة دفعات مسجلة. ألغِ الوصولات أولاً.');
        }
        AuditContext::as($actor->id, $reason, fn () => $invoice->update(['status' => 'cancelled', 'cancel_reason' => $reason]));
    }

    /** @param  array{amount: int, paid_on: string, method: string, reference?: ?string, notes?: ?string}  $data */
    public function pay(StudentInvoice $invoice, array $data, User $actor): Payment
    {
        $this->assertOpen($invoice);
        $amount = (int) $data['amount'];
        if ($amount <= 0) {
            throw new WorkflowException('المبلغ يجب أن يكون أكبر من صفر.');
        }
        if ($amount > $invoice->balance()) {
            throw new WorkflowException('المبلغ أكبر من المتبقي على الطالب ('.number_format($invoice->balance()).' دينار).');
        }
        if (Carbon::parse($data['paid_on'])->isAfter(today())) {
            throw new WorkflowException('لا يُسجّل دفع بتاريخ لاحق.');
        }

        return DB::transaction(fn () => AuditContext::as($actor->id, 'تسجيل دفعة', function () use ($invoice, $data, $amount, $actor) {
            $payment = Payment::create([
                'receipt_number' => $this->nextReceiptNumber(), 'student_invoice_id' => $invoice->id, 'student_id' => $invoice->student_id,
                'amount' => $amount, 'paid_on' => $data['paid_on'], 'method' => $data['method'],
                'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null, 'received_by' => $actor->id,
            ]);
            $this->refreshStatus($invoice->fresh());

            return $payment;
        }));
    }

    public function void(Payment $payment, string $reason, User $actor): void
    {
        if ($payment->voided_at) {
            throw new WorkflowException('الوصل ملغى مسبقاً.');
        }
        DB::transaction(fn () => AuditContext::as($actor->id, $reason, function () use ($payment, $reason, $actor) {
            $payment->update(['voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $reason]);
            $this->refreshStatus($payment->invoice()->first());
        }));
    }

    /**
     * Totals for a year, optionally per college.
     *
     * @return array{invoiced: int, discounts: int, collected: int, outstanding: int, overdue: int, invoices: int, paid_invoices: int}
     */
    public function totals(Collection $invoices): array
    {
        $t = ['invoiced' => 0, 'discounts' => 0, 'collected' => 0, 'outstanding' => 0, 'overdue' => 0, 'invoices' => 0, 'paid_invoices' => 0];
        foreach ($invoices as $inv) {
            if ($inv->status === 'cancelled') {
                continue;
            }
            $t['invoices']++;
            $t['invoiced'] += $inv->amount;
            $t['discounts'] += $inv->discount;
            $t['collected'] += $inv->paid();
            $t['outstanding'] += $inv->balance();
            $t['overdue'] += collect($inv->lines())->where('status', 'overdue')->sum(fn ($l) => $l['amount'] - $l['paid']);
            $t['paid_invoices'] += $inv->status === 'paid' ? 1 : 0;
        }

        return $t;
    }

    /** @param  list<array{percent: float|int, due_on: string}>  $plan */
    private function split(StudentInvoice $invoice, array $plan): void
    {
        $net = $invoice->net();
        $plan = $plan ?: [['percent' => 100, 'due_on' => today()->toDateString()]];
        $given = 0;
        foreach (array_values($plan) as $i => $line) {
            $last = $i === count($plan) - 1;
            // round each installment to whole thousands of dinars; the last one takes the remainder
            $amount = $last ? $net - $given : (int) (round($net * ((float) $line['percent']) / 100 / 1000) * 1000);
            $given += $amount;
            InvoiceInstallment::create(['student_invoice_id' => $invoice->id, 'number' => $i + 1, 'amount' => max(0, $amount), 'due_on' => $line['due_on']]);
        }
    }

    private function refreshStatus(StudentInvoice $invoice): void
    {
        $invoice->load('payments');
        if ($invoice->status === 'cancelled') {
            return;
        }
        $status = $invoice->balance() === 0 ? 'paid' : 'open';
        if ($invoice->status !== $status) {
            $invoice->update(['status' => $status]);
        }
    }

    private function assertOpen(StudentInvoice $invoice): void
    {
        if ($invoice->status === 'cancelled') {
            throw new WorkflowException('المطالبة ملغاة.');
        }
    }

    private function nextReceiptNumber(): string
    {
        $prefix = 'R'.now()->format('y');
        $last = Payment::where('receipt_number', 'like', $prefix.'-%')->lockForUpdate()->orderByDesc('id')->value('receipt_number');
        $n = $last ? ((int) substr($last, strlen($prefix) + 1)) + 1 : 1;

        return sprintf('%s-%06d', $prefix, $n);
    }
}
