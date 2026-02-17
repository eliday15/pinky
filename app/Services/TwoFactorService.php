<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Service for TOTP two-factor authentication logic.
 *
 * Handles secret generation, QR code URI creation, code verification,
 * and recovery code management.
 */
class TwoFactorService
{
    private Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA();
    }

    /**
     * Generate a new TOTP secret key.
     *
     * @return string The raw secret key (not encrypted)
     */
    public function generateSecretKey(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    /**
     * Generate the otpauth:// URI for QR code rendering.
     *
     * @param User $user The user to generate the QR URI for
     * @param string $secret The raw (unencrypted) secret key
     * @return string The otpauth:// URI
     */
    public function generateQrCodeUri(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(
            'Pinky',
            $user->email,
            $secret
        );
    }

    /**
     * Verify a TOTP code against the user's stored secret.
     *
     * @param User $user The user to verify the code for
     * @param string $code The 6-digit TOTP code
     * @return bool Whether the code is valid
     */
    public function verifyCode(User $user, string $code): bool
    {
        if (!$user->two_factor_secret) {
            return false;
        }

        $secret = Crypt::decryptString($user->two_factor_secret);

        return (bool) $this->google2fa->verifyKey($secret, $code, 1);
    }

    /**
     * Generate 8 recovery codes in xxxx-xxxx format.
     *
     * @return array Array of plaintext recovery codes
     */
    public function generateRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = Str::random(4) . '-' . Str::random(4);
        }

        return $codes;
    }

    /**
     * Hash and store recovery codes on the user.
     *
     * @param User $user The user to store codes for
     * @param array $codes Array of plaintext recovery codes
     */
    public function storeRecoveryCodes(User $user, array $codes): void
    {
        $hashed = array_map(fn (string $code) => Hash::make($code), $codes);

        $user->two_factor_recovery_codes = Crypt::encryptString(json_encode($hashed));
        $user->save();
    }

    /**
     * Verify a recovery code and consume it if valid.
     *
     * @param User $user The user to verify the code for
     * @param string $code The recovery code to verify
     * @return bool Whether the code was valid and consumed
     */
    public function useRecoveryCode(User $user, string $code): bool
    {
        if (!$user->two_factor_recovery_codes) {
            return false;
        }

        $hashedCodes = json_decode(
            Crypt::decryptString($user->two_factor_recovery_codes),
            true
        );

        foreach ($hashedCodes as $index => $hashedCode) {
            if (Hash::check($code, $hashedCode)) {
                unset($hashedCodes[$index]);
                $user->two_factor_recovery_codes = Crypt::encryptString(
                    json_encode(array_values($hashedCodes))
                );
                $user->save();

                return true;
            }
        }

        return false;
    }

    /**
     * Get the count of remaining recovery codes.
     *
     * @param User $user The user to check
     * @return int Number of remaining recovery codes
     */
    public function remainingRecoveryCodesCount(User $user): int
    {
        if (!$user->two_factor_recovery_codes) {
            return 0;
        }

        $hashedCodes = json_decode(
            Crypt::decryptString($user->two_factor_recovery_codes),
            true
        );

        return count($hashedCodes);
    }
}
