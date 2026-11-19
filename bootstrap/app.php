<?php

use App\Http\Middleware\EnsureOfflineActivated;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\EnsureTwoFactor;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Services\WorkflowException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Before the login check, so an unactivated offline copy always leads to /activate.
        $middleware->web(prepend: [EnsureOfflineActivated::class]);
        $middleware->api(prepend: [EnsureOfflineActivated::class]);
        $middleware->web(append: [
            SetLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SecurityHeaders::class,
        ]);

        $middleware->trustProxies(at: '*');
        // Gateways call these from their own servers; they carry no session and only prompt a status check.
        $middleware->validateCsrfTokens(except: ['payment-notify/*', 'payments/*/return']);
        $middleware->alias(['active' => EnsureUserIsActive::class, 'staff' => EnsureStaff::class, 'password.changed' => EnsurePasswordChanged::class, 'two-factor' => EnsureTwoFactor::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A business rule stopped the action: go back and show the reason, never a 500 page.
        $exceptions->render(function (WorkflowException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage(), 'problems' => $e->problems], 422);
            }

            return back()->with('error', $e->getMessage())->with('problems', $e->problems ?: null);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
