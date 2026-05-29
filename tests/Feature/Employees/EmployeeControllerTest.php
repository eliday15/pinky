<?php

namespace Tests\Feature\Employees;

use App\Models\CompensationType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Schedule;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\FeatureTestCase;

/**
 * Front-to-back contract tests for EmployeeController (resource + bulkUpdate).
 *
 * Verifies Inertia props consumed by Employees/{Index,Create,Edit,Show}.vue,
 * the EmployeePolicy RBAC matrix, validation rules, and DB effects.
 */
class EmployeeControllerTest extends FeatureTestCase
{
    /**
     * Build a complete, valid store payload for an employee.
     *
     * @return array<string, mixed>
     */
    private function validStorePayload(array $overrides = []): array
    {
        $department = Department::factory()->create();
        $schedule = Schedule::factory()->create();
        $position = Position::factory()->create(['department_id' => $department->id]);

        return array_merge([
            'employee_number' => 'EMP-9001',
            'zkteco_user_id' => 5551,
            'first_name' => 'Juan',
            'last_name' => 'Perez Lopez',
            'hire_date' => '2024-01-15',
            'department_id' => $department->id,
            'position_id' => $position->id,
            'schedule_id' => $schedule->id,
            'hourly_rate' => 75.50,
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // index()
    // ------------------------------------------------------------------

    public function test_admin_can_view_index_with_all_props(): void
    {
        $this->actingAsAdmin();
        Employee::factory()->count(3)->create();

        $this->get(route('employees.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Employees/Index')
                ->has('employees.data')
                ->has('departments')
                ->has('positions')
                ->has('schedules')
                ->has('supervisors')
                ->has('compensationTypes')
                ->has('filters')
                ->has('can')
                ->where('can.create', true)
                ->where('can.update', true)
                ->where('can.delete', true)
                ->where('can.bulkEdit', true));
    }

    public function test_rrhh_can_view_index_but_cannot_delete_or_bulk_edit(): void
    {
        $this->actingAsRrhh();
        Employee::factory()->count(2)->create();

        $this->get(route('employees.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Employees/Index')
                ->has('employees.data')
                ->where('can.create', true)
                ->where('can.update', true)   // edit_personal counts
                ->where('can.delete', false)
                ->where('can.bulkEdit', false));
    }

    public function test_supervisor_index_only_shows_team(): void
    {
        $supervisor = $this->actingAsSupervisor();
        $supervisorEmployee = $this->attachEmployee($supervisor);

        $subordinate = Employee::factory()->create(['supervisor_id' => $supervisorEmployee->id]);
        // An unrelated employee who is NOT in the supervisor's team.
        Employee::factory()->create();

        $this->get(route('employees.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Employees/Index')
                ->where('can.create', false)
                ->where('can.delete', false)
                ->has('employees.data', 1)
                ->where('employees.data.0.id', $subordinate->id));
    }

    public function test_employee_role_forbidden_from_index(): void
    {
        $this->actingAsEmployee();

        $this->get(route('employees.index'))->assertForbidden();
    }

    public function test_guest_redirected_to_login_from_index(): void
    {
        $this->get(route('employees.index'))->assertRedirect(route('login'));
    }

    public function test_index_search_filter_is_applied(): void
    {
        $this->actingAsAdmin();
        $match = Employee::factory()->create(['full_name' => 'Zoraida Match', 'first_name' => 'Zoraida']);
        Employee::factory()->create(['full_name' => 'Otro Empleado', 'first_name' => 'Otro']);

        $this->get(route('employees.index', ['search' => 'Zoraida']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('employees.data', 1)
                ->where('employees.data.0.id', $match->id)
                ->where('filters.search', 'Zoraida'));
    }

    // ------------------------------------------------------------------
    // create()
    // ------------------------------------------------------------------

    public function test_admin_can_view_create_form_with_all_props(): void
    {
        $this->actingAsAdmin();

        $this->get(route('employees.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Employees/Create')
                ->has('departments')
                ->has('positions')
                ->has('schedules')
                ->has('employees')
                ->has('compensationTypes')
                ->has('vacationTable')
                ->where('canEditAll', true));
    }

    public function test_rrhh_can_view_create_form_with_canEditAll_false(): void
    {
        $this->actingAsRrhh();

        $this->get(route('employees.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Employees/Create')
                ->where('canEditAll', false));
    }

    public function test_supervisor_forbidden_from_create_form(): void
    {
        $this->actingAsSupervisor();

        $this->get(route('employees.create'))->assertForbidden();
    }

    public function test_guest_redirected_to_login_from_create(): void
    {
        $this->get(route('employees.create'))->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------
    // store()
    // ------------------------------------------------------------------

    public function test_admin_can_store_employee(): void
    {
        $this->actingAsAdmin();
        $payload = $this->validStorePayload();

        $this->post(route('employees.store'), $payload)
            ->assertRedirect(route('employees.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('employees', [
            'employee_number' => 'EMP-9001',
            'full_name' => 'Juan Perez Lopez',
            'zkteco_user_id' => 5551,
        ]);
    }

    public function test_store_validates_required_fields(): void
    {
        $this->actingAsAdmin();

        $this->from(route('employees.create'))
            ->post(route('employees.store'), [])
            ->assertRedirect(route('employees.create'))
            ->assertSessionHasErrors(['employee_number', 'zkteco_user_id', 'first_name', 'last_name', 'hire_date']);
    }

    public function test_store_rejects_duplicate_employee_number(): void
    {
        $this->actingAsAdmin();
        Employee::factory()->create(['employee_number' => 'EMP-DUP']);

        $payload = $this->validStorePayload(['employee_number' => 'EMP-DUP']);

        $this->from(route('employees.create'))
            ->post(route('employees.store'), $payload)
            ->assertSessionHasErrors(['employee_number']);
    }

    public function test_store_rejects_zkteco_id_already_used_by_active_employee(): void
    {
        $this->actingAsAdmin();
        Employee::factory()->create(['zkteco_user_id' => 7777, 'status' => 'active']);

        $payload = $this->validStorePayload(['zkteco_user_id' => 7777]);

        $this->from(route('employees.create'))
            ->post(route('employees.store'), $payload)
            ->assertSessionHasErrors(['zkteco_user_id']);
    }

    public function test_supervisor_forbidden_from_store(): void
    {
        $this->actingAsSupervisor();

        $this->post(route('employees.store'), $this->validStorePayload())
            ->assertForbidden();
    }

    public function test_guest_redirected_to_login_from_store(): void
    {
        $this->post(route('employees.store'), [])->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------
    // show()
    // ------------------------------------------------------------------

    public function test_admin_can_view_show_with_all_props(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->create();

        $this->get(route('employees.show', $employee))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Employees/Show')
                ->has('employee')
                ->where('employee.id', $employee->id)
                ->has('auditHistory')
                ->has('can')
                ->where('can.edit', true)
                ->where('can.delete', true)
                ->has('can.viewSalary')
                ->has('can.editSubordinates'));
    }

    public function test_supervisor_can_show_own_team_member(): void
    {
        $supervisor = $this->actingAsSupervisor();
        $supervisorEmployee = $this->attachEmployee($supervisor);
        $subordinate = Employee::factory()->create(['supervisor_id' => $supervisorEmployee->id]);

        $this->get(route('employees.show', $subordinate))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Employees/Show')
                ->where('employee.id', $subordinate->id));
    }

    public function test_supervisor_forbidden_from_show_non_team_member(): void
    {
        $supervisor = $this->actingAsSupervisor();
        $this->attachEmployee($supervisor);
        $stranger = Employee::factory()->create();

        $this->get(route('employees.show', $stranger))->assertForbidden();
    }

    public function test_guest_redirected_to_login_from_show(): void
    {
        $employee = Employee::factory()->create();

        $this->get(route('employees.show', $employee))->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------
    // edit()
    // ------------------------------------------------------------------

    public function test_admin_can_view_edit_form_with_all_props(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->create();

        $this->get(route('employees.edit', $employee))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Employees/Edit')
                ->has('employee')
                ->has('departments')
                ->has('positions')
                ->has('schedules')
                ->has('employees')
                ->has('compensationTypes')
                ->has('vacationTable')
                ->has('roles')
                ->has('canCreateUser')
                ->has('subordinateIds')
                ->where('canEditAll', true));
    }

    public function test_rrhh_can_view_edit_form_with_canEditAll_false(): void
    {
        $this->actingAsRrhh();
        $employee = Employee::factory()->create();

        $this->get(route('employees.edit', $employee))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Employees/Edit')
                ->where('canEditAll', false));
    }

    public function test_supervisor_forbidden_from_edit_non_team_member(): void
    {
        $supervisor = $this->actingAsSupervisor();
        $this->attachEmployee($supervisor);
        $stranger = Employee::factory()->create();

        // Supervisor has view_team but NOT employees.edit/edit_personal → policy update() returns false.
        $this->get(route('employees.edit', $stranger))->assertForbidden();
    }

    public function test_guest_redirected_to_login_from_edit(): void
    {
        $employee = Employee::factory()->create();

        $this->get(route('employees.edit', $employee))->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------
    // update()
    // ------------------------------------------------------------------

    public function test_admin_can_update_employee(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->create();

        $payload = array_merge($this->updatePayloadFrom($employee), [
            'first_name' => 'Renamed',
            'last_name' => 'Worker',
        ]);

        $this->put(route('employees.update', $employee), $payload)
            ->assertRedirect(route('employees.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'full_name' => 'Renamed Worker',
        ]);
    }

    public function test_rrhh_update_only_changes_personal_fields(): void
    {
        $this->actingAsRrhh();
        $department = Department::factory()->create();
        $newDepartment = Department::factory()->create();
        $employee = Employee::factory()->create([
            'department_id' => $department->id,
            'first_name' => 'Original',
            'last_name' => 'Name',
        ]);

        // RRHH posts an attempt to change department + name; only name should stick.
        $this->put(route('employees.update', $employee), [
            'first_name' => 'NuevoNombre',
            'last_name' => 'NuevoApellido',
            'department_id' => $newDepartment->id,
        ])
            ->assertRedirect(route('employees.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'full_name' => 'NuevoNombre NuevoApellido',
            'department_id' => $department->id, // unchanged — RRHH cannot move departments
        ]);
    }

    public function test_update_validates_required_fields(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->create();

        // Keep schedule_id/compensation identical so the 2FA gate (which fires for
        // sensitive changes BEFORE field validation) does not intercept; this isolates
        // the required-field validation contract.
        $this->from(route('employees.edit', $employee))
            ->put(route('employees.update', $employee), [
                'schedule_id' => $employee->schedule_id,
            ])
            ->assertSessionHasErrors(['employee_number', 'zkteco_user_id', 'first_name', 'last_name']);
    }

    public function test_supervisor_forbidden_from_update(): void
    {
        $supervisor = $this->actingAsSupervisor();
        $supervisorEmployee = $this->attachEmployee($supervisor);
        $subordinate = Employee::factory()->create(['supervisor_id' => $supervisorEmployee->id]);

        // Supervisor lacks employees.edit/edit_personal → cannot update even own team.
        $this->put(route('employees.update', $subordinate), $this->updatePayloadFrom($subordinate))
            ->assertForbidden();
    }

    public function test_guest_redirected_to_login_from_update(): void
    {
        $employee = Employee::factory()->create();

        $this->put(route('employees.update', $employee), [])->assertRedirect(route('login'));
    }

    /**
     * Build a complete, valid update payload mirroring the existing employee.
     *
     * @return array<string, mixed>
     */
    private function updatePayloadFrom(Employee $employee): array
    {
        return [
            'employee_number' => $employee->employee_number,
            'zkteco_user_id' => $employee->zkteco_user_id,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'hire_date' => $employee->hire_date->toDateString(),
            'department_id' => $employee->department_id,
            'position_id' => $employee->position_id,
            'schedule_id' => $employee->schedule_id,
            'hourly_rate' => (float) $employee->hourly_rate,
            'vacation_days_entitled' => 12,
            'vacation_days_used' => 0,
            'status' => 'active',
        ];
    }

    // ------------------------------------------------------------------
    // destroy()
    // ------------------------------------------------------------------

    public function test_admin_can_delete_employee_soft_delete(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->create();

        $this->delete(route('employees.destroy', $employee))
            ->assertRedirect(route('employees.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('employees', ['id' => $employee->id]);
    }

    public function test_rrhh_forbidden_from_delete(): void
    {
        $this->actingAsRrhh();
        $employee = Employee::factory()->create();

        // rrhh lacks employees.delete.
        $this->delete(route('employees.destroy', $employee))->assertForbidden();

        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'deleted_at' => null]);
    }

    public function test_supervisor_forbidden_from_delete(): void
    {
        $this->actingAsSupervisor();
        $employee = Employee::factory()->create();

        $this->delete(route('employees.destroy', $employee))->assertForbidden();
    }

    public function test_guest_redirected_to_login_from_destroy(): void
    {
        $employee = Employee::factory()->create();

        $this->delete(route('employees.destroy', $employee))->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------
    // bulkUpdate()
    // ------------------------------------------------------------------

    public function test_admin_can_bulk_set_field_on_selected(): void
    {
        $this->actingAsAdmin();
        $newDepartment = Department::factory()->create();
        $e1 = Employee::factory()->create();
        $e2 = Employee::factory()->create();

        $this->post(route('employees.bulkUpdate'), [
            'apply_to' => 'selected',
            'employee_ids' => [$e1->id, $e2->id],
            'operation_type' => 'set_field',
            'field' => 'department_id',
            'value' => $newDepartment->id,
        ])
            ->assertRedirect(route('employees.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('employees', ['id' => $e1->id, 'department_id' => $newDepartment->id]);
        $this->assertDatabaseHas('employees', ['id' => $e2->id, 'department_id' => $newDepartment->id]);
    }

    public function test_admin_can_bulk_adjust_compensation_fixed(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->create(['hourly_rate' => 100.00]);

        $this->post(route('employees.bulkUpdate'), [
            'apply_to' => 'selected',
            'employee_ids' => [$employee->id],
            'operation_type' => 'adjust_compensation',
            'compensation_field' => 'hourly_rate',
            'adjustment_type' => 'fixed',
            'adjustment_value' => 25,
        ])
            ->assertRedirect(route('employees.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'hourly_rate' => 125.00]);
    }

    public function test_bulk_update_validates_operation_type(): void
    {
        $this->actingAsAdmin();

        $this->post(route('employees.bulkUpdate'), [
            'apply_to' => 'selected',
            'employee_ids' => [],
        ])->assertSessionHasErrors(['operation_type']);
    }

    public function test_bulk_update_rejects_invalid_set_field(): void
    {
        $this->actingAsAdmin();

        $this->post(route('employees.bulkUpdate'), [
            'apply_to' => 'selected',
            'employee_ids' => [],
            'operation_type' => 'set_field',
            'field' => 'hourly_rate', // not in the allowed set_field list
            'value' => 1,
        ])->assertSessionHasErrors(['field']);
    }

    public function test_rrhh_forbidden_from_bulk_update(): void
    {
        $this->actingAsRrhh();
        $employee = Employee::factory()->create();

        // rrhh lacks employees.bulk_edit → controller abort(403).
        $this->post(route('employees.bulkUpdate'), [
            'apply_to' => 'selected',
            'employee_ids' => [$employee->id],
            'operation_type' => 'set_field',
            'field' => 'status',
            'value' => 'inactive',
        ])->assertForbidden();
    }

    public function test_supervisor_forbidden_from_bulk_update(): void
    {
        $this->actingAsSupervisor();

        $this->post(route('employees.bulkUpdate'), [
            'apply_to' => 'selected',
            'employee_ids' => [],
            'operation_type' => 'set_field',
            'field' => 'status',
            'value' => 'inactive',
        ])->assertForbidden();
    }

    public function test_guest_redirected_to_login_from_bulk_update(): void
    {
        $this->post(route('employees.bulkUpdate'), [])->assertRedirect(route('login'));
    }

    // ==================================================================
    // STRENGTHENING PASS — added by adversarial review
    // ==================================================================

    // ------------------------------------------------------------------
    // index() — additional filters + rrhh view_all scope
    // ------------------------------------------------------------------

    public function test_rrhh_index_sees_all_active_employees(): void
    {
        // rrhh has employees.view_all → not team-scoped, sees every active employee.
        $this->actingAsRrhh();
        Employee::factory()->count(4)->create();

        $this->get(route('employees.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Employees/Index')
                ->has('employees.data', 4));
    }

    public function test_index_department_filter_is_applied(): void
    {
        $this->actingAsAdmin();
        $dept = Department::factory()->create();
        $match = Employee::factory()->create(['department_id' => $dept->id]);
        Employee::factory()->create(); // different department

        $this->get(route('employees.index', ['department' => $dept->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('employees.data', 1)
                ->where('employees.data.0.id', $match->id)
                ->where('filters.department', (string) $dept->id));
    }

    public function test_index_status_filter_returns_inactive_only(): void
    {
        $this->actingAsAdmin();
        $inactive = Employee::factory()->inactive()->create();
        Employee::factory()->create(); // active — default filter hides it under status=inactive

        $this->get(route('employees.index', ['status' => 'inactive']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('employees.data', 1)
                ->where('employees.data.0.id', $inactive->id));
    }

    public function test_index_default_hides_non_active_employees(): void
    {
        // No status filter → controller defaults to status=active only.
        $this->actingAsAdmin();
        $active = Employee::factory()->create();
        Employee::factory()->terminated()->create();

        $this->get(route('employees.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('employees.data', 1)
                ->where('employees.data.0.id', $active->id));
    }

    public function test_index_minimum_wage_filter_is_applied(): void
    {
        $this->actingAsAdmin();
        $minWage = Employee::factory()->minimumWage()->create();
        Employee::factory()->create(['is_minimum_wage' => false]);

        $this->get(route('employees.index', ['is_minimum_wage' => 'yes']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('employees.data', 1)
                ->where('employees.data.0.id', $minWage->id));
    }

    // ------------------------------------------------------------------
    // store() — rrhh reduced flow + zkteco conflict branches + side effects
    // ------------------------------------------------------------------

    public function test_rrhh_can_store_employee_with_minimal_payload(): void
    {
        // rrhh has employees.create but NOT employees.edit → controller fills
        // department/position/schedule/hire_date/hourly_rate defaults itself.
        $this->actingAsRrhh();
        // Ensure at least one active row exists for each FK the controller back-fills.
        Department::factory()->create();
        Position::factory()->create();
        Schedule::factory()->create();

        $this->post(route('employees.store'), [
            'employee_number' => 'RRHH-001',
            'zkteco_user_id' => 4242,
            'first_name' => 'Ana',
            'last_name' => 'Garcia',
        ])
            ->assertRedirect(route('employees.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('employees', [
            'employee_number' => 'RRHH-001',
            'full_name' => 'Ana Garcia',
        ]);
    }

    public function test_store_sets_full_name_and_default_status(): void
    {
        $this->actingAsAdmin();

        $this->post(route('employees.store'), $this->validStorePayload([
            'employee_number' => 'EMP-FULLNAME',
            'first_name' => 'Maria',
            'last_name' => 'De La Cruz',
        ]))->assertRedirect(route('employees.index'));

        $this->assertDatabaseHas('employees', [
            'employee_number' => 'EMP-FULLNAME',
            'full_name' => 'Maria De La Cruz',
            'status' => 'active', // defaulted
            'vacation_premium_percentage' => 25.00,
        ]);
    }

    public function test_store_zkteco_inactive_conflict_requires_confirmation(): void
    {
        $this->actingAsAdmin();
        Employee::factory()->inactive()->create([
            'zkteco_user_id' => 8888,
            'employee_number' => 'OLD-INACTIVE',
        ]);

        // Without confirm_zkteco_reassign the controller throws a special error.
        $this->from(route('employees.create'))
            ->post(route('employees.store'), $this->validStorePayload([
                'employee_number' => 'NEW-001',
                'zkteco_user_id' => 8888,
            ]))
            ->assertSessionHasErrors(['zkteco_user_id']);

        // The new employee must NOT have been created.
        $this->assertDatabaseMissing('employees', ['employee_number' => 'NEW-001']);
    }

    public function test_store_zkteco_inactive_conflict_succeeds_with_confirmation(): void
    {
        $this->actingAsAdmin();
        $inactive = Employee::factory()->inactive()->create([
            'zkteco_user_id' => 9999,
            'employee_number' => 'OLD-INACTIVE-2',
        ]);

        $this->post(route('employees.store'), $this->validStorePayload([
            'employee_number' => 'NEW-002',
            'zkteco_user_id' => 9999,
            'confirm_zkteco_reassign' => true,
        ]))->assertRedirect(route('employees.index'));

        // New employee gets the ID; old inactive employee's ID is cleared.
        $this->assertDatabaseHas('employees', ['employee_number' => 'NEW-002', 'zkteco_user_id' => 9999]);
        $this->assertDatabaseHas('employees', ['id' => $inactive->id, 'zkteco_user_id' => null]);
    }

    public function test_store_zkteco_softdeleted_reassign_clears_silently(): void
    {
        $this->actingAsAdmin();
        $deleted = Employee::factory()->create([
            'zkteco_user_id' => 3030,
            'employee_number' => 'SOFT-DEL',
        ]);
        $deleted->delete();

        // Soft-deleted holder: controller silently clears its ID, no confirmation needed.
        $this->post(route('employees.store'), $this->validStorePayload([
            'employee_number' => 'NEW-003',
            'zkteco_user_id' => 3030,
        ]))->assertRedirect(route('employees.index'));

        $this->assertDatabaseHas('employees', ['employee_number' => 'NEW-003', 'zkteco_user_id' => 3030]);
        $this->assertDatabaseHas('employees', ['id' => $deleted->id, 'zkteco_user_id' => null]);
    }

    public function test_store_validates_termination_date_after_hire_date(): void
    {
        $this->actingAsAdmin();

        $this->from(route('employees.create'))
            ->post(route('employees.store'), $this->validStorePayload([
                'hire_date' => '2024-06-01',
                'termination_date' => '2024-01-01', // before hire_date
            ]))
            ->assertSessionHasErrors(['termination_date']);
    }

    public function test_store_validates_position_must_exist(): void
    {
        $this->actingAsAdmin();

        $this->from(route('employees.create'))
            ->post(route('employees.store'), $this->validStorePayload([
                'position_id' => 999999,
            ]))
            ->assertSessionHasErrors(['position_id']);
    }

    public function test_employee_role_forbidden_from_store(): void
    {
        $this->actingAsEmployee();

        $this->post(route('employees.store'), $this->validStorePayload())->assertForbidden();
    }

    // ------------------------------------------------------------------
    // show() — employee role own-record + salary visibility
    // ------------------------------------------------------------------

    public function test_employee_role_forbidden_from_show_any(): void
    {
        // employee role has NO employees.* permission → view() returns false.
        $user = $this->actingAsEmployee();
        $own = $this->attachEmployee($user);

        $this->get(route('employees.show', $own))->assertForbidden();
    }

    public function test_supervisor_show_exposes_can_flags_without_salary(): void
    {
        $supervisor = $this->actingAsSupervisor();
        $supervisorEmployee = $this->attachEmployee($supervisor);
        $subordinate = Employee::factory()->create(['supervisor_id' => $supervisorEmployee->id]);

        // Supervisor lacks employees.view_salary and the record isn't his own.
        $this->get(route('employees.show', $subordinate))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Employees/Show')
                ->where('can.edit', false)   // no edit/edit_personal perm
                ->where('can.delete', false)
                ->where('can.viewSalary', false)
                ->has('can.editSubordinates'));
    }

    // ------------------------------------------------------------------
    // edit() — rrhh prop completeness + employee 403
    // ------------------------------------------------------------------

    public function test_rrhh_edit_form_has_full_prop_contract(): void
    {
        // Even the reduced rrhh flow renders the full Edit page; every prop the
        // Vue defineProps declares must be present.
        $this->actingAsRrhh();
        $employee = Employee::factory()->create();

        $this->get(route('employees.edit', $employee))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Employees/Edit')
                ->has('employee')
                ->has('departments')
                ->has('positions')
                ->has('schedules')
                ->has('employees')
                ->has('compensationTypes')
                ->has('vacationTable')
                ->has('roles')
                ->where('canCreateUser', false) // rrhh lacks users.create
                ->has('subordinateIds')
                ->where('canEditAll', false));
    }

    public function test_employee_role_forbidden_from_edit(): void
    {
        $user = $this->actingAsEmployee();
        $own = $this->attachEmployee($user);

        $this->get(route('employees.edit', $own))->assertForbidden();
    }

    // ------------------------------------------------------------------
    // update() — 2FA gate, self-supervisor guard, zkteco active conflict
    // ------------------------------------------------------------------

    public function test_update_schedule_change_requires_two_factor_code(): void
    {
        // admin user has a confirmed 2FA device → changing schedule_id triggers
        // verifyTwoFactorCode BEFORE field validation. With no code supplied the
        // 2FA validation fails.
        $this->actingAsAdmin();
        $employee = Employee::factory()->create();
        $newSchedule = Schedule::factory()->create();

        $payload = array_merge($this->updatePayloadFrom($employee), [
            'schedule_id' => $newSchedule->id,
        ]);

        $this->from(route('employees.edit', $employee))
            ->put(route('employees.update', $employee), $payload)
            ->assertSessionHasErrors(['two_factor_code']);

        // Schedule must remain unchanged because the gate blocked the write.
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'schedule_id' => $employee->schedule_id,
        ]);
    }

    public function test_update_compensation_change_requires_two_factor_code(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->create();
        $compType = CompensationType::factory()->create();

        $payload = array_merge($this->updatePayloadFrom($employee), [
            'compensation_type_ids' => [$compType->id],
        ]);

        $this->from(route('employees.edit', $employee))
            ->put(route('employees.update', $employee), $payload)
            ->assertSessionHasErrors(['two_factor_code']);
    }

    public function test_update_no_sensitive_change_skips_two_factor(): void
    {
        // Same schedule + no compensation change → 2FA gate is a no-op; update proceeds.
        $this->actingAsAdmin();
        $employee = Employee::factory()->create();

        $this->put(route('employees.update', $employee), array_merge(
            $this->updatePayloadFrom($employee),
            ['first_name' => 'SoloNombre', 'last_name' => 'Cambio']
        ))
            ->assertRedirect(route('employees.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'full_name' => 'SoloNombre Cambio',
        ]);
    }

    public function test_update_rejects_self_referencing_supervisor(): void
    {
        // supervisor_id pointing at the employee itself is stripped to null by the
        // controller's defensive merge, so the update succeeds with no supervisor.
        $this->actingAsAdmin();
        $employee = Employee::factory()->create(['supervisor_id' => null]);

        $this->put(route('employees.update', $employee), array_merge(
            $this->updatePayloadFrom($employee),
            ['supervisor_id' => $employee->id]
        ))
            ->assertRedirect(route('employees.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'supervisor_id' => null,
        ]);
    }

    public function test_update_zkteco_active_conflict_is_rejected(): void
    {
        $this->actingAsAdmin();
        Employee::factory()->create(['zkteco_user_id' => 1212, 'status' => 'active']);
        $employee = Employee::factory()->create(['zkteco_user_id' => 1313]);

        $this->from(route('employees.edit', $employee))
            ->put(route('employees.update', $employee), array_merge(
                $this->updatePayloadFrom($employee),
                ['zkteco_user_id' => 1212]
            ))
            ->assertSessionHasErrors(['zkteco_user_id']);

        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'zkteco_user_id' => 1313]);
    }

    public function test_update_validates_status_enum(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->create();

        $this->from(route('employees.edit', $employee))
            ->put(route('employees.update', $employee), array_merge(
                $this->updatePayloadFrom($employee),
                ['status' => 'bogus_status']
            ))
            ->assertSessionHasErrors(['status']);
    }

    public function test_employee_role_forbidden_from_update(): void
    {
        $user = $this->actingAsEmployee();
        $own = $this->attachEmployee($user);

        $this->put(route('employees.update', $own), $this->updatePayloadFrom($own))
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // destroy() — employee role 403
    // ------------------------------------------------------------------

    public function test_employee_role_forbidden_from_destroy(): void
    {
        $user = $this->actingAsEmployee();
        $own = $this->attachEmployee($user);

        $this->delete(route('employees.destroy', $own))->assertForbidden();
        $this->assertDatabaseHas('employees', ['id' => $own->id, 'deleted_at' => null]);
    }

    // ------------------------------------------------------------------
    // bulkUpdate() — percentage adjust, filtered mode, empty-result, validation
    // ------------------------------------------------------------------

    public function test_admin_can_bulk_adjust_compensation_percentage(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->create(['hourly_rate' => 100.00]);

        $this->post(route('employees.bulkUpdate'), [
            'apply_to' => 'selected',
            'employee_ids' => [$employee->id],
            'operation_type' => 'adjust_compensation',
            'compensation_field' => 'hourly_rate',
            'adjustment_type' => 'percentage',
            'adjustment_value' => 10, // +10%
        ])
            ->assertRedirect(route('employees.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'hourly_rate' => 110.00]);
    }

    public function test_admin_bulk_filtered_mode_applies_to_matching_only(): void
    {
        $this->actingAsAdmin();
        $dept = Department::factory()->create();
        $newSchedule = Schedule::factory()->create();
        $inDept = Employee::factory()->create(['department_id' => $dept->id]);
        $outDept = Employee::factory()->create(); // different department

        $this->post(route('employees.bulkUpdate'), [
            'apply_to' => 'filtered',
            'filters' => ['department' => $dept->id],
            'operation_type' => 'set_field',
            'field' => 'schedule_id',
            'value' => $newSchedule->id,
        ])
            ->assertRedirect(route('employees.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('employees', ['id' => $inDept->id, 'schedule_id' => $newSchedule->id]);
        $this->assertDatabaseHas('employees', ['id' => $outDept->id, 'schedule_id' => $outDept->schedule_id]);
    }

    public function test_bulk_update_empty_selection_redirects_with_warning(): void
    {
        $this->actingAsAdmin();

        // No employee_ids match → controller short-circuits with a warning flash.
        $this->post(route('employees.bulkUpdate'), [
            'apply_to' => 'selected',
            'employee_ids' => [],
            'operation_type' => 'set_field',
            'field' => 'status',
            'value' => 'inactive',
        ])
            ->assertRedirect(route('employees.index'))
            ->assertSessionHas('warning');
    }

    public function test_bulk_update_adjust_compensation_requires_subfields(): void
    {
        $this->actingAsAdmin();

        $this->post(route('employees.bulkUpdate'), [
            'apply_to' => 'selected',
            'employee_ids' => [],
            'operation_type' => 'adjust_compensation',
        ])->assertSessionHasErrors(['compensation_field', 'adjustment_type', 'adjustment_value']);
    }

    public function test_bulk_update_set_field_requires_value(): void
    {
        $this->actingAsAdmin();

        $this->post(route('employees.bulkUpdate'), [
            'apply_to' => 'selected',
            'employee_ids' => [],
            'operation_type' => 'set_field',
            'field' => 'status',
            // value omitted
        ])->assertSessionHasErrors(['value']);
    }

    public function test_bulk_update_rejects_invalid_apply_to(): void
    {
        $this->actingAsAdmin();

        $this->post(route('employees.bulkUpdate'), [
            'apply_to' => 'everything', // not in [filtered, selected]
            'operation_type' => 'set_field',
            'field' => 'status',
            'value' => 'inactive',
        ])->assertSessionHasErrors(['apply_to']);
    }

    public function test_employee_role_forbidden_from_bulk_update(): void
    {
        $this->actingAsEmployee();

        $this->post(route('employees.bulkUpdate'), [
            'apply_to' => 'selected',
            'employee_ids' => [],
            'operation_type' => 'set_field',
            'field' => 'status',
            'value' => 'inactive',
        ])->assertForbidden();
    }
}
