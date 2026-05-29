<?php

namespace Tests\Feature\Config;

use App\Models\CompensationType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Schedule;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\FeatureTestCase;

/**
 * Front-to-back contract tests for PositionController (Route::resource,
 * gated by positions.manage -> admin only). Covers RBAC trio, Inertia
 * props consumed by each Vue page, validation (enum/uniqueness/existence),
 * compensation-type pivot sync, supervisor-cycle protection, and the
 * soft-deactivate destroy guarded by active employees.
 */
class PositionControllerTest extends FeatureTestCase
{
    // ---------------------------------------------------------------- index

    public function test_admin_sees_position_index_with_expected_props(): void
    {
        $this->actingAsAdmin();
        Position::factory()->count(2)->create();

        $this->get(route('positions.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Positions/Index')
                ->has('positions')
                ->has('positions.data')
                ->has('departments')
                ->has('filters'));
    }

    public function test_index_defaults_to_active_only(): void
    {
        $this->actingAsAdmin();
        $active = Position::factory()->create();
        Position::factory()->inactive()->create();

        $this->get(route('positions.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('positions.data', 1)
                ->where('positions.data.0.id', $active->id));
    }

    public function test_index_filters_by_department(): void
    {
        $this->actingAsAdmin();
        $deptA = Department::factory()->create();
        $deptB = Department::factory()->create();
        $posA = Position::factory()->create(['department_id' => $deptA->id]);
        Position::factory()->create(['department_id' => $deptB->id]);

        $this->get(route('positions.index', ['department' => $deptA->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('positions.data', 1)
                ->where('positions.data.0.id', $posA->id));
    }

    public function test_rrhh_cannot_view_positions(): void
    {
        $this->actingAsRrhh();
        $this->get(route('positions.index'))->assertForbidden();
    }

    public function test_supervisor_cannot_view_positions(): void
    {
        $this->actingAsSupervisor();
        $this->get(route('positions.index'))->assertForbidden();
    }

    public function test_employee_cannot_view_positions(): void
    {
        $this->actingAsEmployee();
        $this->get(route('positions.index'))->assertForbidden();
    }

    public function test_guest_is_redirected_to_login_from_index(): void
    {
        $this->get(route('positions.index'))->assertRedirect(route('login'));
    }

    // --------------------------------------------------------------- create

    public function test_admin_sees_create_form_with_all_select_props(): void
    {
        $this->actingAsAdmin();

        $this->get(route('positions.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Positions/Create')
                ->has('departments')
                ->has('schedules')
                ->has('positions')
                ->has('compensationTypes')
                ->has('employees'));
    }

    public function test_rrhh_cannot_open_create_form(): void
    {
        $this->actingAsRrhh();
        $this->get(route('positions.create'))->assertForbidden();
    }

    // ---------------------------------------------------------------- store

    public function test_admin_can_store_position(): void
    {
        $this->actingAsAdmin();
        $department = Department::factory()->create();
        $schedule = Schedule::factory()->create();

        $this->post(route('positions.store'), [
            'name' => 'Cajero',
            'code' => 'CAJ-01',
            'position_type' => 'operativo',
            'base_hourly_rate' => 75.50,
            'default_overtime_rate' => 1.5,
            'default_holiday_rate' => 2.0,
            'department_id' => $department->id,
            'default_schedule_id' => $schedule->id,
        ])
            ->assertRedirect(route('positions.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('positions', [
            'name' => 'Cajero',
            'code' => 'CAJ-01',
            'position_type' => 'operativo',
            'is_active' => true,
        ]);
    }

    public function test_store_syncs_compensation_types_with_pivot(): void
    {
        $this->actingAsAdmin();
        $comp = CompensationType::factory()->create();

        $this->post(route('positions.store'), [
            'name' => 'Con Comp',
            'code' => 'COMP-01',
            'position_type' => 'administrativo',
            'compensation_type_ids' => [$comp->id],
            'compensation_type_percentages' => [$comp->id => 10],
            'compensation_type_fixed_amounts' => [$comp->id => null],
        ])->assertRedirect(route('positions.index'));

        $position = Position::where('code', 'COMP-01')->firstOrFail();
        $this->assertTrue($position->compensationTypes()->where('compensation_type_id', $comp->id)->exists());
    }

    public function test_store_requires_name_code_and_position_type(): void
    {
        $this->actingAsAdmin();

        $this->from(route('positions.create'))
            ->post(route('positions.store'), [])
            ->assertSessionHasErrors(['name', 'code', 'position_type'])
            ->assertRedirect(route('positions.create'));
    }

    public function test_store_rejects_invalid_position_type(): void
    {
        $this->actingAsAdmin();

        $this->from(route('positions.create'))
            ->post(route('positions.store'), [
                'name' => 'Mal Tipo',
                'code' => 'BAD-01',
                'position_type' => 'invalido',
            ])
            ->assertSessionHasErrors(['position_type']);
    }

    public function test_store_rejects_duplicate_code(): void
    {
        $this->actingAsAdmin();
        Position::factory()->create(['code' => 'DUP-POS']);

        $this->from(route('positions.create'))
            ->post(route('positions.store'), [
                'name' => 'Duplicado',
                'code' => 'DUP-POS',
                'position_type' => 'operativo',
            ])
            ->assertSessionHasErrors(['code']);
    }

    public function test_store_rejects_overtime_rate_below_one(): void
    {
        $this->actingAsAdmin();

        $this->from(route('positions.create'))
            ->post(route('positions.store'), [
                'name' => 'Bajo OT',
                'code' => 'OT-01',
                'position_type' => 'operativo',
                'default_overtime_rate' => 0.5,
            ])
            ->assertSessionHasErrors(['default_overtime_rate']);
    }

    public function test_store_rejects_nonexistent_department(): void
    {
        $this->actingAsAdmin();

        $this->from(route('positions.create'))
            ->post(route('positions.store'), [
                'name' => 'Sin Dept',
                'code' => 'ND-01',
                'position_type' => 'operativo',
                'department_id' => 999999,
            ])
            ->assertSessionHasErrors(['department_id']);
    }

    public function test_rrhh_cannot_store_position(): void
    {
        $this->actingAsRrhh();
        $this->post(route('positions.store'), [
            'name' => 'X',
            'code' => 'RH-POS',
            'position_type' => 'operativo',
        ])->assertForbidden();

        $this->assertDatabaseMissing('positions', ['code' => 'RH-POS']);
    }

    // ----------------------------------------------------------------- show

    public function test_admin_sees_show_with_position_prop(): void
    {
        $this->actingAsAdmin();
        $position = Position::factory()->create();

        $this->get(route('positions.show', $position))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Positions/Show')
                ->has('position')
                ->where('position.id', $position->id));
    }

    public function test_rrhh_cannot_show_position(): void
    {
        $this->actingAsRrhh();
        $position = Position::factory()->create();
        $this->get(route('positions.show', $position))->assertForbidden();
    }

    // ----------------------------------------------------------------- edit

    public function test_admin_sees_edit_with_all_props(): void
    {
        $this->actingAsAdmin();
        $position = Position::factory()->create();

        $this->get(route('positions.edit', $position))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Positions/Edit')
                ->has('position')
                ->where('position.id', $position->id)
                ->has('departments')
                ->has('schedules')
                ->has('positions')
                ->has('compensationTypes')
                ->has('employees'));
    }

    public function test_edit_excludes_self_from_supervisor_options(): void
    {
        $this->actingAsAdmin();
        $position = Position::factory()->create();
        $other = Position::factory()->create();

        $this->get(route('positions.edit', $position))
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($position, $other) {
                $ids = collect($page->toArray()['props']['positions'])->pluck('id');
                $this->assertFalse($ids->contains($position->id));
                $this->assertTrue($ids->contains($other->id));
            });
    }

    public function test_rrhh_cannot_open_edit_form(): void
    {
        $this->actingAsRrhh();
        $position = Position::factory()->create();
        $this->get(route('positions.edit', $position))->assertForbidden();
    }

    // --------------------------------------------------------------- update

    public function test_admin_can_update_position(): void
    {
        $this->actingAsAdmin();
        $position = Position::factory()->create(['name' => 'Viejo Puesto']);

        $this->put(route('positions.update', $position), [
            'name' => 'Puesto Nuevo',
            'code' => $position->code,
            'position_type' => 'gerencial',
        ])
            ->assertRedirect(route('positions.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('positions', [
            'id' => $position->id,
            'name' => 'Puesto Nuevo',
            'position_type' => 'gerencial',
        ]);
    }

    public function test_update_rejects_self_as_supervisor(): void
    {
        $this->actingAsAdmin();
        $position = Position::factory()->create();

        $this->from(route('positions.edit', $position))
            ->put(route('positions.update', $position), [
                'name' => $position->name,
                'code' => $position->code,
                'position_type' => 'operativo',
                'supervisor_position_id' => $position->id,
            ])
            ->assertSessionHasErrors(['supervisor_position_id']);
    }

    public function test_update_rejects_code_used_by_another_position(): void
    {
        $this->actingAsAdmin();
        Position::factory()->create(['code' => 'TAKEN-POS']);
        $position = Position::factory()->create(['code' => 'MINE-POS']);

        $this->from(route('positions.edit', $position))
            ->put(route('positions.update', $position), [
                'name' => 'Conflicto',
                'code' => 'TAKEN-POS',
                'position_type' => 'operativo',
            ])
            ->assertSessionHasErrors(['code']);
    }

    public function test_update_detaches_compensation_types_when_none_sent(): void
    {
        $this->actingAsAdmin();
        $comp = CompensationType::factory()->create();
        $position = Position::factory()->create();
        $position->compensationTypes()->sync([$comp->id => []]);
        $this->assertTrue($position->compensationTypes()->exists());

        $this->put(route('positions.update', $position), [
            'name' => $position->name,
            'code' => $position->code,
            'position_type' => 'operativo',
        ])->assertRedirect(route('positions.index'));

        $this->assertFalse($position->fresh()->compensationTypes()->exists());
    }

    public function test_rrhh_cannot_update_position(): void
    {
        $this->actingAsRrhh();
        $position = Position::factory()->create();
        $this->put(route('positions.update', $position), [
            'name' => 'Hack',
            'code' => $position->code,
            'position_type' => 'operativo',
        ])->assertForbidden();
    }

    // -------------------------------------------------------------- destroy

    public function test_admin_can_soft_deactivate_empty_position(): void
    {
        $this->actingAsAdmin();
        $position = Position::factory()->create(['is_active' => true]);

        $this->delete(route('positions.destroy', $position))
            ->assertRedirect(route('positions.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('positions', [
            'id' => $position->id,
            'is_active' => false,
        ]);
    }

    public function test_destroy_is_blocked_when_active_employees_exist(): void
    {
        $this->actingAsAdmin();
        $position = Position::factory()->create(['is_active' => true]);
        Employee::factory()->create(['position_id' => $position->id, 'status' => 'active']);

        $this->from(route('positions.index'))
            ->delete(route('positions.destroy', $position))
            ->assertRedirect(route('positions.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('positions', [
            'id' => $position->id,
            'is_active' => true,
        ]);
    }

    public function test_rrhh_cannot_destroy_position(): void
    {
        $this->actingAsRrhh();
        $position = Position::factory()->create(['is_active' => true]);
        $this->delete(route('positions.destroy', $position))->assertForbidden();

        $this->assertDatabaseHas('positions', [
            'id' => $position->id,
            'is_active' => true,
        ]);
    }

    // ----------------------------------------------- strengthened coverage

    public function test_index_search_filters_by_name_or_code(): void
    {
        $this->actingAsAdmin();
        $match = Position::factory()->create(['name' => 'Supervisor Piso', 'code' => 'SUP-PISO']);
        Position::factory()->create(['name' => 'Almacenista', 'code' => 'ALM-01']);

        $this->get(route('positions.index', ['search' => 'Piso']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('positions.data', 1)
                ->where('positions.data.0.id', $match->id)
                ->where('filters.search', 'Piso'));

        $this->get(route('positions.index', ['search' => 'SUP-PISO']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('positions.data', 1));
    }

    public function test_index_rows_expose_employees_count_and_relations(): void
    {
        $this->actingAsAdmin();
        $position = Position::factory()->create();

        $this->get(route('positions.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('positions.data.0', fn (Assert $row) => $row
                    ->where('id', $position->id)
                    ->has('employees_count')
                    ->etc()));
    }

    public function test_store_persists_pivot_percentage_value(): void
    {
        $this->actingAsAdmin();
        $comp = CompensationType::factory()->create();

        $this->post(route('positions.store'), [
            'name' => 'Pivot Pct',
            'code' => 'PIV-01',
            'position_type' => 'operativo',
            'compensation_type_ids' => [$comp->id],
            'compensation_type_percentages' => [$comp->id => 25],
            'compensation_type_fixed_amounts' => [$comp->id => null],
        ])->assertRedirect(route('positions.index'));

        $position = Position::where('code', 'PIV-01')->firstOrFail();
        $pivot = $position->compensationTypes()->where('compensation_type_id', $comp->id)->first()->pivot;
        $this->assertEquals(25, (int) $pivot->default_percentage);
    }

    public function test_store_rejects_nonexistent_schedule_supervisor_and_compensation(): void
    {
        $this->actingAsAdmin();

        $this->from(route('positions.create'))
            ->post(route('positions.store'), [
                'name' => 'Refs Malas',
                'code' => 'REFS-01',
                'position_type' => 'operativo',
                'default_schedule_id' => 999999,
                'supervisor_position_id' => 999999,
                'compensation_type_ids' => [999999],
            ])
            ->assertSessionHasErrors([
                'default_schedule_id',
                'supervisor_position_id',
                'compensation_type_ids.0',
            ]);
    }

    public function test_store_rejects_holiday_rate_below_one(): void
    {
        $this->actingAsAdmin();

        $this->from(route('positions.create'))
            ->post(route('positions.store'), [
                'name' => 'Bajo Holiday',
                'code' => 'HOL-01',
                'position_type' => 'operativo',
                'default_holiday_rate' => 0.5,
            ])
            ->assertSessionHasErrors(['default_holiday_rate']);
    }

    public function test_store_rejects_negative_base_hourly_rate(): void
    {
        $this->actingAsAdmin();

        $this->from(route('positions.create'))
            ->post(route('positions.store'), [
                'name' => 'Negativo',
                'code' => 'NEG-01',
                'position_type' => 'operativo',
                'base_hourly_rate' => -5,
            ])
            ->assertSessionHasErrors(['base_hourly_rate']);
    }

    public function test_update_rejects_nonexistent_supervisor_position(): void
    {
        $this->actingAsAdmin();
        $position = Position::factory()->create();

        $this->from(route('positions.edit', $position))
            ->put(route('positions.update', $position), [
                'name' => $position->name,
                'code' => $position->code,
                'position_type' => 'operativo',
                'supervisor_position_id' => 999999,
            ])
            ->assertSessionHasErrors(['supervisor_position_id']);
    }

    public function test_update_aborts_on_supervisor_cycle(): void
    {
        $this->actingAsAdmin();
        // B's supervisor is A. Editing A to point at B closes the cycle.
        $a = Position::factory()->create();
        $b = Position::factory()->create(['supervisor_position_id' => $a->id]);

        $this->put(route('positions.update', $a), [
            'name' => $a->name,
            'code' => $a->code,
            'position_type' => 'operativo',
            'supervisor_position_id' => $b->id,
        ])->assertStatus(422);

        // No cycle persisted.
        $this->assertDatabaseHas('positions', [
            'id' => $a->id,
            'supervisor_position_id' => null,
        ]);
    }

    public function test_update_allows_valid_supervisor_chain(): void
    {
        $this->actingAsAdmin();
        $boss = Position::factory()->create();
        $position = Position::factory()->create();

        $this->put(route('positions.update', $position), [
            'name' => $position->name,
            'code' => $position->code,
            'position_type' => 'operativo',
            'supervisor_position_id' => $boss->id,
        ])->assertRedirect(route('positions.index'));

        $this->assertDatabaseHas('positions', [
            'id' => $position->id,
            'supervisor_position_id' => $boss->id,
        ]);
    }

    public function test_supervisor_and_employee_cannot_store_or_destroy(): void
    {
        $position = Position::factory()->create(['is_active' => true]);

        $this->actingAsSupervisor();
        $this->post(route('positions.store'), ['name' => 'S', 'code' => 'SUP-POS', 'position_type' => 'operativo'])
            ->assertForbidden();
        $this->delete(route('positions.destroy', $position))->assertForbidden();

        $this->actingAsEmployee();
        $this->get(route('positions.show', $position))->assertForbidden();
        $this->post(route('positions.store'), ['name' => 'E', 'code' => 'EMP-POS', 'position_type' => 'operativo'])
            ->assertForbidden();

        $this->assertDatabaseMissing('positions', ['code' => 'SUP-POS']);
        $this->assertDatabaseMissing('positions', ['code' => 'EMP-POS']);
    }

    public function test_guest_is_redirected_from_all_write_actions(): void
    {
        $position = Position::factory()->create();

        $this->get(route('positions.create'))->assertRedirect(route('login'));
        $this->get(route('positions.show', $position))->assertRedirect(route('login'));
        $this->get(route('positions.edit', $position))->assertRedirect(route('login'));
        $this->post(route('positions.store'), [])->assertRedirect(route('login'));
        $this->put(route('positions.update', $position), [])->assertRedirect(route('login'));
        $this->delete(route('positions.destroy', $position))->assertRedirect(route('login'));
    }

    public function test_show_loads_position_relations(): void
    {
        $this->actingAsAdmin();
        $position = Position::factory()->create();

        $this->get(route('positions.show', $position))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Positions/Show')
                ->has('position.compensation_types')
                ->has('position.employees')
                ->where('position.employees_count', 0));
    }

    public function test_create_props_are_arrays_consumed_by_vue(): void
    {
        $this->actingAsAdmin();
        Department::factory()->create();
        Schedule::factory()->create();
        CompensationType::factory()->create();

        $this->get(route('positions.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Positions/Create')
                ->has('departments.0')
                ->has('schedules.0')
                ->has('compensationTypes.0'));
    }
}
