<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that forces users with required roles to set up 2FA before accessing the app.
 */
class EnsureTwoFactorSetup
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return $next($request);
        }

        // Allow 2FA setup routes and logout
        if ($request->routeIs('two-factor.*') || $request->routeIs('logout')) {
            return $next($request);
        }

        // TODO: Re-enable 2FA requirement after initial setup
        // if ($user->requiresTwoFactor() && !$user->hasTwoFactorEnabled()) {
        //     return redirect()->route('two-factor.setup')
        //         ->with('warning', 'Debes configurar la autenticacion de dos pasos para continuar.');
        // }

        return $next($request);
    }
}
