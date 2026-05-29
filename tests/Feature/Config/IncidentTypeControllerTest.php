<?php

namespace Tests\Feature\Config;

use App\Models\Department;
use App\Models\IncidentType;
use App\Models\Position;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\FeatureTestCase;

/**
 * Feature tests for IncidentTypeController.
 *
 * Covers index/create/store/edit/update/destroy with RBAC (only admin holds
 * incident_types.manage), validation, Inertia prop contract, position/department
 * sync, and the soft-deactivate destroy behavior.
 */
class IncidentTypeControllerTest extends FeatureTestCase
{
    // ----------------------------------------------------------------- index

    public function test_admin_sees_index_with_expected_inertia_props(): void
    {
        $this->actingAsAdmin();
        IncidentType::factory()->count(2)->create();

        $this->get(route('incident-types.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('IncidentTypes/Index')
                ->has('incidentTypes')
                ->has('incidentTypes.data')
                ->has('filters'));
    }

    public function test_index_only_shows_active_by_default(): void
    {
        $this->actingAsAdmin();
        // Migrations seed default rows; isolate controller filtering logic.
        IncidentType::query()->delete();
        IncidentType::factory()->create(['name' => 'Activo']);
        IncidentType::factory()->inactive()->create(['name' => 'Inactivo']);

        $this->get(route('incident-types.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('incidentTypes.data', 1)
                ->where('incidentTypes.data.0.name', 'Activo'));
    }

    public function test_index_status_all_includes_inactive(): void
    {
        $this->actingAsAdmin();
        IncidentType::query()->delete();
        IncidentType::factory()->create();
        IncidentType::factory()->inactive()->create();

        $this->get(route('incident-types.index', ['status' => 'all']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('incidentTypes.data', 2));
    }

    public function test_index_search_filters_by_code(): void
    {
        $this->actingAsAdmin();
        IncidentType::factory()->create(['name' => 'Vacaciones', 'code' => 'VAC-1']);
        IncidentType::factory()->create(['name' => 'Permiso', 'code' => 'PER-1']);

        $this->get(route('incident-types.index', ['search' => 'VAC-1']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('incidentTypes.data', 1)
                ->where('incidentTypes.data.0.code', 'VAC-1'));
    }

    public function test_index_search_filters_by_name(): void
    {
        $this->actingAsAdmin();
        IncidentType::query()->delete();
        IncidentType::factory()->create(['name' => 'Vacaciones', 'code' => 'AAA-1']);
        IncidentType::factory()->create(['name' => 'Permiso', 'code' => 'BBB-1']);

        // Exercises the orWhere('name') branch.
        $this->get(route('incident-types.index', ['search' => 'Vacac']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('incidentTypes.data', 1)
                ->where('incidentTypes.data.0.name', 'Vacaciones'));
    }

    public function test_index_status_active_excludes_inactive(): void
    {
        $this->actingAsAdmin();
        IncidentType::query()->delete();
        IncidentType::factory()->create(['name' => 'Activo']);
        IncidentType::factory()->inactive()->create(['name' => 'Oculto']);

        $this->get(route('incident-types.index', ['status' => 'active']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('incidentTypes.data', 1)
                ->where('incidentTypes.data.0.name', 'Activo')
                ->where('filters.status', 'active'));
    }

    public function test_rrhh_cannot_view_index(): void
    {
        $this->actingAsRrhh();
        $this->get(route('incident-types.index'))->assertForbidden();
    }

    public function test_supervisor_cannot_view_index(): void
    {
        $this->actingAsSupervisor();
        $this->get(route('incident-types.index'))->assertForbidden();
    }

    public function test_employee_cannot_view_index(): void
    {
        $this->actingAsEmployee();
        $this->get(route('incident-types.index'))->assertForbidden();
    }

    public function test_guest_redirected_to_login_from_index(): void
    {
        $this->get(route('incident-types.index'))->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------- create

    public function test_admin_sees_create_with_expected_inertia_props(): void
    {
        $this->actingAsAdmin();

        $this->get(route('incident-types.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('IncidentTypes/Create')
                ->has('positions')
                ->has('departments'));
    }

    public function test_create_exposes_only_active_related_records(): void
    {
        $this->actingAsAdmin();
        // Distinct names so we can assert inclusion/exclusion without depending
        // on exact counts (PositionFactory auto-creates Departments).
        Position::factory()->create(['name' => 'PosActivaUnica']);
        Position::factory()->inactive()->create(['name' => 'PosInactivaUnica']);
        Department::factory()->create(['name' => 'DepActivoUnico']);
        Department::factory()->inactive()->create(['name' => 'DepInactivoUnico']);

        $response = $this->get(route('incident-types.create'))->assertOk();

        $page = $response->viewData('page');
        $positionNames = collect($page['props']['positions'])->pluck('name');
        $departmentNames = collect($page['props']['departments'])->pluck('name');

        $this->assertTrue($positionNames->contains('PosActivaUnica'));
        $this->assertFalse($positionNames->contains('PosInactivaUnica'));
        $this->assertTrue($departmentNames->contains('DepActivoUnico'));
        $this->assertFalse($departmentNames->contains('DepInactivoUnico'));
    }

    public function test_supervisor_cannot_view_create(): void
    {
        $this->actingAsSupervisor();
        $this->get(route('incident-types.create'))->assertForbidden();
    }

    public function test_rrhh_cannot_view_create(): void
    {
        $this->actingAsRrhh();
        $this->get(route('incident-types.create'))->assertForbidden();
    }

    public function test_employee_cannot_view_create(): void
    {
        $this->actingAsEmployee();
        $this->get(route('incident-types.create'))->assertForbidden();
    }

    public function test_guest_redirected_to_login_from_create(): void
    {
        $this->get(route('incident-types.create'))->assertRedirect(route('login'));
    }

    // ----------------------------------------------------------------- store

    public function test_admin_can_store_incident_type(): void
    {
        $this->actingAsAdmin();

        $this->post(route('incident-types.store'), [
            'name' => 'Vacaciones',
            'code' => 'VAC-NEW',
            'category' => 'vacation',
            'is_paid' => true,
            'deducts_vacation' => true,
            'requires_approval' => true,
            'color' => '#FF0000',
            'priority' => 1,
        ])
            ->assertRedirect(route('incident-types.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('incident_types', [
            'code' => 'VAC-NEW',
            'category' => 'vacation',
            'is_paid' => true,
            'deducts_vacation' => true,
        ]);
    }

    public function test_admin_can_store_with_position_and_department_sync(): void
    {
        $this->actingAsAdmin();
        $position = Position::factory()->create();
        $department = Department::factory()->create();

        $this->post(route('incident-types.store'), [
            'name' => 'Permiso',
            'code' => 'PER-SYNC',
            'category' => 'permission',
            'color' => '#00FF00',
            'priority' => 0,
            'position_ids' => [$position->id],
            'department_ids' => [$department->id],
        ])
            ->assertRedirect(route('incident-types.index'))
            ->assertSessionHas('success');

        $type = IncidentType::where('code', 'PER-SYNC')->firstOrFail();
        $this->assertTrue($type->positions->contains($position->id));
        $this->assertTrue($type->departments->contains($department->id));
    }

    public function test_store_requires_name_code_category_color(): void
    {
        $this->actingAsAdmin();

        $this->from(route('incident-types.create'))
            ->post(route('incident-types.store'), [])
            ->assertRedirect(route('incident-types.create'))
            ->assertSessionHasErrors(['name', 'code', 'category', 'color']);
    }

    public function test_store_rejects_invalid_category_enum(): void
    {
        $this->actingAsAdmin();

        $this->from(route('incident-types.create'))
            ->post(route('incident-types.store'), [
                'name' => 'X',
                'code' => 'X-CAT',
                'category' => 'banana',
                'color' => '#000000',
            ])
            ->assertSessionHasErrors(['category']);
    }

    public function test_store_rejects_duplicate_code(): void
    {
        $this->actingAsAdmin();
        IncidentType::factory()->create(['code' => 'DUP-IT']);

        $this->from(route('incident-types.create'))
            ->post(route('incident-types.store'), [
                'name' => 'Dup',
                'code' => 'DUP-IT',
                'category' => 'absence',
                'color' => '#000000',
            ])
            ->assertSessionHasErrors(['code']);
    }

    public function test_store_rejects_nonexistent_department_id(): void
    {
        $this->actingAsAdmin();

        $this->from(route('incident-types.create'))
            ->post(route('incident-types.store'), [
                'name' => 'X',
                'code' => 'IT-BADDEP',
                'category' => 'absence',
                'color' => '#000000',
                'department_ids' => [999999],
            ])
            ->assertSessionHasErrors(['department_ids.0']);
    }

    public function test_store_rejects_negative_priority(): void
    {
        $this->actingAsAdmin();

        $this->from(route('incident-types.create'))
            ->post(route('incident-types.store'), [
                'name' => 'X',
                'code' => 'IT-PRIO',
                'category' => 'absence',
                'color' => '#000000',
                'priority' => -5,
            ])
            ->assertSessionHasErrors(['priority']);
    }

    public function test_rrhh_cannot_store(): void
    {
        $this->actingAsRrhh();

        $this->post(route('incident-types.store'), [
            'name' => 'X',
            'code' => 'IT-RRHH',
            'category' => 'absence',
            'color' => '#000000',
        ])->assertForbidden();

        $this->assertDatabaseMissing('incident_types', ['code' => 'IT-RRHH']);
    }

    public function test_supervisor_cannot_store(): void
    {
        $this->actingAsSupervisor();

        $this->post(route('incident-types.store'), [
            'name' => 'X',
            'code' => 'IT-SUP',
            'category' => 'absence',
            'color' => '#000000',
        ])->assertForbidden();

        $this->assertDatabaseMissing('incident_types', ['code' => 'IT-SUP']);
    }

    public function test_guest_cannot_store(): void
    {
        $this->post(route('incident-types.store'), [])
            ->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------ edit

    public function test_admin_sees_edit_with_expected_inertia_props(): void
    {
        $this->actingAsAdmin();
        $type = IncidentType::factory()->create();

        $this->get(route('incident-types.edit', $type))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('IncidentTypes/Edit')
                ->has('incidentType')
                ->where('incidentType.id', $type->id)
                ->has('positions')
                ->has('departments'));
    }

    public function test_rrhh_cannot_view_edit(): void
    {
        $this->actingAsRrhh();
        $type = IncidentType::factory()->create();
        $this->get(route('incident-types.edit', $type))->assertForbidden();
    }

    public function test_supervisor_cannot_view_edit(): void
    {
        $this->actingAsSupervisor();
        $type = IncidentType::factory()->create();
        $this->get(route('incident-types.edit', $type))->assertForbidden();
    }

    public function test_guest_redirected_to_login_from_edit(): void
    {
        $type = IncidentType::factory()->create();
        $this->get(route('incident-types.edit', $type))->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------- update

    public function test_admin_can_update_incident_type(): void
    {
        $this->actingAsAdmin();
        $type = IncidentType::factory()->create(['code' => 'UPD-IT']);

        $this->put(route('incident-types.update', $type), [
            'name' => 'Actualizado',
            'code' => 'UPD-IT',
            'category' => 'sick_leave',
            'is_paid' => true,
            'requires_document' => true,
            'color' => '#123456',
            'priority' => 3,
        ])
            ->assertRedirect(route('incident-types.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('incident_types', [
            'id' => $type->id,
            'name' => 'Actualizado',
            'category' => 'sick_leave',
            'is_paid' => true,
            'requires_document' => true,
            'priority' => 3,
        ]);
    }

    public function test_update_rejects_code_taken_by_another(): void
    {
        $this->actingAsAdmin();
        IncidentType::factory()->create(['code' => 'TAKEN-IT']);
        $type = IncidentType::factory()->create(['code' => 'MINE-IT']);

        $this->from(route('incident-types.edit', $type))
            ->put(route('incident-types.update', $type), [
                'name' => 'X',
                'code' => 'TAKEN-IT',
                'category' => 'absence',
                'color' => '#000000',
            ])
            ->assertSessionHasErrors(['code']);
    }

    public function test_update_detaches_positions_when_omitted(): void
    {
        $this->actingAsAdmin();
        $position = Position::factory()->create();
        $type = IncidentType::factory()->create(['code' => 'DET-IT']);
        $type->positions()->sync([$position->id]);
        $this->assertCount(1, $type->fresh()->positions);

        // No position_ids => syncPositions() detaches all.
        $this->put(route('incident-types.update', $type), [
            'name' => $type->name,
            'code' => 'DET-IT',
            'category' => 'absence',
            'color' => '#000000',
        ])->assertRedirect(route('incident-types.index'));

        $this->assertCount(0, $type->fresh()->positions);
    }

    public function test_update_detaches_departments_when_omitted(): void
    {
        $this->actingAsAdmin();
        $department = Department::factory()->create();
        $type = IncidentType::factory()->create(['code' => 'DET-DEP']);
        $type->departments()->sync([$department->id]);
        $this->assertCount(1, $type->fresh()->departments);

        // No department_ids => syncDepartments() detaches all.
        $this->put(route('incident-types.update', $type), [
            'name' => $type->name,
            'code' => 'DET-DEP',
            'category' => 'absence',
            'color' => '#000000',
        ])->assertRedirect(route('incident-types.index'));

        $this->assertCount(0, $type->fresh()->departments);
    }

    public function test_supervisor_cannot_update(): void
    {
        $this->actingAsSupervisor();
        $type = IncidentType::factory()->create();

        $this->put(route('incident-types.update', $type), [
            'name' => 'X',
            'code' => $type->code,
            'category' => 'absence',
            'color' => '#000000',
        ])->assertForbidden();
    }

    public function test_rrhh_cannot_update(): void
    {
        $this->actingAsRrhh();
        $type = IncidentType::factory()->create();

        $this->put(route('incident-types.update', $type), [
            'name' => 'X',
            'code' => $type->code,
            'category' => 'absence',
            'color' => '#000000',
        ])->assertForbidden();
    }

    public function test_guest_cannot_update(): void
    {
        $type = IncidentType::factory()->create();
        $this->put(route('incident-types.update', $type), [])
            ->assertRedirect(route('login'));
    }

    // --------------------------------------------------------------- destroy

    public function test_admin_destroy_soft_deactivates(): void
    {
        $this->actingAsAdmin();
        $type = IncidentType::factory()->create(['is_active' => true]);

        $this->delete(route('incident-types.destroy', $type))
            ->assertRedirect(route('incident-types.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('incident_types', [
            'id' => $type->id,
            'is_active' => false,
        ]);
    }

    public function test_rrhh_cannot_destroy(): void
    {
        $this->actingAsRrhh();
        $type = IncidentType::factory()->create(['is_active' => true]);

        $this->delete(route('incident-types.destroy', $type))->assertForbidden();

        $this->assertDatabaseHas('incident_types', [
            'id' => $type->id,
            'is_active' => true,
        ]);
    }

    public function test_supervisor_cannot_destroy(): void
    {
        $this->actingAsSupervisor();
        $type = IncidentType::factory()->create(['is_active' => true]);

        $this->delete(route('incident-types.destroy', $type))->assertForbidden();

        $this->assertDatabaseHas('incident_types', [
            'id' => $type->id,
            'is_active' => true,
        ]);
    }

    public function test_employee_cannot_destroy(): void
    {
        $this->actingAsEmployee();
        $type = IncidentType::factory()->create(['is_active' => true]);

        $this->delete(route('incident-types.destroy', $type))->assertForbidden();
    }

    public function test_guest_cannot_destroy(): void
    {
        $type = IncidentType::factory()->create();
        $this->delete(route('incident-types.destroy', $type))
            ->assertRedirect(route('login'));
    }
}
