<?php

namespace App\Services\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** The account's open sessions (database session driver). */
class Sessions
{
    /** @return Collection<int, array{id: string, ip: ?string, agent: string, last_active: string, current: bool}> */
    public static function of(Request $request): Collection
    {
        if (config('session.driver') !== 'database') {
            return collect();
        }

        return DB::table(config('session.table', 'sessions'))->where('user_id', $request->user()->id)
            ->where('last_activity', '>=', now()->subMinutes((int) config('session.lifetime', 120))->timestamp)   // expired ones are only waiting for clean-up
            ->orderByDesc('last_activity')->limit(10)->get()
            ->map(fn ($s) => [
                'id' => hash('sha256', $s->id), 'ip' => $s->ip_address, 'agent' => self::device((string) $s->user_agent),
                'last_active' => date('Y-m-d H:i', (int) $s->last_activity), 'current' => $s->id === $request->session()->getId(),
            ]);
    }

    public static function endOthers(Request $request): int
    {
        if (config('session.driver') !== 'database' || ! $request->user()) {
            return 0;
        }

        return DB::table(config('session.table', 'sessions'))->where('user_id', $request->user()->id)->where('id', '!=', $request->session()->getId())->delete();
    }

    public static function device(string $agent): string
    {
        $os = match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => '',
        };
        $app = match (true) {
            str_contains($agent, 'SisApp') => 'تطبيق النظام',
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'Chrome') => 'Chrome',
            str_contains($agent, 'Firefox') => 'Firefox',
            str_contains($agent, 'Safari') => 'Safari',
            default => '',
        };

        return trim($app.' · '.$os, ' ·') ?: 'جهاز غير معروف';
    }
}
