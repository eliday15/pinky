<?php

namespace App\Http\Controllers;

use App\Models\TwoFactorDevice;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Controller for managing 2FA devices from the Settings > Seguridad tab.
 *
 * Handles adding, confirming, and removing individual authenticator devices.
 */
class SecurityDeviceController extends Controller
{
    public function __construct(
        private TwoFactorService $twoFactorService
    ) {}

    /**
     * Create a new unconfirmed 2FA device and return QR data.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ], [
            'name.required' => 'El nombre del dispositivo es obligatorio.',
            'name.max' => 'El nombre no puede exceder 100 caracteres.',
        ]);

        $user = Auth::user();

        // Enforce maximum device limit
        $confirmedCount = $user->twoFactorDevices()->whereNotNull('confirmed_at')->count();
        if ($confirmedCount >= 5) {
            return redirect()->back()->with('error', 'Has alcanzado el limite maximo de autenticadores (5).');
        }

        // Remove any existing unconfirmed devices for this user (cleanup)
        $user->twoFactorDevices()->whereNull('confirmed_at')->delete();

        $device = $this->twoFactorService->createDevice($user, $request->name);
        $secret = $this->twoFactorService->getDeviceSecret($device);
        $qrCodeUri = $this->twoFactorService->generateQrCodeUri($user, $secret, $device->name);

        return redirect()->back()->with([
            'pendingDevice' => [
                'id' => $device->id,
                'name' => $device->name,
                'secret' => $secret,
                'qrCodeUri' => $qrCodeUri,
            ],
        ]);
    }

    /**
     * Confirm a pending device by verifying its TOTP code.
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

        $user = Auth::user();
        $device = $user->twoFactorDevices()->findOrFail($request->device_id);

        if ($device->isConfirmed()) {
            return redirect()->back()->with('error', 'Este dispositivo ya esta confirmado.');
        }

        if (!$this->twoFactorService->verifyCodeForDevice($device, $request->code)) {
            return redirect()->back()->withErrors([
                'code' => 'El codigo de verificacion es incorrecto. Asegurate de que la hora de tu dispositivo este sincronizada.',
            ])->with([
                'pendingDevice' => [
                    'id' => $device->id,
                    'name' => $device->name,
                    'secret' => $this->twoFactorService->getDeviceSecret($device),
                    'qrCodeUri' => $this->twoFactorService->generateQrCodeUri(
                        $user,
                        $this->twoFactorService->getDeviceSecret($device),
                        $device->name
                    ),
                ],
            ]);
        }

        $recoveryCodes = $this->twoFactorService->confirmDevice($device, $user);

        $flash = ['success' => 'Dispositivo de autenticacion agregado exitosamente.'];
        if ($recoveryCodes) {
            $flash['recoveryCodes'] = $recoveryCodes;
        }

        return redirect()->back()->with($flash);
    }

    /**
     * Remove a single confirmed 2FA device.
     */
    public function destroy(Request $request, TwoFactorDevice $device): RedirectResponse
    {
        $user = Auth::user();

        // Scope device to current user before any other logic
        $device = $user->twoFactorDevices()->findOrFail($device->id);

        $request->validate([
            'password' => ['required', 'current_password'],
        ], [
            'password.required' => 'La contrasena es obligatoria.',
            'password.current_password' => 'La contrasena es incorrecta.',
        ]);

        // Prevent removing the last device if role requires 2FA
        if ($user->requiresTwoFactor()) {
            $confirmedCount = $user->twoFactorDevices()->whereNotNull('confirmed_at')->count();
            if ($confirmedCount <= 1 && $device->isConfirmed()) {
                return redirect()->back()->with('error', 'No puedes eliminar tu ultimo autenticador. Tu rol requiere autenticacion de dos pasos.');
            }
        }

        $device->delete();

        return redirect()->back()->with('success', 'Dispositivo de autenticacion eliminado.');
    }

    /**
     * Regenerate recovery codes and redirect back to settings.
     */
    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        $user = Auth::user();

        if (!$user->hasTwoFactorEnabled()) {
            return redirect()->back()->with('error', 'No tienes autenticacion de dos pasos activa.');
        }

        $recoveryCodes = $this->twoFactorService->generateRecoveryCodes();
        $this->twoFactorService->storeRecoveryCodes($user, $recoveryCodes);

        return redirect()->back()->with([
            'recoveryCodes' => $recoveryCodes,
            'success' => 'Codigos de recuperacion regenerados.',
        ]);
    }
}
