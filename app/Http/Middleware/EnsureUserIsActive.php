<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** A deactivated account is signed out on its next request. */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->is_active === false) {
            if (! $request->hasSession()) {   // an app's token
                $request->user()->currentAccessToken()?->delete();

                return response()->json(['message' => 'هذا الحساب معطل. راجع مدير النظام.'], 401);
            }
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            return redirect()->route('login')->with('status', 'هذا الحساب معطل. راجع مدير النظام.');
        }

        return $next($request);
    }
}
