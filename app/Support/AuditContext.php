<?php

namespace App\Support;

use Closure;

/**
 * Who is acting and why, for the audit trail. Web requests fill it from the request; queue jobs
 * and console commands set it explicitly with AuditContext::as($userId, $reason, fn () => ...).
 */
final class AuditContext
{
    private static ?int $userId = null;

    private static ?string $reason = null;

    public static function as(?int $userId, ?string $reason, Closure $work): mixed
    {
        [$u, $r] = [self::$userId, self::$reason];
        self::$userId = $userId;
        self::$reason = $reason;
        try {
            return $work();
        } finally {
            self::$userId = $u;
            self::$reason = $r;
        }
    }

    public static function userId(): ?int
    {
        return self::$userId ?? auth()->id();
    }

    public static function reason(): ?string
    {
        return self::$reason;
    }

    public static function ip(): ?string
    {
        return app()->runningInConsole() ? null : request()->ip();
    }

    public static function userAgent(): ?string
    {
        return app()->runningInConsole() ? null : substr((string) request()->userAgent(), 0, 250);
    }
}
