<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** An account issued with a temporary password must set its own before anything else. */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password && ! $request->routeIs('password.first', 'password.update', 'logout', 'locale')) {
            return redirect()->route('password.first');
        }

        return $next($request);
    }
}
