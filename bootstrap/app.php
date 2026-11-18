<?php

use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleInertiaRequests;
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
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SetLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->trustProxies(at: '*');
        $middleware->alias(['active' => EnsureUserIsActive::class, 'staff' => EnsureStaff::class, 'password.changed' => EnsurePasswordChanged::class]);
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
