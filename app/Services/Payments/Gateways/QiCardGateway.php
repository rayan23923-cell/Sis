<?php

namespace App\Services\Payments\Gateways;

use App\Models\OnlinePayment;
use App\Services\Payments\Started;
use App\Services\Payments\Status;
use Illuminate\Http\Request;

/**
 * Qi Card 3DS payment API: basic auth plus the terminal id. The payer is sent to formUrl, comes back to
 * finishPaymentUrl, and the gateway calls notificationUrl.
 */
class QiCardGateway extends HttpGateway
{
    public function key(): string
    {
        return 'qicard';
    }

    protected function required(): array
    {
        return ['base_url', 'username', 'password', 'terminal_id'];
    }

    private function client()
    {
        return $this->http()->withBasicAuth((string) $this->cfg('username'), (string) $this->cfg('password'))
            ->withHeaders(['X-Terminal-Id' => (string) $this->cfg('terminal_id')]);
    }

    public function start(OnlinePayment $payment, string $returnUrl, string $notifyUrl): Started
    {
        $r = $this->client()->post($this->base().'/payment', [
            'requestId' => $payment->uuid, 'amount' => $payment->amount, 'currency' => 'IQD', 'locale' => 'en_US',
            'finishPaymentUrl' => $returnUrl, 'notificationUrl' => $notifyUrl,
        ]);
        if (! $r->successful() || ! $r->json('paymentId') || ! $r->json('formUrl')) {
            $this->fail('create', $r);
        }

        return new Started((string) $r->json('paymentId'), (string) $r->json('formUrl'));
    }

    public function status(OnlinePayment $payment): Status
    {
        $r = $this->client()->get($this->base().'/payment/'.rawurlencode((string) $payment->external_id).'/status');
        if (! $r->successful()) {
            $this->fail('status', $r);
        }
        if ($r->json('canceled')) {
            return new Status(Status::FAILED, message: 'CANCELED');
        }
        $amount = $r->json('confirmedAmount') ?? $r->json('amount');

        return match ($r->json('status')) {
            'SUCCESS' => new Status(Status::PAID, $amount !== null ? (int) round((float) $amount) : null, reference: $r->json('details.rrn') ?? $r->json('paymentId')),
            'FAILED', 'AUTHENTICATION_FAILED' => new Status(Status::FAILED, message: (string) $r->json('status')),
            default => new Status(Status::PENDING),
        };
    }

    public function externalIdFrom(Request $request): ?string
    {
        $id = $request->input('paymentId');

        return is_string($id) && $id !== '' ? $id : null;
    }
}
