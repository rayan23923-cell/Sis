<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Staff screens: a student account is sent to its portal; an account with no role sees only the dashboard and its profile. */
class EnsureStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->roles() === []) {
            if ($user->student()->exists()) {
                return redirect()->route('portal.home');
            }
            abort_unless($request->routeIs('dashboard', 'profile.*'), 403);
        }

        return $next($request);
    }
}
