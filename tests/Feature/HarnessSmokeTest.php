<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\FeatureTestCase;

/**
 * Proves the feature-test harness clears every middleware guard and that
 * the role helpers behave as expected. If this fails, every other feature
 * test built on FeatureTestCase is suspect.
 */
class HarnessSmokeTest extends FeatureTestCase
{
    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_admin_with_helper_reaches_dashboard(): void
    {
        $this->actingAsAdmin();

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Dashboard'));
    }

    public function test_rrhh_with_helper_reaches_dashboard(): void
    {
        $this->actingAsRrhh();

        $this->get('/dashboard')->assertOk();
    }

    public function test_supervisor_is_routed_to_incidents(): void
    {
        $this->actingAsSupervisor();

        $this->get('/dashboard')->assertRedirect(route('incidents.index'));
    }

    public function test_admin_without_two_factor_is_forced_to_setup(): void
    {
        $user = $this->createUser('admin', [], withTwoFactor: false);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route('two-factor.setup'));
    }

    public function test_user_must_change_password_is_forced(): void
    {
        $user = $this->createUser('admin', ['must_change_password' => true]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route('password.force-change'));
    }

    public function test_roles_and_permissions_are_seeded(): void
    {
        $admin = $this->adminUser();

        $this->assertTrue($admin->hasRole('admin'));
        $this->assertTrue($admin->hasPermissionTo('employees.view_all'));
        $this->assertTrue($admin->requiresTwoFactor());
        $this->assertTrue($admin->hasTwoFactorEnabled());
    }

    public function test_employee_role_does_not_require_two_factor(): void
    {
        $employee = $this->employeeUser();

        $this->assertFalse($employee->requiresTwoFactor());
        $this->assertInstanceOf(User::class, $employee);
    }

    public function test_seeded_two_factor_secret_verifies_a_generated_code(): void
    {
        $admin = $this->adminUser();
        $device = $admin->twoFactorDevices()->first();
        $service = app(\App\Services\TwoFactorService::class);

        $this->assertTrue(
            $service->verifyCodeForDevice($device, $this->validTwoFactorCode()),
            'Harness-generated TOTP code must verify against the encrypted device secret'
        );
    }
}
