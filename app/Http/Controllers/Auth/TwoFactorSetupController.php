<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for 2FA setup: showing QR code, confirming setup, managing recovery codes.
 */
class TwoFactorSetupController extends Controller
{
    public function __construct(
        private TwoFactorService $twoFactorService
    ) {}

    /**
     * Show the 2FA setup page with QR code.
     */
    public function show(Request $request): Response
    {
        $user = $request->user();

        // Generate a new secret if user doesn't have one yet
        if (!$user->two_factor_secret) {
            $secret = $this->twoFactorService->generateSecretKey();
            $user->two_factor_secret = Crypt::encryptString($secret);
            $user->save();
        } else {
            $secret = Crypt::decryptString($user->two_factor_secret);
        }

        $qrCodeUri = $this->twoFactorService->generateQrCodeUri($user, $secret);

        return Inertia::render('Auth/TwoFactorSetup', [
            'qrCodeUri' => $qrCodeUri,
            'secret' => $secret,
            'isEnabled' => $user->hasTwoFactorEnabled(),
            'requiresTwoFactor' => $user->requiresTwoFactor(),
            'recoveryCodesCount' => $this->twoFactorService->remainingRecoveryCodesCount($user),
        ]);
    }

    /**
     * Confirm 2FA setup by verifying a code from the authenticator app.
     */
    public function confirm(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ], [
            'code.required' => 'El codigo de verificacion es obligatorio.',
            'code.size' => 'El codigo debe tener 6 digitos.',
        ]);

        $user = $request->user();

        if (!$this->twoFactorService->verifyCode($user, $request->code)) {
            return redirect()->back()->withErrors([
                'code' => 'El codigo de verificacion es incorrecto. Asegurate de que la hora de tu dispositivo este sincronizada.',
            ]);
        }

        // Mark as confirmed
        $user->two_factor_confirmed_at = now();
        $user->save();

        // Generate recovery codes
        $recoveryCodes = $this->twoFactorService->generateRecoveryCodes();
        $this->twoFactorService->storeRecoveryCodes($user, $recoveryCodes);

        return redirect()->route('two-factor.recovery-codes')
            ->with('recoveryCodes', $recoveryCodes)
            ->with('success', 'Autenticacion de dos pasos activada exitosamente.');
    }

    /**
     * Disable 2FA for the user (only allowed for non-required roles).
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ], [
            'password.required' => 'La contrasena es obligatoria.',
            'password.current_password' => 'La contrasena es incorrecta.',
        ]);

        $user = $request->user();

        if ($user->requiresTwoFactor()) {
            return redirect()->back()->with('error', 'Tu rol requiere autenticacion de dos pasos. No se puede desactivar.');
        }

        $user->two_factor_secret = null;
        $user->two_factor_recovery_codes = null;
        $user->two_factor_confirmed_at = null;
        $user->save();

        return redirect()->route('profile.edit')
            ->with('success', 'Autenticacion de dos pasos desactivada.');
    }

    /**
     * Show remaining recovery codes.
     */
    public function recoveryCodes(Request $request): Response
    {
        $user = $request->user();

        if (!$user->hasTwoFactorEnabled()) {
            return Inertia::render('Auth/TwoFactorSetup', [
                'qrCodeUri' => '',
                'secret' => '',
                'isEnabled' => false,
                'requiresTwoFactor' => $user->requiresTwoFactor(),
                'recoveryCodesCount' => 0,
            ]);
        }

        return Inertia::render('Auth/TwoFactorSetup', [
            'qrCodeUri' => '',
            'secret' => '',
            'isEnabled' => true,
            'requiresTwoFactor' => $user->requiresTwoFactor(),
            'recoveryCodesCount' => $this->twoFactorService->remainingRecoveryCodesCount($user),
            'recoveryCodes' => session('recoveryCodes', []),
        ]);
    }

    /**
     * Regenerate recovery codes.
     */
    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (!$user->hasTwoFactorEnabled()) {
            return redirect()->route('two-factor.setup');
        }

        $recoveryCodes = $this->twoFactorService->generateRecoveryCodes();
        $this->twoFactorService->storeRecoveryCodes($user, $recoveryCodes);

        return redirect()->route('two-factor.recovery-codes')
            ->with('recoveryCodes', $recoveryCodes)
            ->with('success', 'Codigos de recuperacion regenerados.');
    }
}
