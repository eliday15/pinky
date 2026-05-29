<?php

namespace Tests\Feature\Admin;

use App\Models\TwoFactorDevice;
use App\Models\User;
use App\Services\TwoFactorService;
use PragmaRX\Google2FA\Google2FA;
use Tests\FeatureTestCase;

/**
 * Feature tests for SecurityDeviceController.
 *
 * These endpoints manage the ACTING user's own 2FA devices. Codes are real
 * TOTP, so valid codes are computed with the same google2fa library. The
 * harness 2FA device uses the well-known secret JBSWY3DPEHPK3PXP, which we
 * reuse to produce valid codes for the current confirmed authenticator.
 */
class SecurityDeviceControllerTest extends FeatureTestCase
{
    private const HARNESS_SECRET = 'JBSWY3DPEHPK3PXP';

    /** Compute a currently-valid TOTP code for a raw secret. */
    private function otpFor(string $secret): string
    {
        return (new Google2FA())->getCurrentOtp($secret);
    }

    /** Decrypt a device's stored secret via the real service. */
    private function deviceSecret(TwoFactorDevice $device): string
    {
        return app(TwoFactorService::class)->getDeviceSecret($device);
    }

    /**
     * Replace the harness's plaintext-secret device with a properly
     * Crypt::encryptString'd confirmed device (as the real app stores them),
     * so verifyCode() can decrypt it. Returns the device's raw TOTP secret.
     *
     * The InteractsWithAuth harness inserts a confirmed device whose `secret`
     * column is the raw string JBSWY3DPEHPK3PXP (not encrypted). The 2FA
     * service decrypts with Crypt::decryptString, which throws on plaintext —
     * so for verifyCode paths we must use a real encrypted device.
     */
    private function realConfirmedDeviceFor(User $user): string
    {
        $service = app(TwoFactorService::class);
        $user->twoFactorDevices()->delete();
        $device = $service->createDevice($user, 'Real Authenticator');
        $device->update(['confirmed_at' => now()]);

        return $service->getDeviceSecret($device);
    }

    // ---------------------------------------------------------------------
    // store
    // ---------------------------------------------------------------------

    public function test_employee_without_existing_2fa_can_store_a_pending_device(): void
    {
        // Employees do not require 2FA and have no confirmed device, so store
        // does NOT demand a code — this is the clean QR-enrollment path.
        $user = $this->actingAsEmployee();
        $this->assertFalse($user->hasTwoFactorEnabled());

        $this->post(route('settings.security.devices.store'), [
            'name' => 'Mi Telefono',
        ])
            ->assertRedirect()
            ->assertSessionHas('pendingDevice');

        $this->assertDatabaseHas('two_factor_devices', [
            'user_id' => $user->id,
            'name' => 'Mi Telefono',
            'confirmed_at' => null,
        ]);
    }

    public function test_store_validation_requires_name(): void
    {
        $this->actingAsEmployee();

        $this->post(route('settings.security.devices.store'), [])
            ->assertSessionHasErrors(['name']);
    }

    public function test_store_validation_rejects_name_over_100_chars(): void
    {
        $this->actingAsEmployee();

        $this->post(route('settings.security.devices.store'), [
            'name' => str_repeat('a', 101),
        ])->assertSessionHasErrors(['name']);
    }

    public function test_store_requires_current_code_when_2fa_already_enabled(): void
    {
        // Admin already has a confirmed device via the harness, so adding a new
        // one requires a valid TOTP from the existing authenticator.
        $this->actingAsAdmin();

        $this->post(route('settings.security.devices.store'), [
            'name' => 'Segundo',
        ])->assertSessionHasErrors(['two_factor_code']);
    }

    public function test_store_rejects_wrong_current_code_when_2fa_enabled(): void
    {
        $admin = $this->actingAsAdmin();
        $this->realConfirmedDeviceFor($admin);

        $this->post(route('settings.security.devices.store'), [
            'name' => 'Segundo',
            'two_factor_code' => '000000',
        ])->assertSessionHasErrors(['two_factor_code']);
    }

    public function test_store_succeeds_with_valid_current_code_when_2fa_enabled(): void
    {
        $admin = $this->actingAsAdmin();
        $secret = $this->realConfirmedDeviceFor($admin);

        $this->post(route('settings.security.devices.store'), [
            'name' => 'Segundo Autenticador',
            'two_factor_code' => $this->otpFor($secret),
        ])
            ->assertRedirect()
            ->assertSessionHas('pendingDevice');

        $this->assertDatabaseHas('two_factor_devices', [
            'user_id' => $admin->id,
            'name' => 'Segundo Autenticador',
            'confirmed_at' => null,
        ]);
    }

    // ---------------------------------------------------------------------
    // confirm
    // ---------------------------------------------------------------------

    public function test_confirm_validation_requires_code_and_device_id(): void
    {
        $this->actingAsEmployee();

        $this->post(route('settings.security.devices.confirm'), [])
            ->assertSessionHasErrors(['code', 'device_id']);
    }

    public function test_confirm_rejects_wrong_code(): void
    {
        $user = $this->actingAsEmployee();
        $device = app(TwoFactorService::class)->createDevice($user, 'Pendiente');

        $this->post(route('settings.security.devices.confirm'), [
            'code' => '000000',
            'device_id' => $device->id,
        ])->assertSessionHasErrors(['code']);

        $this->assertNull($device->fresh()->confirmed_at);
    }

    public function test_confirm_activates_device_with_valid_code(): void
    {
        $user = $this->actingAsEmployee();
        $service = app(TwoFactorService::class);
        $device = $service->createDevice($user, 'Pendiente');
        $secret = $service->getDeviceSecret($device);

        $this->post(route('settings.security.devices.confirm'), [
            'code' => $this->otpFor($secret),
            'device_id' => $device->id,
        ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNotNull($device->fresh()->confirmed_at);
        // First confirmed device also yields recovery codes.
        $this->assertNotNull($user->fresh()->two_factor_recovery_codes);
    }

    public function test_confirm_rejects_already_confirmed_device(): void
    {
        $user = $this->actingAsAdmin();
        // The harness device is already confirmed.
        $confirmed = $user->twoFactorDevices()->whereNotNull('confirmed_at')->first();

        $this->post(route('settings.security.devices.confirm'), [
            'code' => $this->otpFor(self::HARNESS_SECRET),
            'device_id' => $confirmed->id,
        ])->assertSessionHas('error');
    }

    // ---------------------------------------------------------------------
    // destroy
    // ---------------------------------------------------------------------

    public function test_destroy_validation_requires_code(): void
    {
        $user = $this->actingAsAdmin();
        $device = $user->twoFactorDevices()->first();

        $this->delete(route('settings.security.devices.destroy', $device), [])
            ->assertSessionHasErrors(['two_factor_code']);
    }

    public function test_destroy_rejects_wrong_code(): void
    {
        $user = $this->actingAsAdmin();
        $this->realConfirmedDeviceFor($user);
        $device = $user->twoFactorDevices()->first();

        $this->delete(route('settings.security.devices.destroy', $device), [
            'two_factor_code' => '000000',
        ])->assertSessionHasErrors(['two_factor_code']);

        $this->assertDatabaseHas('two_factor_devices', ['id' => $device->id]);
    }

    public function test_destroy_blocks_removing_last_required_device(): void
    {
        // Admin requires 2FA and has exactly one confirmed device — even with a
        // valid code the controller blocks the deletion.
        $user = $this->actingAsAdmin();
        $secret = $this->realConfirmedDeviceFor($user);
        $device = $user->twoFactorDevices()->first();

        $this->delete(route('settings.security.devices.destroy', $device), [
            'two_factor_code' => $this->otpFor($secret),
        ])->assertSessionHasErrors(['two_factor_code']);

        $this->assertDatabaseHas('two_factor_devices', ['id' => $device->id]);
    }

    public function test_destroy_removes_device_when_another_remains(): void
    {
        $user = $this->actingAsAdmin();
        $service = app(TwoFactorService::class);

        // Primary confirmed device with a known, properly-encrypted secret.
        $primarySecret = $this->realConfirmedDeviceFor($user);
        $primary = $user->twoFactorDevices()->first();

        // Add a second confirmed device so removing one is allowed.
        $second = $service->createDevice($user, 'Backup');
        $second->update(['confirmed_at' => now()]);

        $this->delete(route('settings.security.devices.destroy', $second), [
            'two_factor_code' => $this->otpFor($primarySecret),
        ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('two_factor_devices', ['id' => $second->id]);
        $this->assertDatabaseHas('two_factor_devices', ['id' => $primary->id]);
    }

    public function test_destroy_scopes_device_to_acting_user(): void
    {
        $this->actingAsAdmin();
        $otherUser = User::factory()->create();
        $foreignDevice = TwoFactorDevice::create([
            'user_id' => $otherUser->id,
            'name' => 'Ajeno',
            'secret' => 'JBSWY3DPEHPK3PXP',
            'confirmed_at' => now(),
        ]);

        // findOrFail on the acting user's relation -> 404 for a foreign device.
        $this->delete(route('settings.security.devices.destroy', $foreignDevice), [
            'two_factor_code' => $this->otpFor(self::HARNESS_SECRET),
        ])->assertNotFound();

        $this->assertDatabaseHas('two_factor_devices', ['id' => $foreignDevice->id]);
    }

    // ---------------------------------------------------------------------
    // regenerateRecoveryCodes
    // ---------------------------------------------------------------------

    public function test_regenerate_recovery_codes_validation_requires_code(): void
    {
        $this->actingAsAdmin();

        $this->post(route('settings.security.recovery-codes.regenerate'), [])
            ->assertSessionHasErrors(['two_factor_code']);
    }

    public function test_regenerate_recovery_codes_rejects_wrong_code(): void
    {
        $user = $this->actingAsAdmin();
        $this->realConfirmedDeviceFor($user);

        $this->post(route('settings.security.recovery-codes.regenerate'), [
            'two_factor_code' => '000000',
        ])->assertSessionHasErrors(['two_factor_code']);
    }

    public function test_regenerate_recovery_codes_succeeds_with_valid_code(): void
    {
        $user = $this->actingAsAdmin();
        $secret = $this->realConfirmedDeviceFor($user);

        $this->post(route('settings.security.recovery-codes.regenerate'), [
            'two_factor_code' => $this->otpFor($secret),
        ])
            ->assertRedirect()
            ->assertSessionHas('recoveryCodes')
            ->assertSessionHas('success');

        $this->assertNotNull($user->fresh()->two_factor_recovery_codes);
    }

    public function test_regenerate_recovery_codes_blocked_without_active_2fa(): void
    {
        // Employee has no confirmed device -> 2FA not active.
        $this->actingAsEmployee();

        $this->post(route('settings.security.recovery-codes.regenerate'), [
            'two_factor_code' => '123456',
        ])->assertSessionHas('error');
    }

    public function test_guest_redirected_to_login_from_device_store(): void
    {
        $this->post(route('settings.security.devices.store'), ['name' => 'X'])
            ->assertRedirect(route('login'));
    }

    public function test_guest_redirected_to_login_from_device_confirm(): void
    {
        $this->post(route('settings.security.devices.confirm'), [
            'code' => '123456',
            'device_id' => 1,
        ])->assertRedirect(route('login'));
    }

    public function test_guest_redirected_to_login_from_device_destroy(): void
    {
        $user = User::factory()->create();
        $device = TwoFactorDevice::create([
            'user_id' => $user->id,
            'name' => 'X',
            'secret' => self::HARNESS_SECRET,
            'confirmed_at' => now(),
        ]);

        $this->delete(route('settings.security.devices.destroy', $device), [
            'two_factor_code' => '123456',
        ])->assertRedirect(route('login'));
    }

    public function test_guest_redirected_to_login_from_recovery_codes_regenerate(): void
    {
        $this->post(route('settings.security.recovery-codes.regenerate'), [
            'two_factor_code' => '123456',
        ])->assertRedirect(route('login'));
    }

    public function test_store_blocks_when_device_limit_reached(): void
    {
        // Admin already has 5 confirmed devices -> store returns the limit error
        // flash even with a valid current code.
        $admin = $this->actingAsAdmin();
        $secret = $this->realConfirmedDeviceFor($admin);
        $service = app(TwoFactorService::class);

        // realConfirmedDeviceFor leaves exactly one confirmed device; add 4 more.
        for ($i = 0; $i < 4; $i++) {
            $extra = $service->createDevice($admin, "Dev {$i}");
            $extra->update(['confirmed_at' => now()]);
        }
        $this->assertSame(5, $admin->twoFactorDevices()->whereNotNull('confirmed_at')->count());

        $this->post(route('settings.security.devices.store'), [
            'name' => 'Sexto',
            'two_factor_code' => $this->otpFor($secret),
        ])->assertSessionHas('error');

        $this->assertDatabaseMissing('two_factor_devices', [
            'user_id' => $admin->id,
            'name' => 'Sexto',
        ]);
    }
}
