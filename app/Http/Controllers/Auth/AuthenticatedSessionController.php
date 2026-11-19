<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\Security\LoginLog;
use App\Services\Security\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function __construct(private readonly LoginLog $log) {}

    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request. With two-step sign-in on, the password only opens the code step.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();
        $user = $request->user();

        if ($user->hasTwoFactor()) {
            Auth::guard('web')->logout();
            $request->session()->regenerate();
            $request->session()->put(['login.id' => $user->id, 'login.remember' => $request->boolean('remember'), 'login.at' => now()->timestamp]);

            return redirect()->route('two-factor.challenge');
        }

        return $this->signedIn($request, $user);
    }

    public function challenge(Request $request): Response|RedirectResponse
    {
        if (! $this->pending($request)) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    public function verify(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        $user = $this->pending($request);
        if (! $user) {
            return redirect()->route('login');
        }
        $data = $request->validate(['code' => ['required', 'string', 'max:20']], [], ['code' => 'الرمز']);
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
        $remember = (bool) $request->session()->pull('login.remember', false);
        $request->session()->forget(['login.id', 'login.at']);
        Auth::login($user, $remember);

        return $this->signedIn($request, $user);
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        if ($request->user()) {
            $this->log->record($request, 'logout', $request->user());
        }
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }

    private function signedIn(Request $request, User $user): RedirectResponse
    {
        $request->session()->regenerate();
        if (in_array($request->session()->get('locale'), SetLocale::LOCALES, true)) {
            $user->update(['locale' => $request->session()->get('locale')]); // the language chosen on the sign-in page
        }
        $this->log->record($request, 'success', $user);

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /** The account waiting for its code: valid for 10 minutes after the password step. */
    private function pending(Request $request): ?User
    {
        $id = $request->session()->get('login.id');
        if (! $id || now()->timestamp - (int) $request->session()->get('login.at', 0) > 600) {
            return null;
        }

        return User::where('is_active', true)->find($id);
    }
}
