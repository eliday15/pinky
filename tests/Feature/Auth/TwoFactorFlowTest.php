<?php

namespace Tests\Feature\Auth;

use App\Models\TwoFactorDevice;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\FeatureTestCase;

/**
 * Front-to-back coverage of the two-factor setup + challenge flows:
 * the EnsureTwoFactorSetup middleware redirect, the setup page render and its
 * Inertia prop contract, the confirm/validation behaviour, and the challenge
 * controller's session guard.
 */
class TwoFactorFlowTest extends FeatureTestCase
{
    // ---------------------------------------------------------------------
    // EnsureTwoFactorSetup middleware
    // ---------------------------------------------------------------------

    /**
     * An admin (role requires 2FA) WITHOUT a confirmed device is bounced to
     * the setup route by the EnsureTwoFactorSetup middleware.
     */
    public function test_required_role_without_device_is_redirected_to_setup(): void
    {
        $user = $this->createUser('admin', [], withTwoFactor: false);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route('two-factor.setup'))
            ->assertSessionHas('warning');
    }

    /**
     * An employee (role does NOT require 2FA) is never bounced to setup.
     */
    public function test_non_required_role_is_not_redirected_to_setup(): void
    {
        $user = $this->createUser('employee', [], withTwoFactor: false);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk();
    }

    // ---------------------------------------------------------------------
    // two-factor.setup (GET show)
    // ---------------------------------------------------------------------

    /**
     * GET two-factor/setup renders Auth/TwoFactorSetup and supplies EVERY prop
     * the Vue page consumes. For an admin without 2FA the page is in "setup"
     * state: a QR URI + secret are generated and a pending device is created.
     */
    public function test_setup_page_renders_with_all_props_for_admin_without_2fa(): void
    {
        $user = $this->createUser('admin', [], withTwoFactor: false);

        $this->actingAs($user)
            ->get(route('two-factor.setup'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/TwoFactorSetup')
                ->has('qrCodeUri')
                ->has('secret')
                ->where('isEnabled', false)
                ->where('requiresTwoFactor', true)
                ->where('recoveryCodesCount', 0)
                ->has('pendingDeviceId'));

        // The show() action persists an unconfirmed device for the user.
        $this->assertDatabaseHas('two_factor_devices', [
            'user_id' => $user->id,
            'confirmed_at' => null,
        ]);
    }

    /**
     * Hitting setup twice reuses the existing pending (unconfirmed) device
     * rather than spawning a second one.
     */
    public function test_setup_reuses_existing_pending_device(): void
    {
        $user = $this->createUser('admin', [], withTwoFactor: false);

        $this->actingAs($user)->get(route('two-factor.setup'))->assertOk();
        $this->actingAs($user)->get(route('two-factor.setup'))->assertOk();

        $this->assertSame(
            1,
            TwoFactorDevice::where('user_id', $user->id)->whereNull('confirmed_at')->count()
        );
    }

    /**
     * An employee (2FA optional) may still open the setup page voluntarily;
     * requiresTwoFactor is false and isEnabled is false.
     */
    public function test_employee_can_open_setup_page(): void
    {
        $user = $this->createUser('employee', [], withTwoFactor: false);

        $this->actingAs($user)
            ->get(route('two-factor.setup'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/TwoFactorSetup')
                ->where('requiresTwoFactor', false)
                ->where('isEnabled', false));
    }

    /**
     * Guest cannot reach the setup page (lives behind auth).
     */
    public function test_guest_cannot_view_setup_page(): void
    {
        $this->get(route('two-factor.setup'))
            ->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------------
    // two-factor.confirm (POST)
    // ---------------------------------------------------------------------

    /**
     * Confirm requires a 6-digit code and a valid device_id.
     */
    public function test_confirm_requires_code_and_device_id(): void
    {
        $user = $this->createUser('admin', [], withTwoFactor: false);

        $this->actingAs($user)
            ->from(route('two-factor.setup'))
            ->post(route('two-factor.confirm'), [])
            ->assertSessionHasErrors(['code', 'device_id']);
    }

    /**
     * Confirm rejects a code that is not exactly 6 characters.
     */
    public function test_confirm_rejects_wrong_length_code(): void
    {
        $user = $this->createUser('admin', [], withTwoFactor: false);
        $device = TwoFactorDevice::create([
            'user_id' => $user->id,
            'name' => 'Test',
            'secret' => \Illuminate\Support\Facades\Crypt::encryptString('JBSWY3DPEHPK3PXP'),
        ]);

        $this->actingAs($user)
            ->from(route('two-factor.setup'))
            ->post(route('two-factor.confirm'), [
                'code' => '123',
                'device_id' => $device->id,
            ])
            ->assertSessionHasErrors(['code']);
    }

    /**
     * A syntactically valid but incorrect TOTP code is rejected with a
     * code error and the device stays unconfirmed.
     */
    public function test_confirm_rejects_incorrect_totp_code(): void
    {
        $user = $this->createUser('admin', [], withTwoFactor: false);
        $device = TwoFactorDevice::create([
            'user_id' => $user->id,
            'name' => 'Test',
            'secret' => \Illuminate\Support\Facades\Crypt::encryptString('JBSWY3DPEHPK3PXP'),
        ]);

        $this->actingAs($user)
            ->from(route('two-factor.setup'))
            ->post(route('two-factor.confirm'), [
                'code' => '000000',
                'device_id' => $device->id,
            ])
            ->assertSessionHasErrors(['code']);

        $this->assertDatabaseHas('two_factor_devices', [
            'id' => $device->id,
            'confirmed_at' => null,
        ]);
    }

    /**
     * A correct TOTP code confirms the device, generates recovery codes (first
     * device), and redirects to the recovery-codes view with a success flash.
     */
    public function test_confirm_with_valid_code_enables_two_factor(): void
    {
        $user = $this->createUser('admin', [], withTwoFactor: false);

        $service = app(\App\Services\TwoFactorService::class);
        $device = $service->createDevice($user, 'Autenticador principal');
        $secret = $service->getDeviceSecret($device);
        $validCode = (new \PragmaRX\Google2FA\Google2FA())->getCurrentOtp($secret);

        $this->actingAs($user)
            ->post(route('two-factor.confirm'), [
                'code' => $validCode,
                'device_id' => $device->id,
            ])
            ->assertRedirect(route('two-factor.recovery-codes'))
            ->assertSessionHas('success');

        $this->assertTrue(User::find($user->id)->hasTwoFactorEnabled());
        $this->assertNotNull(User::find($user->id)->two_factor_recovery_codes);
    }

    /**
     * Guest cannot confirm a device.
     */
    public function test_guest_cannot_confirm(): void
    {
        $this->post(route('two-factor.confirm'), [
            'code' => '123456',
            'device_id' => 1,
        ])->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------------
    // two-factor.recovery-codes (GET)
    // ---------------------------------------------------------------------

    /**
     * For a user with 2FA enabled, the recovery-codes page renders with the
     * enabled state and a non-zero recovery code count.
     */
    public function test_recovery_codes_page_renders_for_enabled_user(): void
    {
        $user = $this->createUser('admin'); // confirmed device via helper
        app(\App\Services\TwoFactorService::class)
            ->storeRecoveryCodes($user, ['aaaa-bbbb', 'cccc-dddd']);

        $this->actingAs($user)
            ->get(route('two-factor.recovery-codes'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/TwoFactorSetup')
                ->where('isEnabled', true)
                ->where('recoveryCodesCount', 2));
    }

    /**
     * For a user WITHOUT 2FA the recovery-codes action still renders the setup
     * component but in a disabled state with zero codes.
     */
    public function test_recovery_codes_page_renders_disabled_for_user_without_2fa(): void
    {
        $user = $this->createUser('employee', [], withTwoFactor: false);

        $this->actingAs($user)
            ->get(route('two-factor.recovery-codes'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/TwoFactorSetup')
                ->where('isEnabled', false)
                ->where('recoveryCodesCount', 0));
    }

    // ---------------------------------------------------------------------
    // two-factor.challenge (GET) — session guard
    // ---------------------------------------------------------------------

    /**
     * The challenge page redirects back to login when there is no pending
     * two_factor_user_id in the session (the only reachable "no-session" path).
     */
    public function test_challenge_redirects_to_login_without_session(): void
    {
        $this->get(route('two-factor.challenge'))
            ->assertRedirect(route('login'));
    }

    /**
     * When a pending two_factor_user_id IS in the session, the challenge page
     * renders the Auth/TwoFactorChallenge component (no props).
     */
    public function test_challenge_renders_with_pending_session(): void
    {
        $user = $this->createUser('admin');

        $this->withSession(['two_factor_user_id' => $user->id])
            ->get(route('two-factor.challenge'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/TwoFactorChallenge'));
    }

    /**
     * The challenge verify endpoint redirects to login when there is no pending
     * two_factor_user_id in the session.
     */
    public function test_challenge_verify_redirects_to_login_without_session(): void
    {
        $this->post(route('two-factor.verify'), [
            'two_factor_code' => '123456',
        ])->assertRedirect(route('login'));
    }

    /**
     * With a pending session and a valid TOTP code, the challenge verify logs
     * the user in and redirects to the dashboard.
     */
    public function test_challenge_verify_with_valid_code_logs_in(): void
    {
        $user = $this->createUser('admin', [], withTwoFactor: false);
        $service = app(\App\Services\TwoFactorService::class);
        $device = $service->createDevice($user, 'Autenticador principal');
        $service->confirmDevice($device, $user);
        $secret = $service->getDeviceSecret($device);
        $validCode = (new \PragmaRX\Google2FA\Google2FA())->getCurrentOtp($secret);

        $response = $this->withSession(['two_factor_user_id' => $user->id])
            ->post(route('two-factor.verify'), [
                'two_factor_code' => $validCode,
            ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    /**
     * With a pending session but an incorrect TOTP code, the verify endpoint
     * fails validation and does not authenticate the user.
     */
    public function test_challenge_verify_rejects_incorrect_code(): void
    {
        // Build a user with a properly encrypted, confirmed device so the
        // verifyCode() path can decrypt the secret (the harness helper stores
        // a non-encrypted placeholder secret which only satisfies middleware).
        $user = $this->createUser('admin', [], withTwoFactor: false);
        $service = app(\App\Services\TwoFactorService::class);
        $device = $service->createDevice($user, 'Autenticador principal');
        $service->confirmDevice($device, $user);

        $this->withSession(['two_factor_user_id' => $user->id])
            ->from(route('two-factor.challenge'))
            ->post(route('two-factor.verify'), [
                'two_factor_code' => '000000',
            ])
            ->assertSessionHasErrors(['two_factor_code']);

        $this->assertGuest();
    }

    // ---------------------------------------------------------------------
    // Login flow with 2FA — documents the disabled-challenge contract
    // ---------------------------------------------------------------------

    /**
     * DOCUMENTED BEHAVIOUR: the 2FA challenge on login is currently disabled
     * (commented out in AuthenticatedSessionController::store). A user WITH a
     * confirmed device is logged straight in instead of being redirected to
     * two-factor.challenge. This test asserts the ACTUAL behaviour and the
     * divergence is recorded in the workflow bug report.
     */
    public function test_login_with_confirmed_device_skips_challenge(): void
    {
        $user = $this->createUser('admin'); // has a confirmed 2FA device
        $user->forceFill(['password' => \Illuminate\Support\Facades\Hash::make('password')])->save();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        // Challenge is bypassed: user is authenticated and sent to dashboard.
        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs(User::find($user->id));
    }

    // ---------------------------------------------------------------------
    // two-factor.disable (DELETE destroy)
    // ---------------------------------------------------------------------

    /**
     * Disable requires the current password.
     */
    public function test_disable_requires_password(): void
    {
        $user = $this->createUser('employee', [], withTwoFactor: false);
        $service = app(\App\Services\TwoFactorService::class);
        $device = $service->createDevice($user, 'Autenticador principal');
        $service->confirmDevice($device, $user);

        $this->actingAs($user)
            ->from(route('two-factor.recovery-codes'))
            ->delete(route('two-factor.disable'), [])
            ->assertSessionHasErrors(['password']);

        // The confirmed device survives a failed disable attempt.
        $this->assertTrue(User::find($user->id)->hasTwoFactorEnabled());
    }

    /**
     * Disable rejects a wrong current password.
     */
    public function test_disable_rejects_wrong_password(): void
    {
        $user = $this->createUser('employee', [], withTwoFactor: false);
        $user->forceFill(['password' => \Illuminate\Support\Facades\Hash::make('password')])->save();
        $service = app(\App\Services\TwoFactorService::class);
        $device = $service->createDevice($user, 'Autenticador principal');
        $service->confirmDevice($device, $user);

        $this->actingAs($user)
            ->from(route('two-factor.recovery-codes'))
            ->delete(route('two-factor.disable'), ['password' => 'not-the-password'])
            ->assertSessionHasErrors(['password']);

        $this->assertTrue(User::find($user->id)->hasTwoFactorEnabled());
    }

    /**
     * A non-required role (employee) with the correct password disables 2FA:
     * every device is removed, recovery codes are cleared, and the user is
     * redirected to profile.edit with a success flash.
     */
    public function test_non_required_role_can_disable_two_factor(): void
    {
        $user = $this->createUser('employee', [], withTwoFactor: false);
        $user->forceFill(['password' => \Illuminate\Support\Facades\Hash::make('password')])->save();
        $service = app(\App\Services\TwoFactorService::class);
        $device = $service->createDevice($user, 'Autenticador principal');
        $service->confirmDevice($device, $user); // also stores recovery codes

        $this->actingAs($user)
            ->delete(route('two-factor.disable'), ['password' => 'password'])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('success');

        $fresh = User::find($user->id);
        $this->assertFalse($fresh->hasTwoFactorEnabled());
        $this->assertNull($fresh->two_factor_recovery_codes);
        $this->assertDatabaseMissing('two_factor_devices', ['id' => $device->id]);
    }

    /**
     * A required role (admin) CANNOT disable 2FA even with the right password:
     * devices remain and the controller flashes an error back.
     */
    public function test_required_role_cannot_disable_two_factor(): void
    {
        $user = $this->createUser('admin'); // confirmed device via helper
        $user->forceFill(['password' => \Illuminate\Support\Facades\Hash::make('password')])->save();

        $this->actingAs($user)
            ->from(route('two-factor.recovery-codes'))
            ->delete(route('two-factor.disable'), ['password' => 'password'])
            ->assertRedirect(route('two-factor.recovery-codes'))
            ->assertSessionHas('error');

        // Device is untouched: the required role is still protected.
        $this->assertTrue(User::find($user->id)->hasTwoFactorEnabled());
    }

    /**
     * Guest cannot disable 2FA.
     */
    public function test_guest_cannot_disable_two_factor(): void
    {
        $this->delete(route('two-factor.disable'), ['password' => 'password'])
            ->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------------
    // two-factor.regenerate-recovery-codes (POST)
    // ---------------------------------------------------------------------

    /**
     * A user with 2FA enabled can regenerate recovery codes: a fresh batch of 8
     * is stored and the user is redirected to the recovery-codes view with a
     * success flash + the plaintext codes flashed for one-time display.
     */
    public function test_enabled_user_can_regenerate_recovery_codes(): void
    {
        $user = $this->createUser('admin'); // confirmed device via helper
        $service = app(\App\Services\TwoFactorService::class);
        $service->storeRecoveryCodes($user, ['old1-old1', 'old2-old2']);

        $oldCodes = User::find($user->id)->two_factor_recovery_codes;

        $this->actingAs($user)
            ->post(route('two-factor.regenerate-recovery-codes'))
            ->assertRedirect(route('two-factor.recovery-codes'))
            ->assertSessionHas('success')
            ->assertSessionHas('recoveryCodes');

        $fresh = User::find($user->id);
        // The stored ciphertext changed and the standard 8-code batch is present.
        $this->assertNotSame($oldCodes, $fresh->two_factor_recovery_codes);
        $this->assertSame(8, $service->remainingRecoveryCodesCount($fresh));
    }

    /**
     * A user WITHOUT 2FA enabled is redirected to setup rather than regenerating.
     */
    public function test_user_without_2fa_regenerate_redirects_to_setup(): void
    {
        $user = $this->createUser('employee', [], withTwoFactor: false);

        $this->actingAs($user)
            ->post(route('two-factor.regenerate-recovery-codes'))
            ->assertRedirect(route('two-factor.setup'));

        $this->assertNull(User::find($user->id)->two_factor_recovery_codes);
    }

    /**
     * Guest cannot regenerate recovery codes.
     */
    public function test_guest_cannot_regenerate_recovery_codes(): void
    {
        $this->post(route('two-factor.regenerate-recovery-codes'))
            ->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------------
    // two-factor.recovery-codes / setup — remaining guest cases
    // ---------------------------------------------------------------------

    /**
     * Guest cannot view the recovery-codes page (behind auth).
     */
    public function test_guest_cannot_view_recovery_codes(): void
    {
        $this->get(route('two-factor.recovery-codes'))
            ->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------------
    // confirm — second-device branch (no new recovery codes)
    // ---------------------------------------------------------------------

    /**
     * Confirming a SECOND device for a user who already has 2FA enabled does
     * NOT regenerate recovery codes (confirmDevice returns null), yet still
     * redirects to the recovery-codes view with a success flash. Exercises the
     * controller's else branch that the existing first-device test misses.
     */
    public function test_confirm_second_device_does_not_regenerate_recovery_codes(): void
    {
        $user = $this->createUser('admin'); // already has one confirmed device + helper

        $service = app(\App\Services\TwoFactorService::class);
        // Seed recovery codes so we can prove they are NOT replaced.
        $service->storeRecoveryCodes($user, ['keep-keep', 'safe-safe']);
        $existingCodes = User::find($user->id)->two_factor_recovery_codes;

        $secondDevice = $service->createDevice($user, 'Segundo dispositivo');
        $secret = $service->getDeviceSecret($secondDevice);
        $validCode = (new \PragmaRX\Google2FA\Google2FA())->getCurrentOtp($secret);

        $this->actingAs($user)
            ->post(route('two-factor.confirm'), [
                'code' => $validCode,
                'device_id' => $secondDevice->id,
            ])
            ->assertRedirect(route('two-factor.recovery-codes'))
            ->assertSessionHas('success');

        $fresh = User::find($user->id);
        // Recovery codes untouched (only the first device generates them).
        $this->assertSame($existingCodes, $fresh->two_factor_recovery_codes);
        $this->assertDatabaseHas('two_factor_devices', [
            'id' => $secondDevice->id,
        ]);
        $this->assertNotNull($fresh->twoFactorDevices()->whereKey($secondDevice->id)->first()->confirmed_at);
    }
}
