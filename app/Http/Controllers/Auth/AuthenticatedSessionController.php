<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
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
     * Handle an incoming authentication request.
     *
     * If the user has 2FA enabled, credentials are verified but the user is
     * logged out immediately. Their ID is stored in session and they are
     * redirected to the 2FA challenge page.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        try {
            $request->authenticate();
        } catch (ValidationException $e) {
            AuditLog::record(
                module: AuditLog::MODULE_AUTH,
                action: AuditLog::ACTION_LOGIN_FAILED,
                description: "Intento fallido de inicio de sesion para {$request->string('email')}",
                metadata: ['email' => $request->string('email')->toString()],
            );
            throw $e;
        }

        $user = Auth::user();

        // TODO: Re-enable 2FA challenge after initial setup
        // if ($user->hasTwoFactorEnabled()) {
        //     Auth::guard('web')->logout();
        //
        //     $request->session()->put('two_factor_user_id', $user->id);
        //     $request->session()->put('two_factor_remember', $request->boolean('remember'));
        //
        //     return redirect()->route('two-factor.challenge');
        // }

        $request->session()->regenerate();

        AuditLog::record(
            module: AuditLog::MODULE_AUTH,
            action: AuditLog::ACTION_LOGIN,
            model: $user,
            description: 'Inicio sesion',
        );

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        // Capture the user before the session is destroyed
        $user = Auth::user();

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        if ($user) {
            AuditLog::record(
                module: AuditLog::MODULE_AUTH,
                action: AuditLog::ACTION_LOGOUT,
                model: $user,
                description: 'Cerro sesion',
            );
        }

        return redirect('/');
    }
}
