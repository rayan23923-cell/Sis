<?php

namespace App\Services\Payments\Gateways;

use App\Models\OnlinePayment;
use App\Services\Payments\Started;
use App\Services\Payments\Status;
use Illuminate\Http\Request;

/**
 * First Iraqi Bank online-shop API. The payer scans a QR code or opens a link in the FIB app; there is no
 * redirect. FIB calls statusCallbackUrl with {id, status} when the payment changes.
 */
class FibGateway extends HttpGateway
{
    public function key(): string
    {
        return 'fib';
    }

    protected function required(): array
    {
        return ['base_url', 'client_id', 'client_secret'];
    }

    private function token(): string
    {
        return $this->oauthToken($this->base().'/auth/realms/fib-online-shop/protocol/openid-connect/token');
    }

    public function start(OnlinePayment $payment, string $returnUrl, string $notifyUrl): Started
    {
        $r = $this->http()->withToken($this->token())->post($this->base().'/protected/v1/payments', [
            'monetaryValue' => ['amount' => (string) $payment->amount, 'currency' => 'IQD'],
            'statusCallbackUrl' => $notifyUrl,
            'redirectUri' => $returnUrl,
            'description' => mb_substr('رسوم دراسية '.$payment->uuid, 0, 50),
        ]);
        if (! $r->successful() || ! $r->json('paymentId')) {
            $this->fail('create', $r);
        }

        return new Started((string) $r->json('paymentId'), null, [
            'qr' => $r->json('qrCode'), 'code' => $r->json('readableCode'), 'app_link' => $r->json('personalAppLink'), 'valid_until' => $r->json('validUntil'),
        ]);
    }

    public function status(OnlinePayment $payment): Status
    {
        $r = $this->http()->withToken($this->token())->get($this->base().'/protected/v1/payments/'.rawurlencode((string) $payment->external_id).'/status');
        if (! $r->successful()) {
            $this->fail('status', $r);
        }
        $amount = $r->json('amount.amount');

        return match ($r->json('status')) {
            'PAID' => new Status(Status::PAID, $amount !== null ? (int) round((float) $amount) : null, reference: $r->json('paymentId')),
            'DECLINED' => new Status(Status::FAILED, message: (string) ($r->json('decliningReason') ?: 'DECLINED')),
            default => new Status(Status::PENDING),
        };
    }

    public function externalIdFrom(Request $request): ?string
    {
        $id = $request->input('id');

        return is_string($id) && $id !== '' ? $id : null;
    }
}
