<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for the 2FA challenge page shown after login credentials are verified.
 */
class TwoFactorChallengeController extends Controller
{
    public function __construct(
        private TwoFactorService $twoFactorService
    ) {}

    /**
     * Show the 2FA challenge form.
     */
    public function create(Request $request): Response|RedirectResponse
    {
        if (!$request->session()->has('two_factor_user_id')) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    /**
     * Verify the 2FA code or recovery code and complete login.
     */
    public function store(Request $request): RedirectResponse
    {
        $userId = $request->session()->get('two_factor_user_id');
        $remember = $request->session()->get('two_factor_remember', false);

        if (!$userId) {
            return redirect()->route('login');
        }

        $user = User::findOrFail($userId);

        // Try TOTP code first
        if ($request->filled('two_factor_code')) {
            $request->validate([
                'two_factor_code' => ['required', 'string', 'size:6'],
            ]);

            if (!$this->twoFactorService->verifyCode($user, $request->two_factor_code)) {
                throw ValidationException::withMessages([
                    'two_factor_code' => 'El codigo de verificacion es incorrecto.',
                ]);
            }
        } elseif ($request->filled('recovery_code')) {
            $request->validate([
                'recovery_code' => ['required', 'string'],
            ]);

            if (!$this->twoFactorService->useRecoveryCode($user, $request->recovery_code)) {
                throw ValidationException::withMessages([
                    'recovery_code' => 'El codigo de recuperacion es invalido.',
                ]);
            }
        } else {
            throw ValidationException::withMessages([
                'two_factor_code' => 'Ingresa tu codigo de verificacion.',
            ]);
        }

        // Clean up session and complete login
        $request->session()->forget(['two_factor_user_id', 'two_factor_remember']);

        Auth::login($user, $remember);

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
