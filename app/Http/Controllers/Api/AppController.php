<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PortalController;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Student;
use App\Models\User;
use App\Services\Security\LoginLog;
use App\Services\Security\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The apps' API (/api/v1): sign-in with a token, the student's portal data and
 * notices, and a one-time link that opens the full site inside the app already
 * signed in (staff pages, PDFs, payments).
 */
class AppController extends Controller
{
    public function __construct(private readonly LoginLog $log) {}

    /** The same login as the site (university number or email); with two-step sign-in it returns a challenge. */
    public function login(LoginRequest $request): JsonResponse
    {
        $request->validate(['device_name' => ['required', 'string', 'max:100']]);
        $request->authenticate();
        $user = Auth::guard('web')->user();
        Auth::guard('web')->logout();
        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => 'هذا الحساب موقوف.']);
        }
        if ($user->hasTwoFactor()) {
            return response()->json([
                'two_factor' => true,
                'challenge' => Crypt::encryptString(json_encode(['id' => $user->id, 'at' => now()->timestamp])),
            ]);
        }

        return $this->issue($request, $user);
    }

    public function twoFactor(Request $request, TwoFactor $twoFactor): JsonResponse
    {
        $data = $request->validate(['challenge' => ['required', 'string'], 'code' => ['required', 'string', 'max:20'], 'device_name' => ['required', 'string', 'max:100']]);
        try {
            $c = json_decode(Crypt::decryptString($data['challenge']), true);
        } catch (\Throwable) {
            $c = null;
        }
        $user = is_array($c) && now()->timestamp - (int) ($c['at'] ?? 0) <= 600 ? User::where('is_active', true)->find($c['id'] ?? 0) : null;
        if (! $user) {
            throw ValidationException::withMessages(['code' => 'انتهت مهلة الدخول، أدخل كلمة المرور مرة أخرى.']);
        }
        $key = 'two-factor:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => 'محاولات كثيرة. حاول بعد '.RateLimiter::availableIn($key).' ثانية.']);
        }
        if (! $twoFactor->verify($user, $data['code'])) {
            RateLimiter::hit($key, 300);
            $this->log->record($request, 'two_factor_failed', $user);
            throw ValidationException::withMessages(['code' => 'الرمز غير صحيح.']);
        }
        RateLimiter::clear($key);

        return $this->issue($request, $user);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->log->record($request, 'logout', $request->user());
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->account($request->user()));
    }

    /** The student's portal: record, results, current courses, fees, timetable, requests. */
    public function student(Request $request): JsonResponse
    {
        abort_if($request->user()->must_change_password, 403, 'غيّر كلمة المرور أولاً.');
        $student = Student::where('user_id', $request->user()->id)->firstOrFail();

        return response()->json(PortalController::payload($student));
    }

    public function notifications(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'unread' => $user->unreadNotifications()->count(),
            'items' => $user->notifications()->latest()->limit(50)->get()->map(fn ($n) => [
                'id' => $n->id, 'title' => $n->data['title'] ?? '', 'body' => $n->data['body'] ?? '', 'url' => $n->data['url'] ?? null,
                'kind' => $n->data['kind'] ?? 'info', 'read' => $n->read_at !== null, 'at' => $n->created_at->timezone('Asia/Baghdad')->format('Y-m-d H:i'),
            ]),
        ]);
    }

    public function readNotifications(Request $request): JsonResponse
    {
        $request->validate(['id' => ['nullable', 'string', 'max:64']]);
        $q = $request->user()->unreadNotifications();
        $request->filled('id') ? $q->whereKey($request->input('id'))->update(['read_at' => now()]) : $q->update(['read_at' => now()]);

        return response()->json(['unread' => $request->user()->unreadNotifications()->count()]);
    }

    /** A link valid once, for one minute, that signs the app's browser into the site and opens `next`. */
    public function webLink(Request $request): JsonResponse
    {
        $next = (string) ($request->validate(['next' => ['nullable', 'string', 'max:500']])['next'] ?? '');
        $token = Str::random(48);
        Cache::put('app-link:'.hash('sha256', $token), ['user' => $request->user()->id, 'next' => $this->safePath($next)], 60);

        return response()->json(['url' => url('/app/enter/'.$token), 'expires_in' => 60]);
    }

    /** Opens the one-time link (web route). */
    public function enter(Request $request, string $token): RedirectResponse
    {
        $link = Cache::pull('app-link:'.hash('sha256', $token));
        $user = $link ? User::where('is_active', true)->find($link['user']) : null;
        if (! $user) {
            return redirect()->route('login');
        }
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $this->log->record($request, 'success', $user);

        return redirect($link['next'] ?: (Student::where('user_id', $user->id)->exists() ? '/portal' : '/dashboard'));
    }

    private function issue(Request $request, User $user): JsonResponse
    {
        $this->log->record($request, 'success', $user);
        $token = $user->createToken(mb_substr((string) $request->input('device_name'), 0, 100))->plainTextToken;

        return response()->json(['token' => $token, 'user' => $this->account($user)]);
    }

    private function account(User $user): array
    {
        $student = Student::where('user_id', $user->id)->first(['id', 'name_ar', 'university_number']);

        return [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'locale' => $user->locale,
            'is_student' => (bool) $student, 'student_number' => $student?->university_number,
            'roles' => $user->roles(), 'must_change_password' => (bool) $user->must_change_password,
            'needs_two_factor_setup' => ! $user->hasTwoFactor() && $user->requiresTwoFactor(),
            'unread' => $user->unreadNotifications()->count(),
            'university' => config('sis.university_name'),
        ];
    }

    /** Only paths inside this site. */
    private function safePath(string $next): string
    {
        return preg_match('#^/(?!/)[^\s\\\\]*$#', $next) ? $next : '';
    }
}
