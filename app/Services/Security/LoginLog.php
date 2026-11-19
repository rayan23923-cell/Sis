<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Every sign-in attempt, kept for the security page and the auditors. */
class LoginLog
{
    public const EVENTS = [
        'success' => 'دخول ناجح',
        'failed' => 'كلمة مرور خاطئة',
        'lockout' => 'إيقاف مؤقت بعد محاولات فاشلة',
        'two_factor_failed' => 'رمز تحقق خاطئ',
        'logout' => 'خروج',
    ];

    public function record(Request $request, string $event, ?User $user = null, ?string $login = null): void
    {
        DB::table('login_events')->insert([
            'user_id' => $user?->id, 'login' => $login !== null ? mb_substr($login, 0, 150) : null, 'event' => $event,
            'ip' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 255), 'created_at' => now(),
        ]);
        if ($event === 'success' && $user) {
            $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->saveQuietly();
        }
    }
}
