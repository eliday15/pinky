<?php

namespace Tests\Feature\Payroll;

use App\Models\Employee;
use App\Models\EmployeeAnnualTotal;
use App\Models\PayrollPeriod;
use App\Services\Fiscal\EmployeeAnnualTotalService;
use App\Services\PayrollCalculatorService;
use Tests\FeatureTestCase;

/**
 * Acumulados anuales por empleado: se reconstruyen desde los periodos
 * semanales aprobados (rebuild idempotente, insumo del ajuste anual Art. 97).
 */
class EmployeeAnnualTotalTest extends FeatureTestCase
{
    public function test_rebuild_accumulates_approved_weekly_periods(): void
    {
        $employee = Employee::factory()->create([
            'status' => 'active',
            'daily_salary' => 800.00,
            'hire_date' => '2025-01-01',
            'is_attendance_exempt' => true,
        ]);

        // Dos semanas aprobadas + una en review (no cuenta).
        foreach ([['2026-06-01', '2026-06-07', 'approved'], ['2026-06-08', '2026-06-14', 'approved'], ['2026-06-15', '2026-06-21', 'review']] as [$start, $end, $status]) {
            $period = PayrollPeriod::factory()->weekly()->create([
                'start_date' => $start,
                'end_date' => $end,
                'status' => 'draft',
            ]);
            app(PayrollCalculatorService::class)->calculateEmployeePayroll($period, $employee);
            $period->update(['status' => $status]);
        }

        $count = app(EmployeeAnnualTotalService::class)->rebuildYear(2026);
        $this->assertGreaterThanOrEqual(1, $count);

        $total = EmployeeAnnualTotal::where('employee_id', $employee->id)->where('year', 2026)->first();
        $this->assertNotNull($total);
        // 2 semanas × SD×7 = 11,200 gravado; 14 días.
        $this->assertEqualsWithDelta(11200.00, (float) $total->taxable_income, 0.01);
        $this->assertEqualsWithDelta(14.0, (float) $total->days_paid, 0.01);

        // Idempotente: reconstruir de nuevo no duplica.
        app(EmployeeAnnualTotalService::class)->rebuildYear(2026);
        $total->refresh();
        $this->assertEqualsWithDelta(11200.00, (float) $total->taxable_income, 0.01);
    }

    public function test_external_imported_columns_survive_rebuild(): void
    {
        $employee = Employee::factory()->create([
            'status' => 'active',
            'daily_salary' => 500.00,
            'hire_date' => '2025-01-01',
        ]);

        EmployeeAnnualTotal::create([
            'employee_id' => $employee->id,
            'year' => 2026,
            'external_taxable_income' => 50000.00,
            'external_isr_withheld' => 3500.00,
        ]);

        app(EmployeeAnnualTotalService::class)->rebuildYear(2026);

        $total = EmployeeAnnualTotal::where('employee_id', $employee->id)->where('year', 2026)->first();
        $this->assertEqualsWithDelta(50000.00, (float) $total->external_taxable_income, 0.01, 'el import de Contpaq no se pisa');
        $this->assertEqualsWithDelta(53500.00 - 3500.00 + 3500.00, $total->totalTaxable() + $total->totalIsrWithheld() - (float) $total->isr_withheld - (float) $total->taxable_income, 0.01);
    }
}
