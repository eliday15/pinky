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

    public function test_store_generates_general_and_taller_in_one_shot(): void
    {
        // Un solo alta genera AMBAS nóminas (General + Taller), ya calculadas,
        // con cada empleado en la que le toca.
        $taller = Department::factory()->separatePayroll()->create(['name' => 'Taller']);
        $normal = Department::factory()->create(['name' => 'Corte']);
        $tallerEmp = Employee::factory()->create(['status' => 'active', 'daily_salary' => 800, 'department_id' => $taller->id]);
        $normalEmp = Employee::factory()->create(['status' => 'active', 'daily_salary' => 800, 'department_id' => $normal->id]);
        $this->actingAsAdmin();

        $this->post(route('payroll.store'), [
            'name' => 'Semana 28',
            'type' => 'weekly',
            'start_date' => '2026-07-06',
            'end_date' => '2026-07-12',
            'payment_date' => '2026-07-13',
        ])->assertSessionHasNoErrors();

        $this->assertEquals(2, PayrollPeriod::count(), 'se crean exactamente 2 nóminas');

        $general = PayrollPeriod::whereNull('department_id')->firstOrFail();
        $tallerPeriod = PayrollPeriod::where('department_id', $taller->id)->firstOrFail();

        // Ambas ya calculadas (review) y nombradas.
        $this->assertEquals('review', $general->status);
        $this->assertEquals('review', $tallerPeriod->status);
        $this->assertEquals('Semana 28', $general->name);
        $this->assertEquals('Semana 28 - Taller', $tallerPeriod->name);

        // Partición: cada empleado en su nómina, no en la otra.
        $this->assertTrue($general->entries()->where('employee_id', $normalEmp->id)->exists());
        $this->assertFalse($general->entries()->where('employee_id', $tallerEmp->id)->exists());
        $this->assertTrue($tallerPeriod->entries()->where('employee_id', $tallerEmp->id)->exists());
        $this->assertFalse($tallerPeriod->entries()->where('employee_id', $normalEmp->id)->exists());
    }

    public function test_store_second_alta_same_dates_is_rejected(): void
    {
        Department::factory()->separatePayroll()->create(['name' => 'Taller']);
        $this->actingAsAdmin();

        $payload = [
            'name' => 'Semana 28',
            'type' => 'weekly',
            'start_date' => '2026-07-06',
            'end_date' => '2026-07-12',
            'payment_date' => '2026-07-13',
        ];

        $this->post(route('payroll.store'), $payload)->assertSessionHasNoErrors();
        $this->assertEquals(2, PayrollPeriod::count());

        // Segundo alta con las mismas fechas: General y Taller ya existen → error,
        // no se duplica nada.
        $this->from(route('payroll.create'))
            ->post(route('payroll.store'), $payload)
            ->assertSessionHasErrors(['start_date']);

        $this->assertEquals(2, PayrollPeriod::count());
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
