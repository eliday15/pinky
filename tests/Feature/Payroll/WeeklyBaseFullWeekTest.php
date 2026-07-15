<?php

namespace Tests\Feature\Payroll;

use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Services\PayrollCalculatorService;
use Tests\FeatureTestCase;

/**
 * Sueldo base semanal = semana completa (SD×7, séptimo día) SIEMPRE, sin
 * importar en qué día corte el periodo (decisión de negocio 2026-07-15). Las
 * faltas/extras de días posteriores al corte se ajustan en la semana siguiente
 * por fecha; la semana siguiente NO recorta días por lo ya pagado.
 */
class WeeklyBaseFullWeekTest extends FeatureTestCase
{
    private function calculator(): PayrollCalculatorService
    {
        return app(PayrollCalculatorService::class);
    }

    private function employee(string $hire = '2025-01-01'): Employee
    {
        return Employee::factory()->create([
            'status' => 'active',
            'daily_salary' => 1000.00,
            'hire_date' => $hire,
            'termination_date' => null,
        ]);
    }

    public function test_short_weekly_period_pays_full_week(): void
    {
        // Periodo de 6 días (1-6 jul), sin semana anterior: paga completa SD×7.
        $emp = $this->employee();
        $period = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-06',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($period, $emp);

        $this->assertEqualsWithDelta(7000.00, (float) $entry->regular_pay, 0.01, '6 días pero paga semana completa SD×7');
    }

    public function test_next_week_also_pays_full_week(): void
    {
        // La semana anterior (1-6) pagó completa hasta el día 7. La siguiente
        // TAMBIÉN paga SD×7: cada semana es completa sin importar el corte; los
        // ajustes de los días adelantados caen por fecha en su propio periodo.
        $emp = $this->employee();

        PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-06',
        ]);

        $current = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-07-07',
            'end_date' => '2026-07-13',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($current, $emp);

        $this->assertEqualsWithDelta(7000.00, (float) $entry->regular_pay, 0.01, 'la siguiente semana también paga SD×7 completa');
    }

    public function test_contiguous_full_weeks_are_unchanged(): void
    {
        // Semanas normales de 7 días contiguas: cada una paga SD×7, sin recorte.
        $emp = $this->employee();

        PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-24',
            'end_date' => '2026-06-30',
        ]);

        $current = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-07',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($current, $emp);

        $this->assertEqualsWithDelta(7000.00, (float) $entry->regular_pay, 0.01, 'semana completa contigua SD×7');
    }

    public function test_new_hire_midweek_is_prorated(): void
    {
        // Alta a media semana: aunque paga semana completa, se prorratea desde
        // el ingreso (no se paga antes de existir).
        $emp = $this->employee('2026-07-04'); // ingresa el sábado
        $period = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-06',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($period, $emp);

        // Ventana base 1-7; acotada al ingreso (4) → 4,5,6,7 = 4 días.
        $this->assertEqualsWithDelta(4000.00, (float) $entry->regular_pay, 0.01, 'prorrateo por alta se conserva');
    }

    public function test_monthly_period_base_is_unaffected(): void
    {
        // El tipo mensual no paga base (paga extras); regular_pay = 0 y la regla
        // de semana completa no lo toca.
        $emp = $this->employee();
        $period = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($period, $emp);

        $this->assertEqualsWithDelta(0.00, (float) $entry->regular_pay, 0.01, 'mensual no paga base');
    }
}
