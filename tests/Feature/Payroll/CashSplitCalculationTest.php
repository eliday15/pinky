<?php

namespace Tests\Feature\Payroll;

use App\Models\AttendanceRecord;
use App\Models\CompensationType;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Services\PayrollCalculatorService;
use Tests\FeatureTestCase;

/**
 * El reparto efectivo/banco (cash_amount/bank_amount) y el filtro de conceptos
 * por payment_period. Ninguno altera regular_pay/gross_pay/net_pay.
 */
class CashSplitCalculationTest extends FeatureTestCase
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

    public function test_non_imss_weekly_pays_everything_in_cash(): void
    {
        $employee = Employee::factory()->create([
            'status' => 'active',
            'daily_salary' => 800.00,
            'is_imss_enrolled' => false,
        ]);
        $this->presentWeek($employee);

        $period = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($period, $employee);

        $this->assertEqualsWithDelta(5600.00, (float) $entry->net_pay, 0.01);
        $this->assertEqualsWithDelta(5600.00, (float) $entry->cash_amount, 0.01, 'todo el neto en efectivo');
        $this->assertEqualsWithDelta(0.00, (float) $entry->bank_amount, 0.01);
    }

    public function test_imss_enrolled_weekly_sends_base_to_bank_not_cash(): void
    {
        $employee = Employee::factory()->create([
            'status' => 'active',
            'daily_salary' => 800.00,
            'is_imss_enrolled' => true,
        ]);
        $this->presentWeek($employee);

        $period = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($period, $employee);

        // El base neto va por banco; no hay extras en semanal => efectivo 0.
        $this->assertEqualsWithDelta(5600.00, (float) $entry->net_pay, 0.01, 'net_pay no cambia');
        $this->assertEqualsWithDelta(5600.00, (float) $entry->bank_amount, 0.01, 'base neto al banco');
        $this->assertEqualsWithDelta(0.00, (float) $entry->cash_amount, 0.01, 'sin extras, sin efectivo');
    }

    public function test_imss_flag_does_not_change_regular_or_net_pay(): void
    {
        $base = [
            'status' => 'active',
            'daily_salary' => 800.00,
        ];

        $notEnrolled = Employee::factory()->create($base + ['is_imss_enrolled' => false]);
        $enrolled = Employee::factory()->create($base + ['is_imss_enrolled' => true]);
        $this->presentWeek($notEnrolled);
        $this->presentWeek($enrolled);

        $period = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
        ]);

        $a = $this->calculator()->calculateEmployeePayroll($period, $notEnrolled);
        $b = $this->calculator()->calculateEmployeePayroll($period, $enrolled);

        $this->assertEqualsWithDelta((float) $a->regular_pay, (float) $b->regular_pay, 0.01);
        $this->assertEqualsWithDelta((float) $a->gross_pay, (float) $b->gross_pay, 0.01);
        $this->assertEqualsWithDelta((float) $a->net_pay, (float) $b->net_pay, 0.01);
    }

    public function test_imss_enrolled_monthly_pays_extras_in_cash(): void
    {
        // Mensual sin base (regular_pay=0): el efectivo es todo el neto aunque
        // esté inscrito al IMSS — el IMSS solo desvía el base, que aquí es 0.
        $employee = Employee::factory()->create([
            'status' => 'active',
            'daily_salary' => 800.00,
            'is_imss_enrolled' => true,
        ]);

        $he = CompensationType::factory()->create([
            'code' => 'HE',
            'authorization_type' => 'overtime',
            'application_mode' => 'per_hour',
            'calculation_type' => 'fixed',
            'fixed_amount' => 150.00,
            'priority' => 10,
            'is_active' => true,
            'payment_period' => 'monthly',
        ]);
        $employee->compensationTypes()->attach($he->id, ['is_active' => true]);

        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-06-03',
            'status' => 'present',
            'worked_hours' => 8.00,
            'overtime_hours' => 2.00,
            'overtime_authorized_hours' => 2.00,
        ]);

        $period = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($period, $employee);

        $this->assertEqualsWithDelta(300.00, (float) $entry->overtime_pay, 0.01, '2h × $150');
        $this->assertEqualsWithDelta((float) $entry->net_pay, (float) $entry->cash_amount, 0.01, 'extras al efectivo');
        $this->assertEqualsWithDelta(0.00, (float) $entry->bank_amount, 0.01, 'sin base mensual, nada al banco');
    }

    public function test_weekly_marked_concept_pays_in_weekly_period(): void
    {
        $employee = Employee::factory()->create([
            'status' => 'active',
            'daily_salary' => 800.00,
        ]);

        $he = CompensationType::factory()->create([
            'code' => 'HE',
            'authorization_type' => 'overtime',
            'application_mode' => 'per_hour',
            'calculation_type' => 'fixed',
            'fixed_amount' => 150.00,
            'priority' => 10,
            'is_active' => true,
            'payment_period' => 'weekly',
        ]);
        $employee->compensationTypes()->attach($he->id, ['is_active' => true]);

        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-06-03',
            'status' => 'present',
            'worked_hours' => 8.00,
            'overtime_hours' => 2.00,
            'overtime_authorized_hours' => 2.00,
        ]);

        $weekly = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($weekly, $employee);

        // El concepto marcado 'weekly' SÍ paga en la semanal (overtime 2h × $150).
        $this->assertEqualsWithDelta(300.00, (float) $entry->overtime_pay, 0.01, 'concepto weekly paga en semanal');
    }

    public function test_weekly_marked_concept_does_not_pay_in_monthly_period(): void
    {
        $employee = Employee::factory()->create([
            'status' => 'active',
            'daily_salary' => 800.00,
        ]);

        $he = CompensationType::factory()->create([
            'code' => 'HE',
            'authorization_type' => 'overtime',
            'application_mode' => 'per_hour',
            'calculation_type' => 'fixed',
            'fixed_amount' => 150.00,
            'priority' => 10,
            'is_active' => true,
            'payment_period' => 'weekly',
        ]);
        $employee->compensationTypes()->attach($he->id, ['is_active' => true]);

        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-06-03',
            'status' => 'present',
            'worked_hours' => 8.00,
            'overtime_hours' => 2.00,
            'overtime_authorized_hours' => 2.00,
        ]);

        $monthly = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($monthly, $employee);

        // Marcado 'weekly' => NO paga en la mensual.
        $this->assertEqualsWithDelta(0.00, (float) $entry->overtime_pay, 0.01, 'concepto weekly no paga en mensual');
    }
}
