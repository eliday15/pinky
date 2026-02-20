<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that forces users with must_change_password flag to change their password.
 */
class EnsurePasswordChanged
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

        // Allow password change routes, 2FA routes, and logout
        if ($request->routeIs('password.force-change*') || $request->routeIs('two-factor.*') || $request->routeIs('logout')) {
            return $next($request);
        }

        if ($user->must_change_password) {
            return redirect()->route('password.force-change')
                ->with('warning', 'Debes cambiar tu contraseña temporal antes de continuar.');
        }

        return $next($request);
    }
}
