<?php

namespace Tests\Feature\Payroll;

use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\User;
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

    public function test_recurring_concept_is_paid_in_cash(): void
    {
        // Requisito de Luis (2026-07-09): "se paga en efectivo ese recurrente".
        // El recurrente es un EXTRA y los extras SIEMPRE salen en efectivo; el
        // sueldo base va al banco para un empleado FORMALIZADO (IMSS + número +
        // sin periodo de prueba).
        $employee = Employee::factory()->create([
            'status' => 'active',
            'daily_salary' => 800.00,
            'hourly_rate' => 100.00,
            'hire_date' => '2025-01-01',
            'is_imss_enrolled' => true,
            'imss_number' => '75-18-04-2297-6',
            'is_trial_period' => false,
        ]);
        $type = $this->recurringType(150.00, CompensationType::PAYMENT_PERIOD_WEEKLY);
        $employee->compensationTypes()->attach($type->id, ['is_active' => true]);

        $entry = $this->calculator()->calculateEmployeePayroll($this->weekly(), $employee);

        $this->assertEqualsWithDelta(150.00, (float) $entry->cash_amount, 0.01, 'el recurrente sale en efectivo');
        $this->assertEqualsWithDelta(5600.00, (float) $entry->bank_amount, 0.01, 'el sueldo base va al banco (no el recurrente)');
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
        $concept = collect($row['extra_concepts'])->firstWhere('name', 'Ayuda de transporte');

        $this->assertNotNull($concept, 'el recurrente semanal aparece en Otros Conceptos');
        $this->assertEqualsWithDelta(150.00, (float) $concept['amount'], 0.01, 'muestra el valor en pesos del concepto');
    }

    public function test_te_report_shows_concept_value_for_bonos_and_deduction(): void
    {
        // Valor en pesos (Luis 2026-07-09): un bono por unidades (Producción,
        // one_time $1 × 600 bonos) muestra $600; un descuento recurrente
        // (Infonavit -$300) muestra el negativo.
        $department = Department::factory()->create(['name' => 'Producción', 'code' => 'PROD']);
        $employee = Employee::factory()->create([
            'status' => 'active',
            'department_id' => $department->id,
            'daily_salary' => 800.00,
            'hire_date' => '2025-01-01',
        ]);

        $bono = CompensationType::factory()->fixed(1.00)->create([
            'name' => 'Producción',
            'code' => 'PROD-BONO',
            'application_mode' => CompensationType::APPLICATION_ONE_TIME,
            'authorization_type' => Authorization::TYPE_SPECIAL,
        ]);
        Authorization::create([
            'employee_id' => $employee->id,
            'requested_by' => User::factory()->create()->id,
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $bono->id,
            'date' => '2026-06-03',
            'hours' => 600, // 600 bonos
            'reason' => 'destajo',
            'status' => Authorization::STATUS_APPROVED,
        ]);

        $infonavit = CompensationType::factory()->fixed(-300.00)->create([
            'name' => 'Descuento Infonavit',
            'code' => 'DED-INFO',
            'application_mode' => CompensationType::APPLICATION_ONE_TIME,
            'payment_period' => CompensationType::PAYMENT_PERIOD_WEEKLY,
            'is_recurring' => true,
        ]);
        $employee->compensationTypes()->attach($infonavit->id, ['is_active' => true]);

        $report = app(WeeklyOvertimeReportService::class)
            ->buildReport($department, Carbon::parse('2026-06-01'));

        $row = collect($report['rows'])->firstWhere('employee.id', $employee->id);
        $concepts = collect($row['extra_concepts'])->keyBy('name');

        $this->assertEqualsWithDelta(600.00, (float) $concepts['Producción']['amount'], 0.01, '$1 × 600 bonos');
        $this->assertEqualsWithDelta(-300.00, (float) $concepts['Descuento Infonavit']['amount'], 0.01, 'el descuento va en negativo');
    }

    // ------------------------------------------------------------------
    // DEDUCCIONES: concepto recurrente con monto NEGATIVO (Infonavit,
    // préstamos) que resta del EFECTIVO, topado al efectivo disponible.
    // ------------------------------------------------------------------

    /** Empleado que cobra TODO en efectivo (prueba + sin IMSS). */
    private function cashEmployee(): Employee
    {
        return Employee::factory()->create([
            'status' => 'active',
            'daily_salary' => 800.00,
            'hourly_rate' => 100.00,
            'hire_date' => '2025-01-01',
            'is_trial_period' => true,
            'is_imss_enrolled' => false,
        ]);
    }

    /** Empleado FORMALIZADO (IMSS + número + sin prueba): base al banco. */
    private function bankEmployee(): Employee
    {
        return Employee::factory()->create([
            'status' => 'active',
            'daily_salary' => 800.00,
            'hourly_rate' => 100.00,
            'hire_date' => '2025-01-01',
            'is_imss_enrolled' => true,
            'imss_number' => '75-18-04-2297-6',
            'is_trial_period' => false,
        ]);
    }

    public function test_negative_concept_deducts_from_cash(): void
    {
        // Empleado en efectivo: base 5600 en efectivo, Infonavit -300 →
        // efectivo 5300, neto 5300, banco 0.
        $employee = $this->cashEmployee();
        $infonavit = $this->recurringType(-300.00, CompensationType::PAYMENT_PERIOD_WEEKLY);
        $employee->compensationTypes()->attach($infonavit->id, ['is_active' => true]);

        $entry = $this->calculator()->calculateEmployeePayroll($this->weekly(), $employee);

        $this->assertEqualsWithDelta(5300.00, (float) $entry->net_pay, 0.01, 'el neto baja por la deducción');
        $this->assertEqualsWithDelta(5300.00, (float) $entry->cash_amount, 0.01, 'sale del efectivo');
        $this->assertEqualsWithDelta(0.00, (float) $entry->bank_amount, 0.01);
    }

    public function test_deduction_is_capped_at_available_cash_never_negative(): void
    {
        // Deducción que EXCEDE el efectivo (−6000 sobre 5600): se aplica solo lo
        // que alcanza; el efectivo y el neto nunca quedan negativos.
        $employee = $this->cashEmployee();
        $prestamo = $this->recurringType(-6000.00, CompensationType::PAYMENT_PERIOD_WEEKLY);
        $employee->compensationTypes()->attach($prestamo->id, ['is_active' => true]);

        $entry = $this->calculator()->calculateEmployeePayroll($this->weekly(), $employee);

        $this->assertEqualsWithDelta(0.00, (float) $entry->cash_amount, 0.01, 'no rebasa: efectivo en 0');
        $this->assertEqualsWithDelta(0.00, (float) $entry->net_pay, 0.01);
        $this->assertGreaterThanOrEqual(0.0, (float) $entry->cash_amount);
    }

    public function test_deduction_comes_from_cash_not_from_bank(): void
    {
        // IMSS: base 5600 al banco. Percepción +500 (efectivo) y deducción −300.
        // Banco intacto (5600), efectivo 500−300=200, neto 5800.
        $employee = $this->bankEmployee();
        $bono = $this->recurringType(500.00, CompensationType::PAYMENT_PERIOD_WEEKLY);
        $prestamo = $this->recurringType(-300.00, CompensationType::PAYMENT_PERIOD_WEEKLY);
        $employee->compensationTypes()->attach($bono->id, ['is_active' => true]);
        $employee->compensationTypes()->attach($prestamo->id, ['is_active' => true]);

        $entry = $this->calculator()->calculateEmployeePayroll($this->weekly(), $employee);

        $this->assertEqualsWithDelta(5600.00, (float) $entry->bank_amount, 0.01, 'la transferencia (base) no se toca');
        $this->assertEqualsWithDelta(200.00, (float) $entry->cash_amount, 0.01, 'la deducción sale del efectivo');
        $this->assertEqualsWithDelta(5800.00, (float) $entry->net_pay, 0.01);
    }

    public function test_deduction_not_applied_when_there_is_no_cash(): void
    {
        // IMSS sin extras en efectivo: no hay de dónde descontar → la deducción
        // no se aplica (no toca el banco). El neto queda igual al base.
        $employee = $this->bankEmployee();
        $infonavit = $this->recurringType(-300.00, CompensationType::PAYMENT_PERIOD_WEEKLY);
        $employee->compensationTypes()->attach($infonavit->id, ['is_active' => true]);

        $entry = $this->calculator()->calculateEmployeePayroll($this->weekly(), $employee);

        $this->assertEqualsWithDelta(5600.00, (float) $entry->net_pay, 0.01, 'sin efectivo no se descuenta');
        $this->assertEqualsWithDelta(5600.00, (float) $entry->bank_amount, 0.01, 'el banco no se toca');
        $this->assertEqualsWithDelta(0.00, (float) $entry->cash_amount, 0.01);
    }

    public function test_per_employee_negative_amount_deducts(): void
    {
        // El monto de la deducción por empleado (pivot) manda: Infonavit global
        // -100 pero a este empleado -450.
        $employee = $this->cashEmployee();
        $infonavit = $this->recurringType(-100.00, CompensationType::PAYMENT_PERIOD_WEEKLY);
        $employee->compensationTypes()->attach($infonavit->id, [
            'is_active' => true,
            'custom_fixed_amount' => -450.00,
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($this->weekly(), $employee);

        $this->assertEqualsWithDelta(5150.00, (float) $entry->net_pay, 0.01, '5600 - 450');
        $this->assertEqualsWithDelta(5150.00, (float) $entry->cash_amount, 0.01);
    }
}
