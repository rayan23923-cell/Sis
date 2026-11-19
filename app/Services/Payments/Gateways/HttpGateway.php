<?php

namespace App\Services\Payments\Gateways;

use App\Services\Payments\Gateway;
use App\Services\Payments\GatewayException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Shared plumbing for gateways reached over HTTPS: config, timeouts, OAuth tokens and safe error messages. */
abstract class HttpGateway implements Gateway
{
    /** @return list<string> the config keys that must be set */
    abstract protected function required(): array;

    public function label(): string
    {
        return (string) $this->cfg('label');
    }

    public function enabled(): bool
    {
        foreach ($this->required() as $k) {
            if (blank($this->cfg($k))) {
                return false;
            }
        }

        return true;
    }

    protected function cfg(string $key): mixed
    {
        return config("payments.gateways.{$this->key()}.{$key}");
    }

    protected function base(): string
    {
        return rtrim((string) $this->cfg('base_url'), '/');
    }

    protected function http(): PendingRequest
    {
        return Http::acceptJson()->timeout(20)->connectTimeout(8)->retry(2, 300, throw: false);
    }

    /** A client-credentials token, cached until shortly before it expires. */
    protected function oauthToken(string $url, array $extra = []): string
    {
        $key = "payments:{$this->key()}:token:".md5((string) $this->cfg('client_id'));

        return Cache::remember($key, 240, function () use ($url, $extra) {
            $r = $this->http()->asForm()->post($url, ['grant_type' => 'client_credentials', 'client_id' => $this->cfg('client_id'), 'client_secret' => $this->cfg('client_secret')] + $extra);
            $token = $r->json('access_token');
            if (! $r->successful() || ! is_string($token)) {
                $this->fail('auth', $r);
            }

            return $token;
        });
    }

    protected function forgetToken(): void
    {
        Cache::forget("payments:{$this->key()}:token:".md5((string) $this->cfg('client_id')));
    }

    /** Logs what went wrong (status and body, never credentials) and stops with a message the payer can read. */
    protected function fail(string $step, ?Response $r = null, ?string $detail = null): never
    {
        Log::warning("Payment gateway {$this->key()} {$step} failed", ['status' => $r?->status(), 'body' => mb_substr((string) $r?->body(), 0, 500), 'detail' => $detail]);
        if ($step === 'auth') {
            $this->forgetToken();
        }

        throw new GatewayException("تعذّر الاتصال ببوابة {$this->label()}. حاول بعد قليل أو استخدم بوابة أخرى.");
    }
}
