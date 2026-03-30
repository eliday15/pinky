<?php

namespace App\Services;

use App\Models\TwoFactorDevice;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Service for TOTP two-factor authentication logic.
 *
 * Handles secret generation, QR code URI creation, code verification,
 * device management, and recovery code management.
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
     * @param string $deviceName The device name to include in the label
     * @return string The otpauth:// URI
     */
    public function generateQrCodeUri(User $user, string $secret, string $deviceName = ''): string
    {
        $label = $deviceName ? "{$user->email} ({$deviceName})" : $user->email;

        return $this->google2fa->getQRCodeUrl(
            'Pinky',
            $label,
            $secret
        );
    }

    /**
     * Create a new unconfirmed 2FA device for the user.
     *
     * @param User $user The user to create the device for
     * @param string $name The device label
     * @return TwoFactorDevice The newly created device
     */
    public function createDevice(User $user, string $name): TwoFactorDevice
    {
        $secret = $this->generateSecretKey();

        return $user->twoFactorDevices()->create([
            'name' => $name,
            'secret' => Crypt::encryptString($secret),
        ]);
    }

    /**
     * Get the decrypted secret for a device.
     *
     * @param TwoFactorDevice $device The device
     * @return string The raw secret
     */
    public function getDeviceSecret(TwoFactorDevice $device): string
    {
        return Crypt::decryptString($device->secret);
    }

    /**
     * Verify a TOTP code against a specific device's secret.
     *
     * @param TwoFactorDevice $device The device to verify against
     * @param string $code The 6-digit TOTP code
     * @return bool Whether the code is valid
     */
    public function verifyCodeForDevice(TwoFactorDevice $device, string $code): bool
    {
        $secret = Crypt::decryptString($device->secret);

        return (bool) $this->google2fa->verifyKey($secret, $code, 1);
    }

    /**
     * Confirm a device after successful code verification.
     *
     * @param TwoFactorDevice $device The device to confirm
     * @param User $user The device owner
     * @return array|null Recovery codes if this is the user's first device, null otherwise
     */
    public function confirmDevice(TwoFactorDevice $device, User $user): ?array
    {
        $device->update(['confirmed_at' => now()]);

        // Generate recovery codes only if this is the user's first confirmed device
        $confirmedCount = $user->twoFactorDevices()->whereNotNull('confirmed_at')->count();
        if ($confirmedCount === 1) {
            $recoveryCodes = $this->generateRecoveryCodes();
            $this->storeRecoveryCodes($user, $recoveryCodes);

            return $recoveryCodes;
        }

        return null;
    }

    /**
     * Verify a TOTP code against all confirmed devices for a user.
     *
     * Iterates through each confirmed device and returns true if any matches.
     * Updates last_used_at on the matching device.
     *
     * @param User $user The user to verify the code for
     * @param string $code The 6-digit TOTP code
     * @return bool Whether the code is valid for any device
     */
    public function verifyCode(User $user, string $code): bool
    {
        $devices = $user->twoFactorDevices()->whereNotNull('confirmed_at')->get();

        foreach ($devices as $device) {
            if ($this->verifyCodeForDevice($device, $code)) {
                $device->update(['last_used_at' => now()]);

                return true;
            }
        }

        return false;
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
