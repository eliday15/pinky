<?php

namespace Tests\Feature\Admin;

use App\Models\SystemSetting;
use App\Services\BreakfastVendorAccessService;
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

    public function test_breakfast_close_margin_rejects_invalid_values_without_saving(): void
    {
        $this->actingAsAdmin();
        foreach ([-1, 'invalid', 1.5, 60, 61] as $value) {
            $this->put(route('settings.update'), [
                'settings' => [['key' => 'breakfast_close_minutes_before_entry', 'value' => $value]],
            ])->assertSessionHasErrors('settings.0.value');

            $this->put(route('settings.updateSingle'), [
                'key' => 'breakfast_close_minutes_before_entry', 'value' => $value,
            ])->assertSessionHasErrors('value');
        }
        $this->assertSame(10, SystemSetting::get('breakfast_close_minutes_before_entry'));
    }

    public function test_breakfast_window_validation_uses_both_submitted_values(): void
    {
        $this->actingAsAdmin();
        $this->put(route('settings.updateSingle'), [
            'key' => 'breakfast_window_minutes', 'value' => 5,
        ])->assertSessionHasErrors('value');

        $this->put(route('settings.update'), [
            'settings' => [
                ['key' => 'breakfast_window_minutes', 'value' => 5],
                ['key' => 'breakfast_close_minutes_before_entry', 'value' => 0],
            ],
        ])->assertSessionHasNoErrors();
        $this->assertSame(5, SystemSetting::get('breakfast_window_minutes'));
        $this->assertSame(0, SystemSetting::get('breakfast_close_minutes_before_entry'));
    }

    public function test_breakfast_testing_switch_can_be_disabled_and_saved_again(): void
    {
        $this->actingAsAdmin();
        SystemSetting::set('breakfast_open_all_day', true);

        foreach (['false', 'false', 'true', '0', false, true] as $value) {
            $this->put(route('settings.update'), [
                'settings' => [['key' => 'breakfast_open_all_day', 'value' => $value]],
            ])->assertSessionHasNoErrors()->assertRedirect();

            $expected = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            $this->assertSame($expected, SystemSetting::get('breakfast_open_all_day'));
            $this->assertDatabaseHas('system_settings', [
                'key' => 'breakfast_open_all_day',
                'value' => $expected ? 'true' : 'false',
            ]);
        }
    }

    public function test_single_setting_update_preserves_false_boolean_string(): void
    {
        $this->actingAsAdmin();
        SystemSetting::set('breakfast_open_all_day', true);

        $this->put(route('settings.updateSingle'), [
            'key' => 'breakfast_open_all_day',
            'value' => 'false',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertFalse(SystemSetting::get('breakfast_open_all_day'));
    }

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

    public function test_bulk_update_moves_kiosk_role_to_selected_breakfast_vendor(): void
    {
        $this->actingAsAdmin();
        $oldUser = $this->supervisorUser();
        $oldVendor = $this->attachEmployee($oldUser);
        $newUser = $this->supervisorUser();
        $newVendor = $this->attachEmployee($newUser);
        SystemSetting::set('breakfast_vendor_employee_id', $oldVendor->id);
        $oldUser->assignRole(BreakfastVendorAccessService::ROLE);

        $this->put(route('settings.update'), [
            'settings' => [[
                'key' => 'breakfast_vendor_employee_id',
                'value' => (string) $newVendor->id,
            ]],
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertFalse($oldUser->fresh()->hasRole(BreakfastVendorAccessService::ROLE));
        $newUser->refresh();
        $this->assertTrue($newUser->hasRole('supervisor'));
        $this->assertTrue($newUser->hasRole(BreakfastVendorAccessService::ROLE));
        $this->assertTrue($newUser->can('breakfasts.register'));
        $this->assertFalse($newUser->can('breakfasts.view'));
    }

    public function test_bulk_update_rejects_breakfast_vendor_without_user_account(): void
    {
        $this->actingAsAdmin();
        $vendorWithoutAccount = \App\Models\Employee::factory()->create();
        SystemSetting::set('breakfast_vendor_employee_id', 0);

        $this->put(route('settings.update'), [
            'settings' => [[
                'key' => 'breakfast_vendor_employee_id',
                'value' => (string) $vendorWithoutAccount->id,
            ]],
        ])->assertSessionHasErrors(['settings.0.value']);

        $this->assertSame(0, (int) SystemSetting::get('breakfast_vendor_employee_id'));
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

    public function test_bulk_update_rejects_duplicate_keys_without_changing_vendor_access(): void
    {
        $this->actingAsAdmin();
        $currentUser = $this->supervisorUser();
        $currentVendor = $this->attachEmployee($currentUser);
        $newUser = $this->supervisorUser();
        $newVendor = $this->attachEmployee($newUser);
        SystemSetting::set('breakfast_vendor_employee_id', $currentVendor->id);
        $currentUser->assignRole(BreakfastVendorAccessService::ROLE);

        $this->put(route('settings.update'), [
            'settings' => [
                [
                    'key' => 'breakfast_vendor_employee_id',
                    'value' => (string) $newVendor->id,
                ],
                [
                    'key' => 'breakfast_vendor_employee_id',
                    'value' => '0',
                ],
            ],
        ])->assertSessionHasErrors(['settings.0.key', 'settings.1.key']);

        $this->assertSame($currentVendor->id, (int) SystemSetting::get('breakfast_vendor_employee_id'));
        $this->assertTrue($currentUser->fresh()->hasRole(BreakfastVendorAccessService::ROLE));
        $this->assertFalse($newUser->fresh()->hasRole(BreakfastVendorAccessService::ROLE));
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

    public function test_single_update_can_clear_vendor_and_revoke_kiosk_role(): void
    {
        $this->actingAsAdmin();
        $vendorUser = $this->supervisorUser();
        $vendor = $this->attachEmployee($vendorUser);
        SystemSetting::set('breakfast_vendor_employee_id', $vendor->id);
        $vendorUser->assignRole(BreakfastVendorAccessService::ROLE);

        $this->put(route('settings.updateSingle'), [
            'key' => 'breakfast_vendor_employee_id',
            'value' => '0',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertFalse($vendorUser->fresh()->hasRole(BreakfastVendorAccessService::ROLE));
        $this->assertSame(0, (int) SystemSetting::get('breakfast_vendor_employee_id'));
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
