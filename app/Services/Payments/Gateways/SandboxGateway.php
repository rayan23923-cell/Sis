<?php

namespace App\Services\Payments\Gateways;

use App\Models\OnlinePayment;
use App\Services\Payments\Gateway;
use App\Services\Payments\Started;
use App\Services\Payments\Status;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * A pretend gateway inside the system, for demonstrations and tests: its page asks the payer to approve or
 * decline, and the answer is kept in the cache as if the gateway held it. Off unless PAYMENTS_SANDBOX is set
 * (always on in tests).
 */
class SandboxGateway implements Gateway
{
    public function key(): string
    {
        return 'sandbox';
    }

    public function label(): string
    {
        return (string) config('payments.gateways.sandbox.label');
    }

    public function enabled(): bool
    {
        return (bool) config('payments.gateways.sandbox.enabled') || app()->environment('testing');
    }

    public function start(OnlinePayment $payment, string $returnUrl, string $notifyUrl): Started
    {
        $id = 'SBX-'.strtoupper(substr(str_replace('-', '', $payment->uuid), 0, 12));

        return new Started($id, route('payments.sandbox', $payment));
    }

    public function status(OnlinePayment $payment): Status
    {
        return match (Cache::get("payments:sandbox:{$payment->external_id}")) {
            'paid' => new Status(Status::PAID, $payment->amount, reference: $payment->external_id),
            'declined' => new Status(Status::FAILED, message: 'DECLINED'),
            default => new Status(Status::PENDING),
        };
    }

    public function decide(OnlinePayment $payment, bool $approve): void
    {
        Cache::put("payments:sandbox:{$payment->external_id}", $approve ? 'paid' : 'declined', now()->addDay());
    }

    public function externalIdFrom(Request $request): ?string
    {
        return null;
    }
}
