<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Inertia\Inertia;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'password-changed' => \App\Http\Middleware\EnsurePasswordChanged::class,
            'two-factor-setup' => \App\Http\Middleware\EnsureTwoFactorSetup::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (TokenMismatchException $e, Request $request) {
            // Only regenerate the CSRF token — do NOT invalidate the session.
            // The user is likely still authenticated; only the token is stale.
            $request->session()->regenerateToken();

            if ($request->header('X-Inertia')) {
                // For Inertia XHR requests: force a full page reload via 409 + X-Inertia-Location.
                // This guarantees fresh tokens on the reloaded page.
                return Inertia::location($request->header('referer', url()->current()));
            }

            // For traditional (non-Inertia) form submissions:
            return redirect()->back()
                ->withInput()
                ->with('warning', 'Tu formulario expiró. Tus datos han sido preservados, por favor intenta de nuevo.');
        });
    })->create();
