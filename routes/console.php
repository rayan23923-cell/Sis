<?php

use App\Models\OnlinePayment;
use App\Models\StudentInvoice;
use App\Notifications\Notice;
use App\Services\Notifier;
use App\Services\Payments\OnlinePaymentService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily: remind students of an installment due within 3 days, and of an overdue one (once each).
Artisan::command('sis:fee-reminders', function () {
    $sent = 0;
    StudentInvoice::where('status', 'open')->with(['installments', 'payments', 'student.user'])->chunkById(200, function ($invoices) use (&$sent) {
        foreach ($invoices as $invoice) {
            $user = $invoice->student?->user;
            if (! $user) {
                continue;
            }
            foreach ($invoice->lines() as $line) {
                $left = $line['amount'] - $line['paid'];
                $due = Carbon::parse($line['due_on']);
                $kind = $line['status'] === 'overdue' ? 'overdue' : ($left > 0 && $due->isFuture() && today()->diffInDays($due) <= 3 ? 'soon' : null);
                if (! $kind) {
                    continue;
                }
                $key = "fee-reminder:{$invoice->id}:{$line['number']}:{$kind}";
                if (Cache::has($key)) {
                    continue;
                }
                $amount = number_format($left);
                app(Notifier::class)->send($user, new Notice(
                    $kind === 'overdue' ? 'قسط متأخر' : 'قسط يستحق قريباً',
                    "القسط {$line['number']}: {$amount} دينار — تاريخ الاستحقاق {$line['due_on']}.",
                    route('portal.home', ['tab' => 'fees']), $kind === 'overdue' ? 'danger' : 'warning'));
                Cache::put($key, true, now()->addDays($kind === 'overdue' ? 30 : 7));
                $sent++;
            }
        }
    });
    $this->info("Sent {$sent} reminders.");
})->purpose('Remind students of due and overdue installments');

Schedule::command('sis:fee-reminders')->dailyAt('09:00')->timezone('Asia/Baghdad');

// Every 5 minutes: ask the gateways about online payments still open (a missed return or server call must not
// lose a payment), and close the ones whose time ran out.
Artisan::command('sis:payments-reconcile', function () {
    $service = app(OnlinePaymentService::class);
    $n = 0;
    OnlinePayment::whereIn('status', ['pending', 'expired', 'cancelled'])->where('created_at', '>=', now()->subDay())
        ->where(fn ($q) => $q->whereNull('checked_at')->orWhere('checked_at', '<', now()->subMinutes(2)))
        ->orderBy('id')->limit(200)->get()
        ->each(function ($p) use ($service, &$n) {
            $service->refresh($p);
            $n++;
        });
    $this->info("Checked {$n} online payments.");
})->purpose('Confirm or expire open online payments with their gateways');

Schedule::command('sis:payments-reconcile')->everyFiveMinutes()->withoutOverlapping();

// Offline server: a daily backup of the database and files (does nothing unless BACKUP_DAILY is on).
Schedule::command('backup:run')->hourly()->withoutOverlapping();
