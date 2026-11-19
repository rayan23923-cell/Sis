<?php

namespace App\Services\Payments;

use App\Models\OnlinePayment;
use App\Models\StudentInvoice;
use App\Models\User;
use App\Notifications\Notice;
use App\Services\FeeService;
use App\Services\Notifier;
use App\Services\WorkflowException;
use App\Support\AuditContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A student pays an installment or the whole balance through a gateway. The attempt becomes a receipt only
 * when the gateway itself confirms it (never on the strength of the browser's return), exactly once, and only
 * if the invoice still owes that much; otherwise it is held for the accountant to review.
 */
class OnlinePaymentService
{
    /** States in which the gateway may still report the payment as paid. */
    private const CHECKABLE = ['pending', 'expired', 'cancelled'];

    public function __construct(private Gateways $gateways, private FeeService $fees, private Notifier $notifier) {}

    /** The amount for "the next installment" or "everything left". */
    public function amountFor(StudentInvoice $invoice, string $what): int
    {
        $invoice->loadMissing(['installments', 'payments']);
        if ($what === 'full') {
            return $invoice->balance();
        }
        foreach ($invoice->lines() as $l) {
            if ($l['amount'] > $l['paid']) {
                return min($l['amount'] - $l['paid'], $invoice->balance());
            }
        }

        return $invoice->balance();
    }

    public function start(StudentInvoice $invoice, string $gatewayKey, string $what, User $by): OnlinePayment
    {
        $gateway = $this->gateways->get($gatewayKey);
        if (! $gateway->enabled()) {
            throw new WorkflowException('بوابة الدفع هذه غير مفعّلة.');
        }
        if ($invoice->status !== 'open') {
            throw new WorkflowException('لا يوجد مبلغ مطلوب على هذه المطالبة.');
        }
        $open = OnlinePayment::where('student_invoice_id', $invoice->id)->where('status', 'pending')->latest('id')->first();
        if ($open) {
            $this->refresh($open);
            if ($open->fresh()->status === 'pending') {
                return $open;
            }
        }
        $amount = $this->amountFor($invoice->fresh(), $what);
        if ($amount <= 0) {
            throw new WorkflowException('لا يوجد مبلغ مطلوب على هذه المطالبة.');
        }
        if ($amount < config('payments.minimum')) {
            throw new WorkflowException('المبلغ أقل من الحد الأدنى للدفع الإلكتروني ('.number_format(config('payments.minimum')).' دينار). يُدفع لدى المحاسب.');
        }

        $payment = AuditContext::as($by->id, 'بدء دفع إلكتروني', fn () => OnlinePayment::create([
            'uuid' => (string) Str::uuid(), 'student_invoice_id' => $invoice->id, 'student_id' => $invoice->student_id,
            'gateway' => $gatewayKey, 'amount' => $amount, 'created_by' => $by->id, 'expires_at' => now()->addMinutes(config('payments.ttl')),
        ]));

        try {
            $started = $gateway->start($payment, route('payments.return', $payment), route('payments.notify', $gatewayKey));
        } catch (GatewayException $e) {
            $payment->update(['status' => 'failed', 'message' => $e->getMessage()]);

            throw new WorkflowException($e->getMessage());
        }
        $payment->update(['external_id' => $started->externalId ?: null, 'redirect_url' => $started->redirectUrl, 'display' => $started->display ?: null]);

        return $payment;
    }

    /** Asks the gateway and acts on the answer. Returns the payment as it now stands. */
    public function refresh(OnlinePayment $payment): OnlinePayment
    {
        if (! in_array($payment->status, self::CHECKABLE, true)) {
            return $payment;
        }
        try {
            $status = $this->gateways->get($payment->gateway)->status($payment);
        } catch (GatewayException) {
            return $payment;
        }
        $payment->forceFill(['checked_at' => now()])->saveQuietly();

        if ($status->state === Status::PAID) {
            $this->settle($payment, $status);
        } elseif ($status->state === Status::FAILED) {
            $payment->update(['status' => 'failed', 'message' => $status->message]);
        } elseif ($payment->status === 'pending' && $payment->expires_at?->isPast()) {
            $payment->update(['status' => 'expired']);
        }

        return $payment->fresh();
    }

    public function cancel(OnlinePayment $payment, User $by): void
    {
        $payment = $this->refresh($payment);
        if ($payment->status !== 'pending') {
            return;
        }
        AuditContext::as($by->id, 'إلغاء دفع إلكتروني', fn () => $payment->update(['status' => 'cancelled']));
    }

    /** The accountant has dealt with a payment that could not become a receipt (refunded it, or settled it by hand). */
    public function markReviewed(OnlinePayment $payment, string $note, User $by): void
    {
        if ($payment->status !== 'review' || $payment->reviewed_by) {
            throw new WorkflowException('هذه الدفعة لا تحتاج مراجعة.');
        }
        AuditContext::as($by->id, $note, fn () => $payment->update(['reviewed_by' => $by->id, 'message' => mb_substr($payment->message.' — '.$note, 0, 250)]));
    }

    private function settle(OnlinePayment $payment, Status $status): void
    {
        $result = DB::transaction(function () use ($payment, $status) {
            $p = OnlinePayment::whereKey($payment->id)->lockForUpdate()->first();
            if (! in_array($p->status, self::CHECKABLE, true)) {
                return null;   // someone else settled it a moment ago
            }
            $invoice = StudentInvoice::whereKey($p->student_invoice_id)->lockForUpdate()->with(['payments', 'installments'])->first();
            $label = $this->gateways->get($p->gateway)->label();

            $problem = match (true) {
                $status->amount !== null && $status->amount !== $p->amount => 'البوابة أكدت مبلغاً ('.number_format($status->amount).') يختلف عن المطلوب.',
                $invoice->status === 'cancelled' => 'المطالبة ملغاة.',
                $invoice->balance() < $p->amount => 'المتبقي على الطالب ('.number_format($invoice->balance()).') أقل من المبلغ المدفوع؛ يحتاج استرجاعاً أو تسوية.',
                default => null,
            };
            if ($problem) {
                $p->update(['status' => 'review', 'message' => $problem, 'settled_at' => now()]);

                return ['review', $p, $invoice];
            }

            $receipt = $this->fees->pay($invoice, [
                'amount' => $p->amount, 'paid_on' => today()->toDateString(), 'method' => 'electronic',
                'reference' => mb_substr($label.': '.($status->reference ?? $p->external_id), 0, 190),
                'notes' => 'دفع إلكتروني عبر '.$label,
            ], null);
            $p->update(['status' => 'paid', 'payment_id' => $receipt->id, 'settled_at' => now(), 'message' => null]);

            return ['paid', $p, $invoice];
        });
        if (! $result) {
            return;
        }
        [$kind, $p, $invoice] = $result;
        $student = $invoice->student()->with('user', 'currentProgram.program')->first();
        if ($kind === 'paid') {
            $this->notifier->send($student->user ?? collect(), new Notice('وصلت دفعتك', 'استُلمت دفعة '.number_format($p->amount).' دينار إلكترونياً وصدر وصلها.', route('portal.home', ['tab' => 'fees']), 'success'));

            return;
        }
        $collegeId = $student->currentProgram?->program?->collegeId();
        $staff = $collegeId ? $this->notifier->staffOf($collegeId, 'accountant') : collect();
        $this->notifier->send($staff, new Notice('دفعة إلكترونية تحتاج مراجعة', $student->name_ar.': '.$p->message, route('finance.student', $student->id), 'warning'));
        $this->notifier->send($student->user ?? collect(), new Notice('دفعتك قيد المراجعة', 'استلمنا دفعتك وتحتاج مراجعة من المحاسب قبل إصدار الوصل.', route('portal.home', ['tab' => 'fees']), 'warning'));
    }
}
