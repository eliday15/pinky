<?php

namespace Tests\Concerns;

use App\Models\Employee;
use App\Models\TwoFactorDevice;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use PragmaRX\Google2FA\Google2FA;

/**
 * Helpers for creating authenticated users that satisfy every middleware
 * guard in the app (auth, verified, password-changed, two-factor-setup).
 *
 * Roles admin/rrhh/supervisor are forced into 2FA by the EnsureTwoFactorSetup
 * middleware, so users with those roles receive a confirmed TwoFactorDevice.
 * The device secret is irrelevant for feature tests — the middleware only
 * checks that at least one device has a non-null confirmed_at.
 */
trait InteractsWithAuth
{
    /**
     * Fixed base32 TOTP secret used for every seeded confirmed 2FA device.
     *
     * Stored encrypted on the device (mirroring production, where
     * TwoFactorService::createDevice encrypts the secret). Knowing the raw
     * secret lets tests generate a valid code via validTwoFactorCode().
     */
    protected string $twoFactorSecret = 'JBSWY3DPEHPK3PXP';

    /**
     * Create a user that passes all middleware, optionally with a role.
     *
     * @param string|null $role One of admin|rrhh|supervisor|employee
     * @param array<string, mixed> $attributes Extra user attributes
     * @param bool $withTwoFactor Attach a confirmed 2FA device when the role requires it
     */
    protected function createUser(?string $role = null, array $attributes = [], bool $withTwoFactor = true): User
    {
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'must_change_password' => false,
        ], $attributes));

        if ($role !== null) {
            $user->assignRole($role);
        }

        if ($withTwoFactor && $user->requiresTwoFactor()) {
            $this->confirmTwoFactorFor($user);
        }

        return $user;
    }

    /**
     * Attach a confirmed two-factor device so the user clears the 2FA gate.
     */
    protected function confirmTwoFactorFor(User $user): TwoFactorDevice
    {
        return TwoFactorDevice::create([
            'user_id' => $user->id,
            'name' => 'Test Authenticator',
            'secret' => Crypt::encryptString($this->twoFactorSecret),
            'confirmed_at' => now(),
        ]);
    }

    /**
     * Generate a currently-valid TOTP code for the seeded 2FA secret.
     *
     * Matches what TwoFactorService::verifyCodeForDevice expects, so it can be
     * submitted to any 2FA-gated endpoint (e.g. anomaly resolve/dismiss).
     */
    protected function validTwoFactorCode(): string
    {
        return app(Google2FA::class)->getCurrentOtp($this->twoFactorSecret);
    }

    /**
     * Link a real Employee record to the given user (for team/own scoping).
     *
     * @param array<string, mixed> $attributes
     */
    protected function attachEmployee(User $user, array $attributes = []): Employee
    {
        return Employee::factory()->create(array_merge(['user_id' => $user->id], $attributes));
    }

    /** Create an admin user (full permissions). */
    protected function adminUser(array $attributes = []): User
    {
        return $this->createUser('admin', $attributes);
    }

    /** Create an RRHH (HR) user. */
    protected function rrhhUser(array $attributes = []): User
    {
        return $this->createUser('rrhh', $attributes);
    }

    /** Create a supervisor user. */
    protected function supervisorUser(array $attributes = []): User
    {
        return $this->createUser('supervisor', $attributes);
    }

    /** Create a self-service employee user (no 2FA requirement). */
    protected function employeeUser(array $attributes = []): User
    {
        return $this->createUser('employee', $attributes);
    }

    /** Create an admin user and immediately authenticate as them. */
    protected function actingAsAdmin(array $attributes = []): User
    {
        $user = $this->adminUser($attributes);
        $this->actingAs($user);

        return $user;
    }

    /** Create an RRHH user and immediately authenticate as them. */
    protected function actingAsRrhh(array $attributes = []): User
    {
        $user = $this->rrhhUser($attributes);
        $this->actingAs($user);

        return $user;
    }

    /** Create a supervisor user and immediately authenticate as them. */
    protected function actingAsSupervisor(array $attributes = []): User
    {
        $user = $this->supervisorUser($attributes);
        $this->actingAs($user);

        return $user;
    }

    /** Create an employee user and immediately authenticate as them. */
    protected function actingAsEmployee(array $attributes = []): User
    {
        $user = $this->employeeUser($attributes);
        $this->actingAs($user);

        return $user;
    }
}
