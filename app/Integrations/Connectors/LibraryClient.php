<?php

namespace App\Integrations\Connectors;

use App\Models\Connector;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Calls the FileNest library's SIS endpoints (/api/sis/v1/...) with the key FileNest issued.
 * Short timeouts: a library that is down must never hold up SIS.
 */
class LibraryClient
{
    public function __construct(private readonly string $baseUrl, private readonly string $token) {}

    public static function for(Connector $c): self
    {
        if (! $c->base_url || ! $c->outgoing_token) {
            throw new ConnectorException('لم يُضبط عنوان المكتبة أو مفتاحها.');
        }

        return new self($c->base_url, $c->outgoing_token);
    }

    /** @return array{system: string, name?: string, version?: string, tools?: array} */
    public function manifest(): array
    {
        $data = $this->call('get', 'manifest');
        if (($data['system'] ?? null) !== 'filenest') {
            throw new ConnectorException('العنوان لا يعود لنظام FileNest.');
        }

        return $data;
    }

    /** @param  array<int, array{card_number: string, name: string, stage: ?string, phone: ?string, email: ?string, active: bool}>  $members */
    public function syncMembers(array $members): array
    {
        return $this->call('post', 'members', ['members' => $members]);
    }

    /** @return array{members: array<string, array{active_loans: int, overdue_loans: int, fines_due: int}>, missing: array<int, string>} */
    public function clearance(array $cards): array
    {
        return $this->call('post', 'clearance', ['cards' => array_values($cards)]) + ['members' => [], 'missing' => []];
    }

    private function call(string $method, string $path, array $body = []): array
    {
        try {
            $res = $this->http()->{$method}($this->baseUrl.'/api/sis/v1/'.$path, $body);
        } catch (ConnectionException) {
            throw new ConnectorException('تعذّر الوصول إلى المكتبة على '.$this->baseUrl.'.');
        }
        if ($res->status() === 401 || $res->status() === 403) {
            throw new ConnectorException('رفضت المكتبة المفتاح. أصدر مفتاحاً جديداً من إعدادات المكتبة وأعد الربط.');
        }
        if ($res->status() === 404) {
            throw new ConnectorException('ربط نظام الطلبة غير مفعّل في المكتبة، أو أن نسختها قديمة.');
        }
        if (! $res->successful() || ! is_array($res->json())) {
            throw new ConnectorException('ردّت المكتبة بخطأ ('.$res->status().').');
        }

        return $res->json();
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->token)->acceptJson()->asJson()->connectTimeout(5)->timeout(20)
            ->withHeaders(['User-Agent' => 'SIS-Connector/1']);
    }
}
