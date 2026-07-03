<?php

namespace Tests\Feature\Employees;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Schedule;
use Tests\FeatureTestCase;

/**
 * Bandera is_attendance_exempt ("No checa") en alta/edición de empleados:
 * - Admin puede marcarla y dar de alta SIN ID ZKTeco.
 * - Dos empleados exentos (ambos sin ZKTeco) no chocan por el falso conflicto
 *   de whereNull.
 * - Un empleado normal sigue requiriendo ID ZKTeco.
 * - RRHH (sin employees.edit) no puede fijar la bandera.
 */
class AttendanceExemptFlagTest extends FeatureTestCase
{
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
            'daily_salary' => 604.00,
        ], $overrides);
    }

    public function test_admin_can_store_exempt_employee_without_zkteco(): void
    {
        $this->actingAsAdmin();

        $payload = $this->validStorePayload([
            'employee_number' => 'EMP-EXEMPT-1',
            'is_attendance_exempt' => true,
            'zkteco_user_id' => null,
        ]);

        $this->post(route('employees.store'), $payload)
            ->assertRedirect(route('employees.index'))
            ->assertSessionHasNoErrors();

        $employee = Employee::where('employee_number', 'EMP-EXEMPT-1')->firstOrFail();
        $this->assertTrue((bool) $employee->is_attendance_exempt);
        $this->assertNull($employee->zkteco_user_id);
    }

    public function test_two_exempt_employees_without_zkteco_have_no_false_conflict(): void
    {
        $this->actingAsAdmin();

        $this->post(route('employees.store'), $this->validStorePayload([
            'employee_number' => 'EMP-EXEMPT-1',
            'is_attendance_exempt' => true,
            'zkteco_user_id' => null,
        ]))->assertRedirect(route('employees.index'))->assertSessionHasNoErrors();

        // El segundo exento (también sin ZKTeco) NO debe chocar contra el primero.
        $this->post(route('employees.store'), $this->validStorePayload([
            'employee_number' => 'EMP-EXEMPT-2',
            'is_attendance_exempt' => true,
            'zkteco_user_id' => null,
        ]))->assertRedirect(route('employees.index'))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('employees', ['employee_number' => 'EMP-EXEMPT-1', 'is_attendance_exempt' => true]);
        $this->assertDatabaseHas('employees', ['employee_number' => 'EMP-EXEMPT-2', 'is_attendance_exempt' => true]);
    }

    public function test_non_exempt_store_still_requires_zkteco(): void
    {
        $this->actingAsAdmin();

        $payload = $this->validStorePayload(['zkteco_user_id' => null]);

        $this->from(route('employees.create'))
            ->post(route('employees.store'), $payload)
            ->assertSessionHasErrors(['zkteco_user_id']);
    }

    public function test_store_defaults_exempt_flag_to_false(): void
    {
        $this->actingAsAdmin();

        $this->post(route('employees.store'), $this->validStorePayload())
            ->assertRedirect(route('employees.index'));

        $employee = Employee::where('employee_number', 'EMP-9001')->firstOrFail();
        $this->assertFalse((bool) $employee->is_attendance_exempt);
    }

    public function test_rrhh_cannot_set_exempt_flag(): void
    {
        $this->actingAsRrhh();

        $payload = $this->validStorePayload([
            'employee_number' => 'EMP-RRHH-EX',
            'is_attendance_exempt' => true,
        ]);

        $this->post(route('employees.store'), $payload)
            ->assertRedirect(route('employees.index'));

        $employee = Employee::where('employee_number', 'EMP-RRHH-EX')->firstOrFail();
        $this->assertFalse((bool) $employee->is_attendance_exempt, 'RRHH no puede marcar "No checa"');
    }

    public function test_admin_can_update_employee_to_exempt(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->create([
            'is_attendance_exempt' => false,
            'zkteco_user_id' => 7777,
        ]);

        // Reutiliza el horario/depto/puesto del empleado para NO disparar el 2FA
        // (solo lo exige un cambio de horario o de conceptos). Cambiar la
        // bandera y quitar el ZKTeco no es "sensible".
        $payload = [
            'employee_number' => $employee->employee_number,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'hire_date' => $employee->hire_date->toDateString(),
            'department_id' => $employee->department_id,
            'position_id' => $employee->position_id,
            'schedule_id' => $employee->schedule_id,
            'daily_salary' => (float) $employee->daily_salary_computed,
            'vacation_days_entitled' => 12,
            'vacation_days_used' => 0,
            'status' => 'active',
            'is_attendance_exempt' => true,
            'zkteco_user_id' => null,
        ];

        $this->put(route('employees.update', $employee), $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('employees.index'));

        $this->assertTrue((bool) $employee->fresh()->is_attendance_exempt);
        $this->assertNull($employee->fresh()->zkteco_user_id);
    }
}
