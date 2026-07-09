<?php

namespace Tests\Feature\Payroll;

use App\Models\CompensationType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Services\PayrollCalculatorService;
use App\Services\Reports\WeeklyOvertimeReportService;
use Carbon\Carbon;
use Tests\FeatureTestCase;

/**
 * Conceptos RECURRENTES (Luis/fábrica 2026-07-09): una cantidad fija que se le
 * da al empleado CADA periodo (semanal o mensual) de forma automática, sin
 * autorización ni condición de asistencia.
 */
class RecurringCompensationTest extends FeatureTestCase
{
    private function calculator(): PayrollCalculatorService
    {
        return app(PayrollCalculatorService::class);
    }

    private function employee(): Employee
    {
        return Employee::factory()->create([
            'status' => 'active',
            'daily_salary' => 800.00,
            'hourly_rate' => 100.00,
            'hire_date' => '2025-01-01',
        ]);
    }

    /** Concepto recurrente a monto fijo, con periodo de pago dado. */
    private function recurringType(float $amount, string $paymentPeriod): CompensationType
    {
        return CompensationType::factory()->fixed($amount)->create([
            'application_mode' => CompensationType::APPLICATION_ONE_TIME,
            'payment_period' => $paymentPeriod,
            'is_recurring' => true,
        ]);
    }

    private function weekly(): PayrollPeriod
    {
        return PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
        ]);
    }

    private function monthly(): PayrollPeriod
    {
        return PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);
    }

    public function test_recurring_weekly_concept_pays_automatically_without_authorization(): void
    {
        $employee = $this->employee();
        $type = $this->recurringType(150.00, CompensationType::PAYMENT_PERIOD_WEEKLY);
        $employee->compensationTypes()->attach($type->id, ['is_active' => true]);

        $entry = $this->calculator()->calculateEmployeePayroll($this->weekly(), $employee);

        $this->assertEqualsWithDelta(150.00, (float) $entry->other_compensation_pay, 0.01, 'la cantidad fija semanal se paga sola');
        $concepts = collect($entry->calculation_breakdown['compensation_concepts'] ?? []);
        $this->assertTrue(
            $concepts->contains(fn ($c) => ($c['source'] ?? null) === 'recurring' && abs((float) $c['amount'] - 150.0) < 0.01),
            'el concepto recurrente aparece en el desglose',
        );
    }

    public function test_recurring_weekly_concept_does_not_pay_in_monthly_period(): void
    {
        $employee = $this->employee();
        $type = $this->recurringType(150.00, CompensationType::PAYMENT_PERIOD_WEEKLY);
        $employee->compensationTypes()->attach($type->id, ['is_active' => true]);

        $entry = $this->calculator()->calculateEmployeePayroll($this->monthly(), $employee);

        $this->assertEqualsWithDelta(0.00, (float) $entry->other_compensation_pay, 0.01, 'un concepto semanal no cae en la nómina mensual');
    }

    public function test_recurring_monthly_concept_pays_in_monthly_period(): void
    {
        $employee = $this->employee();
        $type = $this->recurringType(500.00, CompensationType::PAYMENT_PERIOD_MONTHLY);
        $employee->compensationTypes()->attach($type->id, ['is_active' => true]);

        $entry = $this->calculator()->calculateEmployeePayroll($this->monthly(), $employee);

        $this->assertEqualsWithDelta(500.00, (float) $entry->other_compensation_pay, 0.01);
    }

    public function test_per_employee_custom_amount_overrides_global(): void
    {
        $employee = $this->employee();
        $type = $this->recurringType(150.00, CompensationType::PAYMENT_PERIOD_WEEKLY);
        $employee->compensationTypes()->attach($type->id, [
            'is_active' => true,
            'custom_fixed_amount' => 220.00,
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($this->weekly(), $employee);

        $this->assertEqualsWithDelta(220.00, (float) $entry->other_compensation_pay, 0.01, 'el monto por empleado manda sobre el global');
    }

    public function test_inactive_assignment_does_not_pay(): void
    {
        $employee = $this->employee();
        $type = $this->recurringType(150.00, CompensationType::PAYMENT_PERIOD_WEEKLY);
        $employee->compensationTypes()->attach($type->id, ['is_active' => false]);

        $entry = $this->calculator()->calculateEmployeePayroll($this->weekly(), $employee);

        $this->assertEqualsWithDelta(0.00, (float) $entry->other_compensation_pay, 0.01);
    }

    public function test_non_recurring_concept_does_not_auto_pay(): void
    {
        // Regresión: un concepto NO recurrente asignado sigue necesitando una
        // autorización para pagar; no cae solo.
        $employee = $this->employee();
        $type = CompensationType::factory()->fixed(150.00)->create([
            'application_mode' => CompensationType::APPLICATION_ONE_TIME,
            'payment_period' => CompensationType::PAYMENT_PERIOD_WEEKLY,
            'is_recurring' => false,
        ]);
        $employee->compensationTypes()->attach($type->id, ['is_active' => true]);

        $entry = $this->calculator()->calculateEmployeePayroll($this->weekly(), $employee);

        $this->assertEqualsWithDelta(0.00, (float) $entry->other_compensation_pay, 0.01, 'sin recurrente ni autorización no paga');
    }

    public function test_recurring_weekly_concept_shows_in_te_report_otros_conceptos(): void
    {
        $department = Department::factory()->create(['name' => 'Producción', 'code' => 'PROD']);
        $employee = Employee::factory()->create([
            'status' => 'active',
            'department_id' => $department->id,
            'daily_salary' => 800.00,
            'hire_date' => '2025-01-01',
        ]);
        $type = CompensationType::factory()->fixed(150.00)->create([
            'name' => 'Ayuda de transporte',
            'application_mode' => CompensationType::APPLICATION_ONE_TIME,
            'payment_period' => CompensationType::PAYMENT_PERIOD_WEEKLY,
            'is_recurring' => true,
        ]);
        $employee->compensationTypes()->attach($type->id, ['is_active' => true]);

        $report = app(WeeklyOvertimeReportService::class)
            ->buildReport($department, Carbon::parse('2026-06-01'));

        $row = collect($report['rows'])->firstWhere('employee.id', $employee->id);
        $names = collect($row['extra_concepts'])->pluck('name');

        $this->assertTrue($names->contains('Ayuda de transporte'), 'el recurrente semanal aparece en Otros Conceptos');
    }
}
