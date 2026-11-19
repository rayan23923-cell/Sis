<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Accounts whose role requires two-step sign-in must set it up before using the system. */
class EnsureTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->hasTwoFactor() && $user->requiresTwoFactor() && ! $request->routeIs('security.*', 'logout', 'locale', 'password.*')) {
            return redirect()->route('security.show');   // the page explains why
        }

        return $next($request);
    }
}
