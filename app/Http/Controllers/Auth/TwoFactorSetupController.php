<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for 2FA setup: showing QR code, confirming setup, managing recovery codes.
 *
 * This handles the initial/forced 2FA setup flow (middleware redirect).
 * For managing multiple devices from Settings, see SecurityDeviceController.
 */
class TwoFactorSetupController extends Controller
{
    public function __construct(
        private TwoFactorService $twoFactorService
    ) {}

    /**
     * Show the 2FA setup page with QR code.
     *
     * Creates an unconfirmed device if the user doesn't have one pending.
     */
    public function show(Request $request): Response
    {
        $user = $request->user();

        // Find or create an unconfirmed device for setup
        $pendingDevice = $user->twoFactorDevices()->whereNull('confirmed_at')->first();

        if (!$pendingDevice) {
            $pendingDevice = $this->twoFactorService->createDevice($user, 'Autenticador principal');
        }

        $secret = $this->twoFactorService->getDeviceSecret($pendingDevice);
        $qrCodeUri = $this->twoFactorService->generateQrCodeUri($user, $secret, $pendingDevice->name);

        return Inertia::render('Auth/TwoFactorSetup', [
            'qrCodeUri' => $qrCodeUri,
            'secret' => $secret,
            'isEnabled' => $user->hasTwoFactorEnabled(),
            'requiresTwoFactor' => $user->requiresTwoFactor(),
            'recoveryCodesCount' => $this->twoFactorService->remainingRecoveryCodesCount($user),
            'pendingDeviceId' => $pendingDevice->id,
        ]);
    }

    /**
     * Confirm 2FA setup by verifying a code from the authenticator app.
     */
    public function confirm(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'size:6'],
            'device_id' => ['required', 'integer', 'exists:two_factor_devices,id'],
        ], [
            'code.required' => 'El codigo de verificacion es obligatorio.',
            'code.size' => 'El codigo debe tener 6 digitos.',
        ]);

        $user = $request->user();
        $device = $user->twoFactorDevices()->findOrFail($request->device_id);

        if (!$this->twoFactorService->verifyCodeForDevice($device, $request->code)) {
            return redirect()->back()->withErrors([
                'code' => 'El codigo de verificacion es incorrecto. Asegurate de que la hora de tu dispositivo este sincronizada.',
            ]);
        }

        $recoveryCodes = $this->twoFactorService->confirmDevice($device, $user);

        if ($recoveryCodes) {
            return redirect()->route('two-factor.recovery-codes')
                ->with('recoveryCodes', $recoveryCodes)
                ->with('success', 'Autenticacion de dos pasos activada exitosamente.');
        }

        return redirect()->route('two-factor.recovery-codes')
            ->with('success', 'Dispositivo de autenticacion agregado exitosamente.');
    }

    /**
     * Disable 2FA for the user (only allowed for non-required roles).
     *
     * Removes ALL devices and recovery codes.
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

        $user->twoFactorDevices()->delete();
        $user->update(['two_factor_recovery_codes' => null]);

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
