<?php

namespace App\Services\Payments\Gateways;

use App\Models\OnlinePayment;
use App\Services\Payments\Started;
use App\Services\Payments\Status;
use Illuminate\Http\Request;

/**
 * ZainCash payment gateway API v2 (OAuth2). The payer is sent to redirectUrl and comes back with a token
 * signed with the client secret (HS256). The token only tells us the transaction id; its state is read
 * from the inquiry endpoint.
 */
class ZainCashGateway extends HttpGateway
{
    public function key(): string
    {
        return 'zaincash';
    }

    protected function required(): array
    {
        return ['base_url', 'client_id', 'client_secret'];
    }

    private function token(): string
    {
        return $this->oauthToken($this->base().'/oauth2/token', ['scope' => 'payment:read payment:write']);
    }

    public function start(OnlinePayment $payment, string $returnUrl, string $notifyUrl): Started
    {
        $r = $this->http()->withToken($this->token())->post($this->base().'/api/v2/payment-gateway/transaction/init', [
            'language' => 'ar', 'externalReferenceId' => $payment->uuid, 'orderId' => $payment->uuid,
            'amount' => ['value' => (string) $payment->amount, 'currency' => 'IQD'],
            'serviceType' => 'رسوم دراسية',
            'redirectUrls' => ['successUrl' => $returnUrl, 'failureUrl' => $returnUrl],
        ]);
        if (! $r->successful() || ! $r->json('redirectUrl')) {
            $this->fail('create', $r);
        }
        $id = $r->json('transactionDetails.transactionId') ?? $r->json('transactionId') ?? '';

        return new Started((string) $id, (string) $r->json('redirectUrl'));
    }

    public function status(OnlinePayment $payment): Status
    {
        if (blank($payment->external_id)) {
            return new Status(Status::PENDING);
        }
        $r = $this->http()->withToken($this->token())->get($this->base().'/api/v2/payment-gateway/transaction/inquiry/'.rawurlencode((string) $payment->external_id));
        if (! $r->successful()) {
            $this->fail('status', $r);
        }
        $state = strtoupper((string) ($r->json('status') ?? $r->json('currentStatus') ?? $r->json('data.currentStatus') ?? $r->json('transactionDetails.status') ?? ''));
        $amount = $r->json('amount.value') ?? $r->json('data.amount.value') ?? $r->json('transactionDetails.amount.value');

        return match (true) {
            in_array($state, ['SUCCESS', 'COMPLETED'], true) => new Status(Status::PAID, $amount !== null ? (int) round((float) $amount) : null, reference: $payment->external_id),
            in_array($state, ['FAILED', 'EXPIRED', 'CANCELLED', 'CANCELED', 'REJECTED', 'REVERSED'], true) => new Status(Status::FAILED, message: $state),
            default => new Status(Status::PENDING),
        };
    }

    /** The transaction id from the signed return token; a token with a bad signature is ignored. */
    public function externalIdFrom(Request $request): ?string
    {
        $claims = self::decodeHs256((string) $request->query('token', ''), (string) $this->cfg('client_secret'));
        $id = $claims['data']['transactionId'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** @return array<string, mixed>|null the claims of a JWT signed HS256 with $secret, or null */
    public static function decodeHs256(string $jwt, string $secret): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3 || $secret === '') {
            return null;
        }
        $b64 = fn (string $s) => base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        $header = json_decode((string) $b64($parts[0]), true);
        if (($header['alg'] ?? null) !== 'HS256') {
            return null;
        }
        $sig = $b64($parts[2]);
        if ($sig === false || ! hash_equals(hash_hmac('sha256', $parts[0].'.'.$parts[1], $secret, true), $sig)) {
            return null;
        }
        $claims = json_decode((string) $b64($parts[1]), true);
        if (! is_array($claims) || (isset($claims['exp']) && $claims['exp'] < time() - 60)) {
            return null;
        }

        return $claims;
    }
}
