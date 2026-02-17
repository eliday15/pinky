<?php

namespace App\Http\Traits;

use App\Services\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Trait for controllers that require 2FA verification on sensitive actions.
 *
 * Usage: Add `use VerifiesTwoFactor;` to the controller, then call
 * `$this->verifyTwoFactorCode($request)` at the start of any
 * approve/reject/markPaid action.
 */
trait VerifiesTwoFactor
{
    /**
     * Verify the 2FA code from the request if the user has 2FA enabled.
     *
     * If the user does not have 2FA enabled, this is a no-op.
     *
     * @param Request $request The current request
     * @throws ValidationException If the code is missing or invalid
     */
    protected function verifyTwoFactorCode(Request $request): void
    {
        $user = $request->user();

        if (!$user->hasTwoFactorEnabled()) {
            return;
        }

        $request->validate([
            'two_factor_code' => ['required', 'string', 'size:6'],
        ], [
            'two_factor_code.required' => 'El codigo de verificacion es obligatorio.',
            'two_factor_code.size' => 'El codigo debe tener 6 digitos.',
        ]);

        if (!app(TwoFactorService::class)->verifyCode($user, $request->two_factor_code)) {
            throw ValidationException::withMessages([
                'two_factor_code' => 'El codigo de verificacion es incorrecto.',
            ]);
        }
    }
}
