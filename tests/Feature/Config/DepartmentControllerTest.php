<?php

namespace Tests\Feature\Config;

use App\Models\Department;
use App\Models\Employee;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\FeatureTestCase;

/**
 * Front-to-back contract tests for DepartmentController (Route::resource,
 * gated by departments.manage -> admin only). Covers RBAC trio, Inertia
 * props consumed by the Vue pages, validation rules, and DB effects
 * (destroy soft-deactivates and is blocked by active employees).
 */
class DepartmentControllerTest extends FeatureTestCase
{
    // ---------------------------------------------------------------- index

    public function test_admin_sees_department_index_with_expected_props(): void
    {
        $this->actingAsAdmin();
        Department::factory()->count(3)->create();

        $this->get(route('departments.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Departments/Index')
                ->has('departments')
                ->has('departments.data')
                ->has('filters'));
    }

    public function test_index_defaults_to_active_only_and_search_filters(): void
    {
        $this->actingAsAdmin();
        $active = Department::factory()->create(['name' => 'Ventas Activo']);
        Department::factory()->inactive()->create(['name' => 'Ventas Inactivo']);

        // Default (no status param) -> only active rows.
        $this->get(route('departments.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('departments.data', 1)
                ->where('departments.data.0.id', $active->id));

        // status=all -> both rows.
        $this->get(route('departments.index', ['status' => 'all']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('departments.data', 2));
    }

    public function test_rrhh_cannot_view_departments(): void
    {
        $this->actingAsRrhh();
        $this->get(route('departments.index'))->assertForbidden();
    }

    public function test_supervisor_cannot_view_departments(): void
    {
        $this->actingAsSupervisor();
        $this->get(route('departments.index'))->assertForbidden();
    }

    public function test_employee_cannot_view_departments(): void
    {
        $this->actingAsEmployee();
        $this->get(route('departments.index'))->assertForbidden();
    }

    public function test_guest_is_redirected_to_login_from_index(): void
    {
        $this->get(route('departments.index'))->assertRedirect(route('login'));
    }

    // --------------------------------------------------------------- create

    public function test_admin_sees_create_form(): void
    {
        $this->actingAsAdmin();
        $this->get(route('departments.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Departments/Create'));
    }

    public function test_rrhh_cannot_open_create_form(): void
    {
        $this->actingAsRrhh();
        $this->get(route('departments.create'))->assertForbidden();
    }

    // ---------------------------------------------------------------- store

    public function test_admin_can_store_department(): void
    {
        $this->actingAsAdmin();

        $this->post(route('departments.store'), [
            'name' => 'Recursos Humanos',
            'code' => 'RH-01',
            'description' => 'Departamento de RH',
            'default_break_minutes' => 60,
        ])
            ->assertRedirect(route('departments.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('departments', [
            'name' => 'Recursos Humanos',
            'code' => 'RH-01',
            'is_active' => true,
        ]);
    }

    public function test_store_requires_name_and_code(): void
    {
        $this->actingAsAdmin();

        $this->from(route('departments.create'))
            ->post(route('departments.store'), [])
            ->assertSessionHasErrors(['name', 'code'])
            ->assertRedirect(route('departments.create'));
    }

    public function test_store_rejects_duplicate_code(): void
    {
        $this->actingAsAdmin();
        Department::factory()->create(['code' => 'DUP-01']);

        $this->from(route('departments.create'))
            ->post(route('departments.store'), [
                'name' => 'Otro',
                'code' => 'DUP-01',
            ])
            ->assertSessionHasErrors(['code']);
    }

    public function test_store_rejects_out_of_range_break_minutes(): void
    {
        $this->actingAsAdmin();

        $this->from(route('departments.create'))
            ->post(route('departments.store'), [
                'name' => 'Limites',
                'code' => 'LIM-01',
                'default_break_minutes' => 999,
            ])
            ->assertSessionHasErrors(['default_break_minutes']);
    }

    public function test_rrhh_cannot_store_department(): void
    {
        $this->actingAsRrhh();
        $this->post(route('departments.store'), [
            'name' => 'X',
            'code' => 'X-01',
        ])->assertForbidden();

        $this->assertDatabaseMissing('departments', ['code' => 'X-01']);
    }

    public function test_guest_cannot_store_department(): void
    {
        $this->post(route('departments.store'), [
            'name' => 'X',
            'code' => 'GUEST-01',
        ])->assertRedirect(route('login'));
    }

    // ----------------------------------------------------------------- show

    public function test_admin_sees_show_with_department_prop(): void
    {
        $this->actingAsAdmin();
        $department = Department::factory()->create();

        $this->get(route('departments.show', $department))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Departments/Show')
                ->has('department')
                ->where('department.id', $department->id));
    }

    public function test_rrhh_cannot_show_department(): void
    {
        $this->actingAsRrhh();
        $department = Department::factory()->create();
        $this->get(route('departments.show', $department))->assertForbidden();
    }

    // ----------------------------------------------------------------- edit

    public function test_admin_sees_edit_with_department_prop(): void
    {
        $this->actingAsAdmin();
        $department = Department::factory()->create();

        $this->get(route('departments.edit', $department))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Departments/Edit')
                ->has('department')
                ->where('department.id', $department->id));
    }

    public function test_rrhh_cannot_open_edit_form(): void
    {
        $this->actingAsRrhh();
        $department = Department::factory()->create();
        $this->get(route('departments.edit', $department))->assertForbidden();
    }

    // --------------------------------------------------------------- update

    public function test_admin_can_update_department(): void
    {
        $this->actingAsAdmin();
        $department = Department::factory()->create(['name' => 'Viejo']);

        $this->put(route('departments.update', $department), [
            'name' => 'Nuevo Nombre',
            'code' => $department->code,
            'default_break_minutes' => 30,
        ])
            ->assertRedirect(route('departments.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('departments', [
            'id' => $department->id,
            'name' => 'Nuevo Nombre',
            'default_break_minutes' => 30,
        ]);
    }

    public function test_update_allows_keeping_own_code(): void
    {
        $this->actingAsAdmin();
        $department = Department::factory()->create(['code' => 'KEEP-01']);

        $this->put(route('departments.update', $department), [
            'name' => 'Mismo Code',
            'code' => 'KEEP-01',
        ])->assertRedirect(route('departments.index'));
    }

    public function test_update_rejects_code_used_by_another_department(): void
    {
        $this->actingAsAdmin();
        Department::factory()->create(['code' => 'TAKEN-01']);
        $department = Department::factory()->create(['code' => 'MINE-01']);

        $this->from(route('departments.edit', $department))
            ->put(route('departments.update', $department), [
                'name' => 'Conflicto',
                'code' => 'TAKEN-01',
            ])
            ->assertSessionHasErrors(['code']);
    }

    public function test_rrhh_cannot_update_department(): void
    {
        $this->actingAsRrhh();
        $department = Department::factory()->create();
        $this->put(route('departments.update', $department), [
            'name' => 'Hack',
            'code' => $department->code,
        ])->assertForbidden();
    }

    // -------------------------------------------------------------- destroy

    public function test_admin_can_soft_deactivate_empty_department(): void
    {
        $this->actingAsAdmin();
        $department = Department::factory()->create(['is_active' => true]);

        $this->delete(route('departments.destroy', $department))
            ->assertRedirect(route('departments.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('departments', [
            'id' => $department->id,
            'is_active' => false,
        ]);
    }

    public function test_destroy_is_blocked_when_active_employees_exist(): void
    {
        $this->actingAsAdmin();
        $department = Department::factory()->create(['is_active' => true]);
        Employee::factory()->create(['department_id' => $department->id, 'status' => 'active']);

        $this->from(route('departments.index'))
            ->delete(route('departments.destroy', $department))
            ->assertRedirect(route('departments.index'))
            ->assertSessionHas('error');

        // Still active because deactivation was refused.
        $this->assertDatabaseHas('departments', [
            'id' => $department->id,
            'is_active' => true,
        ]);
    }

    public function test_rrhh_cannot_destroy_department(): void
    {
        $this->actingAsRrhh();
        $department = Department::factory()->create(['is_active' => true]);

        $this->delete(route('departments.destroy', $department))->assertForbidden();

        $this->assertDatabaseHas('departments', [
            'id' => $department->id,
            'is_active' => true,
        ]);
    }

    public function test_guest_cannot_destroy_department(): void
    {
        $department = Department::factory()->create();
        $this->delete(route('departments.destroy', $department))
            ->assertRedirect(route('login'));
    }

    // ----------------------------------------------- strengthened coverage

    public function test_index_search_filters_by_name_or_code(): void
    {
        $this->actingAsAdmin();
        $match = Department::factory()->create(['name' => 'Logistica', 'code' => 'LOG-99']);
        Department::factory()->create(['name' => 'Cocina', 'code' => 'COC-99']);

        // Search by name fragment.
        $this->get(route('departments.index', ['search' => 'Logist']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('departments.data', 1)
                ->where('departments.data.0.id', $match->id)
                ->where('filters.search', 'Logist'));

        // Search by code fragment hits the orWhere branch.
        $this->get(route('departments.index', ['search' => 'LOG-99']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('departments.data', 1)
                ->where('departments.data.0.id', $match->id));
    }

    public function test_index_status_inactive_returns_only_inactive(): void
    {
        $this->actingAsAdmin();
        Department::factory()->create(['name' => 'Activo Dept']);
        $inactive = Department::factory()->inactive()->create(['name' => 'Inactivo Dept']);

        $this->get(route('departments.index', ['status' => 'inactive']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('departments.data', 1)
                ->where('departments.data.0.id', $inactive->id));
    }

    public function test_index_rows_expose_withcount_aggregates(): void
    {
        $this->actingAsAdmin();
        $department = Department::factory()->create();

        $this->get(route('departments.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('departments.data.0', fn (Assert $row) => $row
                    ->where('id', $department->id)
                    ->has('employees_count')
                    ->has('positions_count')
                    ->etc()));
    }

    public function test_supervisor_and_employee_cannot_store_or_destroy(): void
    {
        $department = Department::factory()->create(['is_active' => true]);

        $this->actingAsSupervisor();
        $this->post(route('departments.store'), ['name' => 'S', 'code' => 'SUP-DEPT'])
            ->assertForbidden();
        $this->delete(route('departments.destroy', $department))->assertForbidden();

        $this->actingAsEmployee();
        $this->post(route('departments.store'), ['name' => 'E', 'code' => 'EMP-DEPT'])
            ->assertForbidden();
        $this->get(route('departments.show', $department))->assertForbidden();

        $this->assertDatabaseMissing('departments', ['code' => 'SUP-DEPT']);
        $this->assertDatabaseMissing('departments', ['code' => 'EMP-DEPT']);
    }

    public function test_guest_is_redirected_from_create_show_edit_and_update(): void
    {
        $department = Department::factory()->create();

        $this->get(route('departments.create'))->assertRedirect(route('login'));
        $this->get(route('departments.show', $department))->assertRedirect(route('login'));
        $this->get(route('departments.edit', $department))->assertRedirect(route('login'));
        $this->put(route('departments.update', $department), [
            'name' => 'X', 'code' => $department->code,
        ])->assertRedirect(route('login'));
    }

    public function test_store_rejects_name_longer_than_max(): void
    {
        $this->actingAsAdmin();

        $this->from(route('departments.create'))
            ->post(route('departments.store'), [
                'name' => str_repeat('a', 101),
                'code' => 'LONG-01',
            ])
            ->assertSessionHasErrors(['name']);
    }

    public function test_store_accepts_boundary_break_minutes(): void
    {
        $this->actingAsAdmin();

        $this->post(route('departments.store'), [
            'name' => 'Borde',
            'code' => 'EDGE-480',
            'default_break_minutes' => 480,
        ])->assertRedirect(route('departments.index'));

        $this->assertDatabaseHas('departments', ['code' => 'EDGE-480', 'default_break_minutes' => 480]);
    }

    public function test_show_loads_department_relations(): void
    {
        $this->actingAsAdmin();
        $department = Department::factory()->create();

        $this->get(route('departments.show', $department))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Departments/Show')
                ->has('department.employees')
                ->has('department.positions')
                ->where('department.employees_count', 0)
                ->where('department.positions_count', 0));
    }
}
