<?php

namespace Tests\Feature\Admin;

use App\Models\SystemSetting;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\FeatureTestCase;

/**
 * Feature tests for SettingsController.
 *
 * Covers the read pages (index/attendance/payroll/general), the bulk and
 * single update endpoints, RBAC (settings.view to read, settings.edit to
 * write — admin only), validation, and the Inertia prop contract.
 */
class SettingsControllerTest extends FeatureTestCase
{
    // ---------------------------------------------------------------------
    // index
    // ---------------------------------------------------------------------

    public function test_admin_sees_settings_index_with_expected_props(): void
    {
        $this->actingAsAdmin();
        SystemSetting::factory()->attendance()->create();

        $this->get(route('settings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Index')
                ->has('settings')
                ->has('groups')
                ->has('can.edit')
                ->where('can.edit', true)
                ->has('security.twoFactorEnabled')
                ->has('security.requiresTwoFactor')
                ->has('security.recoveryCodesCount')
                ->has('security.devices'));
    }

    public function test_rrhh_cannot_view_settings_index(): void
    {
        $this->actingAsRrhh();

        $this->get(route('settings.index'))->assertForbidden();
    }

    public function test_employee_cannot_view_settings_index(): void
    {
        $this->actingAsEmployee();

        $this->get(route('settings.index'))->assertForbidden();
    }

    public function test_guest_redirected_to_login_from_settings_index(): void
    {
        $this->get(route('settings.index'))->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------------
    // attendance / payroll / general read pages
    // ---------------------------------------------------------------------

    public function test_admin_sees_attendance_settings(): void
    {
        $this->actingAsAdmin();
        SystemSetting::factory()->attendance()->create();

        $this->get(route('settings.attendance'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Attendance')
                ->has('settings')
                ->has('can.edit'));
    }

    public function test_admin_sees_payroll_settings(): void
    {
        $this->actingAsAdmin();
        SystemSetting::factory()->payroll()->create();

        $this->get(route('settings.payroll'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Payroll')
                ->has('settings')
                ->has('can.edit'));
    }

    public function test_admin_sees_general_settings(): void
    {
        $this->actingAsAdmin();
        SystemSetting::factory()->create(); // default group is general

        $this->get(route('settings.general'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/General')
                ->has('settings')
                ->has('can.edit'));
    }

    public function test_rrhh_cannot_view_attendance_settings(): void
    {
        $this->actingAsRrhh();

        $this->get(route('settings.attendance'))->assertForbidden();
    }

    public function test_rrhh_cannot_view_payroll_settings(): void
    {
        $this->actingAsRrhh();

        $this->get(route('settings.payroll'))->assertForbidden();
    }

    public function test_rrhh_cannot_view_general_settings(): void
    {
        $this->actingAsRrhh();

        $this->get(route('settings.general'))->assertForbidden();
    }

    public function test_employee_cannot_view_attendance_settings(): void
    {
        $this->actingAsEmployee();

        $this->get(route('settings.attendance'))->assertForbidden();
    }

    public function test_supervisor_cannot_view_payroll_settings(): void
    {
        $this->actingAsSupervisor();

        $this->get(route('settings.payroll'))->assertForbidden();
    }

    public function test_guest_redirected_to_login_from_attendance_settings(): void
    {
        $this->get(route('settings.attendance'))->assertRedirect(route('login'));
    }

    public function test_guest_redirected_to_login_from_payroll_settings(): void
    {
        $this->get(route('settings.payroll'))->assertRedirect(route('login'));
    }

    public function test_guest_redirected_to_login_from_general_settings(): void
    {
        $this->get(route('settings.general'))->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------------
    // update (bulk)
    // ---------------------------------------------------------------------

    public function test_admin_can_bulk_update_settings(): void
    {
        $this->actingAsAdmin();
        $setting = SystemSetting::factory()->attendance()->create([
            'key' => 'tolerancia_retardo',
            'type' => 'integer',
            'value' => '10',
        ]);

        $this->put(route('settings.update'), [
            'settings' => [
                ['key' => $setting->key, 'value' => '15'],
            ],
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('system_settings', [
            'key' => 'tolerancia_retardo',
            'value' => '15',
        ]);
    }

    public function test_bulk_update_validation_requires_settings_array(): void
    {
        $this->actingAsAdmin();

        $this->put(route('settings.update'), [])
            ->assertSessionHasErrors(['settings']);
    }

    public function test_bulk_update_validation_rejects_unknown_key(): void
    {
        $this->actingAsAdmin();

        $this->put(route('settings.update'), [
            'settings' => [
                ['key' => 'does_not_exist_key', 'value' => 'x'],
            ],
        ])->assertSessionHasErrors(['settings.0.key']);
    }

    public function test_rrhh_cannot_bulk_update_settings(): void
    {
        $this->actingAsRrhh();
        $setting = SystemSetting::factory()->create();

        $this->put(route('settings.update'), [
            'settings' => [
                ['key' => $setting->key, 'value' => 'new'],
            ],
        ])->assertForbidden();
    }

    public function test_employee_cannot_bulk_update_settings(): void
    {
        $this->actingAsEmployee();
        $setting = SystemSetting::factory()->create();

        $this->put(route('settings.update'), [
            'settings' => [
                ['key' => $setting->key, 'value' => 'new'],
            ],
        ])->assertForbidden();
    }

    public function test_guest_redirected_to_login_from_bulk_update(): void
    {
        $setting = SystemSetting::factory()->create();

        $this->put(route('settings.update'), [
            'settings' => [
                ['key' => $setting->key, 'value' => 'new'],
            ],
        ])->assertRedirect(route('login'));
    }

    public function test_bulk_update_requires_each_value(): void
    {
        $this->actingAsAdmin();
        $setting = SystemSetting::factory()->create();

        $this->put(route('settings.update'), [
            'settings' => [
                ['key' => $setting->key],
            ],
        ])->assertSessionHasErrors(['settings.0.value']);
    }

    // ---------------------------------------------------------------------
    // updateSingle
    // ---------------------------------------------------------------------

    public function test_admin_can_update_single_setting(): void
    {
        $this->actingAsAdmin();
        $setting = SystemSetting::factory()->create([
            'key' => 'company_name',
            'type' => 'string',
            'value' => 'Old Co',
        ]);

        $this->put(route('settings.updateSingle'), [
            'key' => $setting->key,
            'value' => 'New Co',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('system_settings', [
            'key' => 'company_name',
            'value' => 'New Co',
        ]);
    }

    public function test_update_single_validation_requires_key_and_value(): void
    {
        $this->actingAsAdmin();

        $this->put(route('settings.updateSingle'), [])
            ->assertSessionHasErrors(['key', 'value']);
    }

    public function test_update_single_validation_rejects_unknown_key(): void
    {
        $this->actingAsAdmin();

        $this->put(route('settings.updateSingle'), [
            'key' => 'nope_not_real',
            'value' => 'x',
        ])->assertSessionHasErrors(['key']);
    }

    public function test_rrhh_cannot_update_single_setting(): void
    {
        $this->actingAsRrhh();
        $setting = SystemSetting::factory()->create();

        $this->put(route('settings.updateSingle'), [
            'key' => $setting->key,
            'value' => 'x',
        ])->assertForbidden();
    }

    public function test_employee_cannot_update_single_setting(): void
    {
        $this->actingAsEmployee();
        $setting = SystemSetting::factory()->create();

        $this->put(route('settings.updateSingle'), [
            'key' => $setting->key,
            'value' => 'x',
        ])->assertForbidden();
    }

    public function test_guest_redirected_to_login_from_update_single(): void
    {
        $setting = SystemSetting::factory()->create();

        $this->put(route('settings.updateSingle'), [
            'key' => $setting->key,
            'value' => 'x',
        ])->assertRedirect(route('login'));
    }
}
