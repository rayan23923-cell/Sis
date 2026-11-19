<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Security\LoginLog;
use App\Services\Security\Sessions;
use App\Services\Security\TwoFactor;
use App\Support\AuditContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** The account's security page (two-step sign-in, open sessions, recent sign-ins) and the sign-in log for auditors. */
class SecurityController extends Controller
{
    public function __construct(private readonly TwoFactor $twoFactor) {}

    public function show(Request $request): Response
    {
        $user = $request->user();
        $pending = $user->two_factor_secret && ! $user->hasTwoFactor();

        return Inertia::render('Security/Show', [
            'twoFactor' => [
                'enabled' => $user->hasTwoFactor(),
                'required' => $user->requiresTwoFactor(),
                'pending' => $pending,
                'qr' => $pending ? preg_replace('/^<\?xml[^>]*>\s*/', '', $this->twoFactor->qrSvg($user)) : null,
                'secret' => $pending ? trim(chunk_split((string) $user->two_factor_secret, 4, ' ')) : null,
                'recovery_left' => $user->hasTwoFactor() ? count($user->two_factor_recovery_codes ?? []) : null,
            ],
            'recoveryCodes' => session('recovery_codes'),
            'sessions' => Sessions::of($request)->values(),
            'logins' => DB::table('login_events')->where('user_id', $user->id)->latest('id')->limit(10)->get()
                ->map(fn ($e) => ['event' => $e->event, 'label' => LoginLog::EVENTS[$e->event] ?? $e->event, 'ip' => $e->ip, 'device' => Sessions::device((string) $e->user_agent), 'at' => (string) $e->created_at]),
            'passwordChangedAt' => $user->password_changed_at?->format('Y-m-d'),
            'passwordMin' => config('security.password_min'),
        ]);
    }

    public function begin(Request $request): RedirectResponse
    {
        abort_if($request->user()->hasTwoFactor(), 409);
        $this->twoFactor->begin($request->user());

        return back();
    }

    public function confirm(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']], [], ['code' => 'الرمز']);
        $codes = $this->twoFactor->confirm($request->user(), $data['code']);
        if (! $codes) {
            throw ValidationException::withMessages(['code' => 'الرمز غير صحيح. تأكد من وقت الهاتف وأعد المحاولة.']);
        }

        return back()->with('success', 'فُعّل التحقق بخطوتين.')->with('recovery_codes', $codes);
    }

    public function disable(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']], [], ['password' => 'كلمة المرور']);
        if ($request->user()->requiresTwoFactor()) {
            throw ValidationException::withMessages(['password' => 'التحقق بخطوتين إلزامي لدورك ولا يمكن إيقافه.']);
        }
        $this->twoFactor->disable($request->user());

        return back()->with('success', 'أوقف التحقق بخطوتين.');
    }

    public function recoveryCodes(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']], [], ['password' => 'كلمة المرور']);
        abort_unless($request->user()->hasTwoFactor(), 409);

        return back()->with('success', 'أُنشئت رموز استرداد جديدة وأُلغيت القديمة.')->with('recovery_codes', $this->twoFactor->regenerateRecoveryCodes($request->user()));
    }

    public function endSessions(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']], [], ['password' => 'كلمة المرور']);
        $n = Sessions::endOthers($request);
        $request->user()->forceFill(['remember_token' => Str::random(60)])->saveQuietly();

        return back()->with('success', $n ? "أُنهيت {$n} جلسة على أجهزة أخرى." : 'لا توجد جلسات أخرى.');
    }

    /** The sign-in log of every account, for auditors and the registrar. */
    public function logins(Request $request): Response
    {
        Gate::authorize('view-audit');
        $f = $request->validate(['event' => ['nullable', 'string', 'max:20'], 'q' => ['nullable', 'string', 'max:100']]);
        $rows = DB::table('login_events')->leftJoin('users', 'users.id', '=', 'login_events.user_id')
            ->when($f['event'] ?? null, fn ($q, $e) => $q->where('login_events.event', $e))
            ->when($f['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('users.name', 'like', "%{$s}%")->orWhere('users.email', 'like', "%{$s}%")
                ->orWhere('login_events.login', 'like', "%{$s}%")->orWhere('login_events.ip', 'like', "%{$s}%")))
            ->orderByDesc('login_events.id')
            ->select('login_events.*', 'users.name as user_name', 'users.email as user_email')
            ->paginate(50)->withQueryString()
            ->through(fn ($e) => ['id' => $e->id, 'event' => $e->event, 'label' => LoginLog::EVENTS[$e->event] ?? $e->event,
                'user' => $e->user_name, 'login' => $e->user_email ?? $e->login, 'ip' => $e->ip, 'device' => Sessions::device((string) $e->user_agent), 'at' => (string) $e->created_at]);

        $since = now()->subDay();

        return Inertia::render('Security/Logins', [
            'rows' => $rows, 'filters' => $f ?: new \stdClass, 'events' => LoginLog::EVENTS,
            'stats' => [
                'failed_24h' => DB::table('login_events')->whereIn('event', ['failed', 'two_factor_failed'])->where('created_at', '>=', $since)->count(),
                'lockouts_24h' => DB::table('login_events')->where('event', 'lockout')->where('created_at', '>=', $since)->count(),
                'without_2fa' => User::where('is_active', true)->whereNull('two_factor_confirmed_at')->get()->filter->requiresTwoFactor()->count(),
            ],
        ]);
    }

    /** A lost phone: an administrator clears the account's two-step sign-in so it can be set up again. */
    public function reset(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manage-users');
        AuditContext::as($request->user()->id, 'إعادة ضبط التحقق بخطوتين لحساب '.$user->email, fn () => $this->twoFactor->disable($user));

        return back()->with('success', 'أُعيد ضبط التحقق بخطوتين للحساب. سيُطلب منه الإعداد عند الدخول.');
    }
}
