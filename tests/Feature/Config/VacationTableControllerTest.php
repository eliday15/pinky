<?php

namespace Tests\Feature\Config;

use App\Models\VacationTable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\FeatureTestCase;

/**
 * Feature tests for VacationTableController.
 *
 * The controller gates both index and update on the `settings.edit` permission,
 * which only the admin role holds. update() replaces ALL rows inside a
 * transaction (delete-all then create from the submitted `entries` array).
 */
class VacationTableControllerTest extends FeatureTestCase
{
    // ----------------------------------------------------------------- index

    public function test_admin_sees_index_with_expected_inertia_props(): void
    {
        $this->actingAsAdmin();
        VacationTable::query()->delete();
        VacationTable::factory()->forYears(1, 12)->create();
        VacationTable::factory()->forYears(2, 14)->create();

        $this->get(route('settings.vacation-table'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/VacationTable')
                ->has('vacationTable', 2));
    }

    public function test_index_orders_by_years_of_service(): void
    {
        $this->actingAsAdmin();
        VacationTable::query()->delete();
        VacationTable::factory()->forYears(5, 20)->create();
        VacationTable::factory()->forYears(1, 12)->create();

        $this->get(route('settings.vacation-table'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('vacationTable.0.years_of_service', 1)
                ->where('vacationTable.1.years_of_service', 5));
    }

    public function test_rrhh_cannot_view_index(): void
    {
        $this->actingAsRrhh();
        $this->get(route('settings.vacation-table'))->assertForbidden();
    }

    public function test_supervisor_cannot_view_index(): void
    {
        $this->actingAsSupervisor();
        $this->get(route('settings.vacation-table'))->assertForbidden();
    }

    public function test_employee_cannot_view_index(): void
    {
        $this->actingAsEmployee();
        $this->get(route('settings.vacation-table'))->assertForbidden();
    }

    public function test_guest_redirected_to_login_from_index(): void
    {
        $this->get(route('settings.vacation-table'))->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------- update

    public function test_admin_can_replace_all_entries(): void
    {
        $this->actingAsAdmin();
        VacationTable::query()->delete();
        VacationTable::factory()->forYears(99, 99)->create();

        $this->put(route('settings.vacation-table.update'), [
            'entries' => [
                ['years_of_service' => 1, 'vacation_days' => 12],
                ['years_of_service' => 2, 'vacation_days' => 14],
                ['years_of_service' => 3, 'vacation_days' => 16],
            ],
        ])
            ->assertRedirect(route('settings.vacation-table'))
            ->assertSessionHas('success');

        // Old row replaced; only the three submitted rows remain.
        $this->assertDatabaseMissing('vacation_tables', ['years_of_service' => 99]);
        $this->assertDatabaseHas('vacation_tables', ['years_of_service' => 1, 'vacation_days' => 12]);
        $this->assertDatabaseHas('vacation_tables', ['years_of_service' => 3, 'vacation_days' => 16]);
        $this->assertSame(3, VacationTable::count());
    }

    public function test_update_requires_entries_array(): void
    {
        $this->actingAsAdmin();

        $this->from(route('settings.vacation-table'))
            ->put(route('settings.vacation-table.update'), [])
            ->assertRedirect(route('settings.vacation-table'))
            ->assertSessionHasErrors(['entries']);
    }

    public function test_update_requires_non_empty_entries(): void
    {
        $this->actingAsAdmin();

        $this->from(route('settings.vacation-table'))
            ->put(route('settings.vacation-table.update'), [
                'entries' => [],
            ])
            ->assertSessionHasErrors(['entries']);
    }

    public function test_update_validates_entry_fields(): void
    {
        $this->actingAsAdmin();

        $this->from(route('settings.vacation-table'))
            ->put(route('settings.vacation-table.update'), [
                'entries' => [
                    ['years_of_service' => 0, 'vacation_days' => 0],
                ],
            ])
            ->assertSessionHasErrors([
                'entries.0.years_of_service',
                'entries.0.vacation_days',
            ]);
    }

    public function test_update_rejects_non_integer_values(): void
    {
        $this->actingAsAdmin();

        $this->from(route('settings.vacation-table'))
            ->put(route('settings.vacation-table.update'), [
                'entries' => [
                    ['years_of_service' => 'abc', 'vacation_days' => 'xyz'],
                ],
            ])
            ->assertSessionHasErrors([
                'entries.0.years_of_service',
                'entries.0.vacation_days',
            ]);
    }

    public function test_failed_validation_does_not_wipe_existing_table(): void
    {
        $this->actingAsAdmin();
        VacationTable::query()->delete();
        VacationTable::factory()->forYears(7, 22)->create();

        $this->from(route('settings.vacation-table'))
            ->put(route('settings.vacation-table.update'), ['entries' => []])
            ->assertSessionHasErrors(['entries']);

        // Validation fails before the destructive transaction runs.
        $this->assertDatabaseHas('vacation_tables', ['years_of_service' => 7, 'vacation_days' => 22]);
    }

    public function test_rrhh_cannot_update(): void
    {
        $this->actingAsRrhh();
        VacationTable::query()->delete();
        VacationTable::factory()->forYears(8, 24)->create();

        $this->put(route('settings.vacation-table.update'), [
            'entries' => [['years_of_service' => 1, 'vacation_days' => 12]],
        ])->assertForbidden();

        // Existing data untouched.
        $this->assertDatabaseHas('vacation_tables', ['years_of_service' => 8]);
    }

    public function test_supervisor_cannot_update(): void
    {
        $this->actingAsSupervisor();

        $this->put(route('settings.vacation-table.update'), [
            'entries' => [['years_of_service' => 1, 'vacation_days' => 12]],
        ])->assertForbidden();
    }

    public function test_employee_cannot_update(): void
    {
        $this->actingAsEmployee();
        VacationTable::query()->delete();
        VacationTable::factory()->forYears(9, 26)->create();

        $this->put(route('settings.vacation-table.update'), [
            'entries' => [['years_of_service' => 1, 'vacation_days' => 12]],
        ])->assertForbidden();

        // Existing data untouched by an unauthorized employee.
        $this->assertDatabaseHas('vacation_tables', ['years_of_service' => 9]);
    }

    public function test_update_rejects_negative_vacation_days(): void
    {
        $this->actingAsAdmin();

        // min:1 on vacation_days — negatives are rejected.
        $this->from(route('settings.vacation-table'))
            ->put(route('settings.vacation-table.update'), [
                'entries' => [
                    ['years_of_service' => 1, 'vacation_days' => -3],
                ],
            ])
            ->assertSessionHasErrors(['entries.0.vacation_days']);
    }

    public function test_guest_cannot_update(): void
    {
        $this->put(route('settings.vacation-table.update'), [
            'entries' => [['years_of_service' => 1, 'vacation_days' => 12]],
        ])->assertRedirect(route('login'));
    }
}
