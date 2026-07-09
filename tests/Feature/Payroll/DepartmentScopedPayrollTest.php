<?php

namespace Tests\Feature\Payroll;

use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Services\PayrollCalculatorService;
use App\Services\PayrollInvalidationService;
use Tests\FeatureTestCase;

/**
 * Nómina por departamento: los deptos con nómina propia (Taller) salen de la
 * nómina general y se calculan/crean en su propio periodo. Partición limpia:
 * nadie cae en ambas.
 */
class DepartmentScopedPayrollTest extends FeatureTestCase
{
    private function calculator(): PayrollCalculatorService
    {
        return app(PayrollCalculatorService::class);
    }

    private function presentWeek(Employee $employee): void
    {
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-06-03', // Wednesday, mid-period
            'status' => 'present',
            'worked_hours' => 8.00,
        ]);
    }

    private function weeklyPeriod(?Department $department = null): PayrollPeriod
    {
        $factory = PayrollPeriod::factory()->weekly();

        if ($department) {
            $factory = $factory->forDepartment($department);
        }

        return $factory->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
        ]);
    }

    public function test_general_period_excludes_separate_payroll_employees(): void
    {
        $taller = Department::factory()->separatePayroll()->create(['name' => 'Taller']);
        $normal = Department::factory()->create(['name' => 'Corte']);

        $tallerEmp = Employee::factory()->create(['status' => 'active', 'daily_salary' => 800, 'department_id' => $taller->id]);
        $normalEmp = Employee::factory()->create(['status' => 'active', 'daily_salary' => 800, 'department_id' => $normal->id]);
        $this->presentWeek($tallerEmp);
        $this->presentWeek($normalEmp);

        $general = $this->weeklyPeriod();
        $this->calculator()->calculatePeriod($general);

        $employeeIds = $general->entries()->pluck('employee_id');

        $this->assertTrue($employeeIds->contains($normalEmp->id), 'la general incluye al de Corte');
        $this->assertFalse($employeeIds->contains($tallerEmp->id), 'la general NO incluye al de Taller');
    }

    public function test_department_period_includes_only_that_department(): void
    {
        $taller = Department::factory()->separatePayroll()->create(['name' => 'Taller']);
        $normal = Department::factory()->create(['name' => 'Corte']);

        $tallerEmp = Employee::factory()->create(['status' => 'active', 'daily_salary' => 800, 'department_id' => $taller->id]);
        $normalEmp = Employee::factory()->create(['status' => 'active', 'daily_salary' => 800, 'department_id' => $normal->id]);
        $this->presentWeek($tallerEmp);
        $this->presentWeek($normalEmp);

        $tallerPeriod = $this->weeklyPeriod($taller);
        $this->calculator()->calculatePeriod($tallerPeriod);

        $employeeIds = $tallerPeriod->entries()->pluck('employee_id');

        $this->assertTrue($employeeIds->contains($tallerEmp->id), 'la de Taller incluye al de Taller');
        $this->assertFalse($employeeIds->contains($normalEmp->id), 'la de Taller NO incluye al de Corte');
        $this->assertCount(1, $employeeIds);
    }

    public function test_store_allows_general_and_department_periods_same_dates(): void
    {
        $taller = Department::factory()->separatePayroll()->create(['name' => 'Taller']);
        $this->actingAsAdmin();

        // General
        $this->post(route('payroll.store'), [
            'name' => 'Semana 28',
            'type' => 'weekly',
            'start_date' => '2026-07-06',
            'end_date' => '2026-07-12',
            'payment_date' => '2026-07-13',
        ])->assertSessionHasNoErrors();

        // Taller, MISMAS fechas: no debe considerarse traslape.
        $this->post(route('payroll.store'), [
            'name' => 'Semana 28 - Taller',
            'type' => 'weekly',
            'department_id' => $taller->id,
            'start_date' => '2026-07-06',
            'end_date' => '2026-07-12',
            'payment_date' => '2026-07-13',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('payroll_periods', ['name' => 'Semana 28', 'department_id' => null]);
        $this->assertDatabaseHas('payroll_periods', ['name' => 'Semana 28 - Taller', 'department_id' => $taller->id]);
    }

    public function test_store_rejects_two_general_periods_same_dates(): void
    {
        $this->actingAsAdmin();

        $this->post(route('payroll.store'), [
            'name' => 'General A',
            'type' => 'weekly',
            'start_date' => '2026-07-06',
            'end_date' => '2026-07-12',
            'payment_date' => '2026-07-13',
        ])->assertSessionHasNoErrors();

        $this->from(route('payroll.create'))
            ->post(route('payroll.store'), [
                'name' => 'General B',
                'type' => 'weekly',
                'start_date' => '2026-07-06',
                'end_date' => '2026-07-12',
                'payment_date' => '2026-07-13',
            ])
            ->assertSessionHasErrors(['start_date']);
    }

    public function test_store_rejects_department_without_separate_payroll(): void
    {
        $normal = Department::factory()->create(['name' => 'Corte']);
        $this->actingAsAdmin();

        $this->from(route('payroll.create'))
            ->post(route('payroll.store'), [
                'name' => 'Corte aparte',
                'type' => 'weekly',
                'department_id' => $normal->id,
                'start_date' => '2026-07-06',
                'end_date' => '2026-07-12',
                'payment_date' => '2026-07-13',
            ])
            ->assertSessionHasErrors(['department_id']);
    }

    public function test_invalidation_targets_only_the_matching_scope(): void
    {
        $taller = Department::factory()->separatePayroll()->create(['name' => 'Taller']);
        $tallerEmp = Employee::factory()->create(['status' => 'active', 'daily_salary' => 800, 'department_id' => $taller->id]);
        $this->presentWeek($tallerEmp);

        // Dos periodos draft para las mismas fechas: general + Taller.
        $general = $this->weeklyPeriod();
        $tallerPeriod = $this->weeklyPeriod($taller);

        app(PayrollInvalidationService::class)->invalidate($tallerEmp->id, '2026-06-01', '2026-06-07');

        // El cambio del empleado de Taller solo toca el periodo de Taller.
        $this->assertDatabaseHas('payroll_entries', [
            'payroll_period_id' => $tallerPeriod->id,
            'employee_id' => $tallerEmp->id,
        ]);
        $this->assertDatabaseMissing('payroll_entries', [
            'payroll_period_id' => $general->id,
            'employee_id' => $tallerEmp->id,
        ]);
    }
}
