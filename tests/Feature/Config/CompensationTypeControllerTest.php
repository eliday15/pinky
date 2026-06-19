<?php

namespace Tests\Feature\Config;

use App\Models\CompensationType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\FeatureTestCase;

/**
 * Feature tests for CompensationTypeController.
 *
 * Covers index/create/store/edit/update/destroy with RBAC (only admin holds
 * compensation_types.manage), validation, Inertia prop contract, and DB effects
 * (including the soft-deactivate destroy behavior).
 */
class CompensationTypeControllerTest extends FeatureTestCase
{
    // ----------------------------------------------------------------- index

    public function test_admin_sees_index_with_expected_inertia_props(): void
    {
        $this->actingAsAdmin();
        CompensationType::factory()->count(3)->create();

        $this->get(route('compensation-types.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('CompensationTypes/Index')
                ->has('compensationTypes')
                ->has('compensationTypes.data')
                ->has('filters'));
    }

    public function test_index_only_shows_active_by_default(): void
    {
        $this->actingAsAdmin();
        // Migrations seed default rows; isolate controller filtering logic.
        CompensationType::query()->delete();
        CompensationType::factory()->create(['name' => 'Active One']);
        CompensationType::factory()->inactive()->create(['name' => 'Inactive One']);

        $this->get(route('compensation-types.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('compensationTypes.data', 1)
                ->where('compensationTypes.data.0.name', 'Active One'));
    }

    public function test_index_status_all_includes_inactive(): void
    {
        $this->actingAsAdmin();
        CompensationType::query()->delete();
        CompensationType::factory()->create();
        CompensationType::factory()->inactive()->create();

        $this->get(route('compensation-types.index', ['status' => 'all']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('compensationTypes.data', 2));
    }

    public function test_index_search_filters_by_code(): void
    {
        $this->actingAsAdmin();
        CompensationType::factory()->create(['name' => 'Bono Nocturno', 'code' => 'BONO-NOC']);
        CompensationType::factory()->create(['name' => 'Otro', 'code' => 'OTRO-1']);

        $this->get(route('compensation-types.index', ['search' => 'BONO-NOC']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('compensationTypes.data', 1)
                ->where('compensationTypes.data.0.code', 'BONO-NOC')
                ->where('filters.search', 'BONO-NOC'));
    }

    public function test_index_search_filters_by_name(): void
    {
        $this->actingAsAdmin();
        CompensationType::query()->delete();
        CompensationType::factory()->create(['name' => 'Bono Nocturno', 'code' => 'XXX-1']);
        CompensationType::factory()->create(['name' => 'Vale Despensa', 'code' => 'YYY-1']);

        // Exercises the orWhere('name') branch of the search closure.
        $this->get(route('compensation-types.index', ['search' => 'Nocturno']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('compensationTypes.data', 1)
                ->where('compensationTypes.data.0.name', 'Bono Nocturno'));
    }

    public function test_index_status_active_excludes_inactive(): void
    {
        $this->actingAsAdmin();
        CompensationType::query()->delete();
        CompensationType::factory()->create(['name' => 'Solo Activo']);
        CompensationType::factory()->inactive()->create(['name' => 'Oculto']);

        $this->get(route('compensation-types.index', ['status' => 'active']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('compensationTypes.data', 1)
                ->where('compensationTypes.data.0.name', 'Solo Activo')
                ->where('filters.status', 'active'));
    }

    public function test_rrhh_cannot_view_index(): void
    {
        $this->actingAsRrhh();
        $this->get(route('compensation-types.index'))->assertForbidden();
    }

    public function test_supervisor_cannot_view_index(): void
    {
        $this->actingAsSupervisor();
        $this->get(route('compensation-types.index'))->assertForbidden();
    }

    public function test_employee_cannot_view_index(): void
    {
        $this->actingAsEmployee();
        $this->get(route('compensation-types.index'))->assertForbidden();
    }

    public function test_guest_redirected_to_login_from_index(): void
    {
        $this->get(route('compensation-types.index'))->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------- create

    public function test_admin_sees_create_with_expected_inertia_props(): void
    {
        $this->actingAsAdmin();

        $this->get(route('compensation-types.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('CompensationTypes/Create')
                ->has('positions')
                ->has('departments')
                ->has('employees'));
    }

    public function test_create_exposes_only_active_related_records(): void
    {
        $this->actingAsAdmin();
        // Distinct, searchable names so we can assert inclusion/exclusion without
        // depending on exact counts (PositionFactory auto-creates Departments).
        Position::factory()->create(['name' => 'PosActivaUnica']);
        Position::factory()->inactive()->create(['name' => 'PosInactivaUnica']);
        Department::factory()->create(['name' => 'DepActivoUnico']);
        Department::factory()->inactive()->create(['name' => 'DepInactivoUnico']);

        // create() pulls Position::active()/Department::active() only.
        $response = $this->get(route('compensation-types.create'))->assertOk();

        $page = $response->viewData('page');
        $positionNames = collect($page['props']['positions'])->pluck('name');
        $departmentNames = collect($page['props']['departments'])->pluck('name');

        $this->assertTrue($positionNames->contains('PosActivaUnica'));
        $this->assertFalse($positionNames->contains('PosInactivaUnica'));
        $this->assertTrue($departmentNames->contains('DepActivoUnico'));
        $this->assertFalse($departmentNames->contains('DepInactivoUnico'));
    }

    public function test_rrhh_cannot_view_create(): void
    {
        $this->actingAsRrhh();
        $this->get(route('compensation-types.create'))->assertForbidden();
    }

    public function test_supervisor_cannot_view_create(): void
    {
        $this->actingAsSupervisor();
        $this->get(route('compensation-types.create'))->assertForbidden();
    }

    public function test_employee_cannot_view_create(): void
    {
        $this->actingAsEmployee();
        $this->get(route('compensation-types.create'))->assertForbidden();
    }

    public function test_guest_redirected_to_login_from_create(): void
    {
        $this->get(route('compensation-types.create'))->assertRedirect(route('login'));
    }

    // ----------------------------------------------------------------- store

    public function test_admin_can_store_percentage_type(): void
    {
        $this->actingAsAdmin();

        $this->post(route('compensation-types.store'), [
            'name' => 'Bono Porcentaje',
            'code' => 'BONO-PCT',
            'calculation_type' => 'percentage',
            'percentage_value' => 25.50,
            'application_mode' => 'per_hour',
            'priority' => 0,
        ])
            ->assertRedirect(route('compensation-types.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('compensation_types', [
            'code' => 'BONO-PCT',
            'calculation_type' => 'percentage',
            'percentage_value' => 25.50,
        ]);
    }

    public function test_admin_can_store_fixed_type(): void
    {
        $this->actingAsAdmin();

        $this->post(route('compensation-types.store'), [
            'name' => 'Bono Fijo',
            'code' => 'BONO-FIX',
            'calculation_type' => 'fixed',
            'fixed_amount' => 150.00,
            'application_mode' => 'one_time',
            'priority' => 1,
        ])
            ->assertRedirect(route('compensation-types.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('compensation_types', [
            'code' => 'BONO-FIX',
            'calculation_type' => 'fixed',
            'fixed_amount' => 150.00,
        ]);
    }

    public function test_payment_period_defaults_to_monthly_and_can_be_set_weekly(): void
    {
        $this->actingAsAdmin();

        // Sin payment_period: cae en el default 'monthly'.
        $this->post(route('compensation-types.store'), [
            'name' => 'Default Mensual',
            'code' => 'PP-DEF',
            'calculation_type' => 'fixed',
            'fixed_amount' => 10.00,
            'application_mode' => 'one_time',
            'priority' => 0,
        ])->assertRedirect(route('compensation-types.index'));

        $this->assertDatabaseHas('compensation_types', [
            'code' => 'PP-DEF',
            'payment_period' => 'monthly',
        ]);

        // Explícito 'weekly' se persiste.
        $this->post(route('compensation-types.store'), [
            'name' => 'Semanal',
            'code' => 'PP-WK',
            'calculation_type' => 'fixed',
            'fixed_amount' => 10.00,
            'application_mode' => 'one_time',
            'priority' => 0,
            'payment_period' => 'weekly',
        ])->assertRedirect(route('compensation-types.index'));

        $this->assertDatabaseHas('compensation_types', [
            'code' => 'PP-WK',
            'payment_period' => 'weekly',
        ]);
    }

    public function test_store_rejects_invalid_payment_period(): void
    {
        $this->actingAsAdmin();

        $this->from(route('compensation-types.create'))
            ->post(route('compensation-types.store'), [
                'name' => 'Mal',
                'code' => 'PP-BAD',
                'calculation_type' => 'fixed',
                'fixed_amount' => 10.00,
                'application_mode' => 'one_time',
                'priority' => 0,
                'payment_period' => 'daily',
            ])
            ->assertSessionHasErrors(['payment_period']);
    }

    public function test_store_syncs_positions_departments_and_employees_with_pivots(): void
    {
        $this->actingAsAdmin();
        $position = Position::factory()->create();
        $department = Department::factory()->create();
        $employee = Employee::factory()->create();

        $this->post(route('compensation-types.store'), [
            'name' => 'Bono Asignado',
            'code' => 'BONO-SYNC',
            'calculation_type' => 'percentage',
            'percentage_value' => 30,
            'application_mode' => 'per_hour',
            'priority' => 0,
            'position_ids' => [$position->id],
            'position_percentages' => [$position->id => 12.5],
            'department_ids' => [$department->id],
            'department_fixed_amounts' => [$department->id => 75],
            'employee_ids' => [$employee->id],
            'employee_percentages' => [$employee->id => 40],
        ])
            ->assertRedirect(route('compensation-types.index'))
            ->assertSessionHas('success');

        $type = CompensationType::where('code', 'BONO-SYNC')->firstOrFail();
        $this->assertTrue($type->positions->contains($position->id));
        $this->assertTrue($type->departments->contains($department->id));
        $this->assertTrue($type->employees->contains($employee->id));

        // Pivot overrides persisted through syncEmployees()/syncPositions().
        $this->assertDatabaseHas('employee_compensation_type', [
            'compensation_type_id' => $type->id,
            'employee_id' => $employee->id,
            'custom_percentage' => 40,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('position_compensation_type', [
            'compensation_type_id' => $type->id,
            'position_id' => $position->id,
            'default_percentage' => 12.5,
        ]);
    }

    public function test_store_rejects_nonexistent_position_id(): void
    {
        $this->actingAsAdmin();

        $this->from(route('compensation-types.create'))
            ->post(route('compensation-types.store'), [
                'name' => 'X',
                'code' => 'BAD-POS',
                'calculation_type' => 'fixed',
                'fixed_amount' => 10,
                'application_mode' => 'one_time',
                'position_ids' => [999999],
            ])
            ->assertSessionHasErrors(['position_ids.0']);
    }

    public function test_store_rejects_percentage_above_max(): void
    {
        $this->actingAsAdmin();

        $this->from(route('compensation-types.create'))
            ->post(route('compensation-types.store'), [
                'name' => 'X',
                'code' => 'PCT-MAX',
                'calculation_type' => 'percentage',
                'percentage_value' => 1000, // > 999.99
                'application_mode' => 'per_hour',
            ])
            ->assertSessionHasErrors(['percentage_value']);
    }

    public function test_store_rejects_zero_fixed_amount(): void
    {
        $this->actingAsAdmin();

        $this->from(route('compensation-types.create'))
            ->post(route('compensation-types.store'), [
                'name' => 'X',
                'code' => 'FIX-ZERO',
                'calculation_type' => 'fixed',
                'fixed_amount' => 0, // < min 0.01
                'application_mode' => 'one_time',
            ])
            ->assertSessionHasErrors(['fixed_amount']);
    }

    public function test_store_rejects_negative_priority(): void
    {
        $this->actingAsAdmin();

        $this->from(route('compensation-types.create'))
            ->post(route('compensation-types.store'), [
                'name' => 'X',
                'code' => 'PRIO-NEG',
                'calculation_type' => 'fixed',
                'fixed_amount' => 10,
                'application_mode' => 'one_time',
                'priority' => -1,
            ])
            ->assertSessionHasErrors(['priority']);
    }

    public function test_store_rejects_invalid_authorization_type_enum(): void
    {
        $this->actingAsAdmin();

        $this->from(route('compensation-types.create'))
            ->post(route('compensation-types.store'), [
                'name' => 'X',
                'code' => 'AUTH-BAD',
                'calculation_type' => 'fixed',
                'fixed_amount' => 10,
                'application_mode' => 'one_time',
                'authorization_type' => 'banana',
            ])
            ->assertSessionHasErrors(['authorization_type']);
    }

    public function test_store_requires_name_and_code(): void
    {
        $this->actingAsAdmin();

        $this->from(route('compensation-types.create'))
            ->post(route('compensation-types.store'), [])
            ->assertRedirect(route('compensation-types.create'))
            ->assertSessionHasErrors(['name', 'code', 'calculation_type', 'application_mode']);
    }

    public function test_store_percentage_requires_percentage_value(): void
    {
        $this->actingAsAdmin();

        $this->from(route('compensation-types.create'))
            ->post(route('compensation-types.store'), [
                'name' => 'X',
                'code' => 'X-1',
                'calculation_type' => 'percentage',
                'application_mode' => 'per_hour',
            ])
            ->assertSessionHasErrors(['percentage_value']);
    }

    public function test_store_fixed_requires_fixed_amount(): void
    {
        $this->actingAsAdmin();

        $this->from(route('compensation-types.create'))
            ->post(route('compensation-types.store'), [
                'name' => 'X',
                'code' => 'X-2',
                'calculation_type' => 'fixed',
                'application_mode' => 'one_time',
            ])
            ->assertSessionHasErrors(['fixed_amount']);
    }

    public function test_store_rejects_duplicate_code(): void
    {
        $this->actingAsAdmin();
        CompensationType::factory()->create(['code' => 'DUP-1']);

        $this->from(route('compensation-types.create'))
            ->post(route('compensation-types.store'), [
                'name' => 'Dup',
                'code' => 'DUP-1',
                'calculation_type' => 'fixed',
                'fixed_amount' => 10,
                'application_mode' => 'one_time',
            ])
            ->assertSessionHasErrors(['code']);
    }

    public function test_store_rejects_invalid_calculation_type_enum(): void
    {
        $this->actingAsAdmin();

        $this->from(route('compensation-types.create'))
            ->post(route('compensation-types.store'), [
                'name' => 'X',
                'code' => 'X-3',
                'calculation_type' => 'banana',
                'application_mode' => 'per_hour',
            ])
            ->assertSessionHasErrors(['calculation_type']);
    }

    public function test_store_rejects_invalid_application_mode_enum(): void
    {
        $this->actingAsAdmin();

        $this->from(route('compensation-types.create'))
            ->post(route('compensation-types.store'), [
                'name' => 'X',
                'code' => 'X-4',
                'calculation_type' => 'fixed',
                'fixed_amount' => 10,
                'application_mode' => 'per_year',
            ])
            ->assertSessionHasErrors(['application_mode']);
    }

    public function test_rrhh_cannot_store(): void
    {
        $this->actingAsRrhh();

        $this->post(route('compensation-types.store'), [
            'name' => 'Bono',
            'code' => 'BONO-RRHH',
            'calculation_type' => 'fixed',
            'fixed_amount' => 10,
            'application_mode' => 'one_time',
        ])->assertForbidden();

        $this->assertDatabaseMissing('compensation_types', ['code' => 'BONO-RRHH']);
    }

    public function test_supervisor_cannot_store(): void
    {
        $this->actingAsSupervisor();

        $this->post(route('compensation-types.store'), [
            'name' => 'Bono',
            'code' => 'BONO-SUP',
            'calculation_type' => 'fixed',
            'fixed_amount' => 10,
            'application_mode' => 'one_time',
        ])->assertForbidden();

        $this->assertDatabaseMissing('compensation_types', ['code' => 'BONO-SUP']);
    }

    public function test_guest_cannot_store(): void
    {
        $this->post(route('compensation-types.store'), [])
            ->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------ edit

    public function test_admin_sees_edit_with_expected_inertia_props(): void
    {
        $this->actingAsAdmin();
        $type = CompensationType::factory()->create();

        $this->get(route('compensation-types.edit', $type))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('CompensationTypes/Edit')
                ->has('compensationType')
                ->where('compensationType.id', $type->id)
                ->has('positions')
                ->has('departments')
                ->has('employees'));
    }

    public function test_supervisor_cannot_view_edit(): void
    {
        $this->actingAsSupervisor();
        $type = CompensationType::factory()->create();
        $this->get(route('compensation-types.edit', $type))->assertForbidden();
    }

    public function test_rrhh_cannot_view_edit(): void
    {
        $this->actingAsRrhh();
        $type = CompensationType::factory()->create();
        $this->get(route('compensation-types.edit', $type))->assertForbidden();
    }

    public function test_guest_redirected_to_login_from_edit(): void
    {
        $type = CompensationType::factory()->create();
        $this->get(route('compensation-types.edit', $type))->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------- update

    public function test_admin_can_update_type(): void
    {
        $this->actingAsAdmin();
        $type = CompensationType::factory()->percentage(10)->create(['code' => 'UPD-1']);

        $this->put(route('compensation-types.update', $type), [
            'name' => 'Actualizado',
            'code' => 'UPD-1',
            'calculation_type' => 'percentage',
            'percentage_value' => 99.99,
            'application_mode' => 'per_day',
            'priority' => 5,
        ])
            ->assertRedirect(route('compensation-types.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('compensation_types', [
            'id' => $type->id,
            'name' => 'Actualizado',
            'percentage_value' => 99.99,
            'application_mode' => 'per_day',
            'priority' => 5,
        ]);
    }

    public function test_update_allows_keeping_same_code(): void
    {
        $this->actingAsAdmin();
        $type = CompensationType::factory()->create(['code' => 'KEEP-1']);

        $this->put(route('compensation-types.update', $type), [
            'name' => 'Mismo Code',
            'code' => 'KEEP-1',
            'calculation_type' => 'fixed',
            'fixed_amount' => 50,
            'application_mode' => 'one_time',
        ])
            ->assertRedirect(route('compensation-types.index'))
            ->assertSessionHas('success');
    }

    public function test_update_rejects_code_taken_by_another(): void
    {
        $this->actingAsAdmin();
        CompensationType::factory()->create(['code' => 'TAKEN-1']);
        $type = CompensationType::factory()->create(['code' => 'MINE-1']);

        $this->from(route('compensation-types.edit', $type))
            ->put(route('compensation-types.update', $type), [
                'name' => 'X',
                'code' => 'TAKEN-1',
                'calculation_type' => 'fixed',
                'fixed_amount' => 50,
                'application_mode' => 'one_time',
            ])
            ->assertSessionHasErrors(['code']);
    }

    public function test_update_detaches_employees_when_omitted(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->create();
        $type = CompensationType::factory()->create(['code' => 'DET-EMP']);
        $type->employees()->sync([$employee->id => ['is_active' => true]]);
        $this->assertCount(1, $type->fresh()->employees);

        // Submitting empty employee_ids triggers sync([]) → full detach.
        $this->put(route('compensation-types.update', $type), [
            'name' => $type->name,
            'code' => 'DET-EMP',
            'calculation_type' => 'percentage',
            'percentage_value' => 10,
            'application_mode' => 'per_hour',
            'employee_ids' => [],
        ])->assertRedirect(route('compensation-types.index'));

        $this->assertCount(0, $type->fresh()->employees);
    }

    public function test_rrhh_cannot_update(): void
    {
        $this->actingAsRrhh();
        $type = CompensationType::factory()->create();

        $this->put(route('compensation-types.update', $type), [
            'name' => 'X',
            'code' => $type->code,
            'calculation_type' => 'fixed',
            'fixed_amount' => 10,
            'application_mode' => 'one_time',
        ])->assertForbidden();
    }

    public function test_supervisor_cannot_update(): void
    {
        $this->actingAsSupervisor();
        $type = CompensationType::factory()->create();

        $this->put(route('compensation-types.update', $type), [
            'name' => 'X',
            'code' => $type->code,
            'calculation_type' => 'fixed',
            'fixed_amount' => 10,
            'application_mode' => 'one_time',
        ])->assertForbidden();
    }

    public function test_guest_cannot_update(): void
    {
        $type = CompensationType::factory()->create();
        $this->put(route('compensation-types.update', $type), [])
            ->assertRedirect(route('login'));
    }

    // --------------------------------------------------------------- destroy

    public function test_admin_destroy_soft_deactivates(): void
    {
        $this->actingAsAdmin();
        $type = CompensationType::factory()->create(['is_active' => true]);

        $this->delete(route('compensation-types.destroy', $type))
            ->assertRedirect(route('compensation-types.index'))
            ->assertSessionHas('success');

        // destroy() only flips is_active to false; row still exists.
        $this->assertDatabaseHas('compensation_types', [
            'id' => $type->id,
            'is_active' => false,
        ]);
    }

    public function test_rrhh_cannot_destroy(): void
    {
        $this->actingAsRrhh();
        $type = CompensationType::factory()->create(['is_active' => true]);

        $this->delete(route('compensation-types.destroy', $type))->assertForbidden();

        $this->assertDatabaseHas('compensation_types', [
            'id' => $type->id,
            'is_active' => true,
        ]);
    }

    public function test_supervisor_cannot_destroy(): void
    {
        $this->actingAsSupervisor();
        $type = CompensationType::factory()->create(['is_active' => true]);

        $this->delete(route('compensation-types.destroy', $type))->assertForbidden();

        $this->assertDatabaseHas('compensation_types', [
            'id' => $type->id,
            'is_active' => true,
        ]);
    }

    public function test_employee_cannot_destroy(): void
    {
        $this->actingAsEmployee();
        $type = CompensationType::factory()->create(['is_active' => true]);

        $this->delete(route('compensation-types.destroy', $type))->assertForbidden();
    }

    public function test_guest_cannot_destroy(): void
    {
        $type = CompensationType::factory()->create();
        $this->delete(route('compensation-types.destroy', $type))
            ->assertRedirect(route('login'));
    }
}
