<?php

namespace Tests\Feature\Payroll;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Incident;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Services\PayrollCalculatorService;
use App\Services\PayrollShadowRecalcService;
use Tests\FeatureTestCase;

/**
 * Recálculo en sombra de periodos pagados (DECISIONES §9): el servicio corre
 * el MISMO camino de cálculo de producción dentro de una transacción que
 * siempre se revierte — reporta qué habría cambiado con las reglas nuevas
 * sin tocar jamás lo persistido (un periodo pagado es inmutable).
 */
class PayrollShadowRecalcTest extends FeatureTestCase
{
    private function shadow(): PayrollShadowRecalcService
    {
        return app(PayrollShadowRecalcService::class);
    }

    private function makeEmployee(): Employee
    {
        return Employee::factory()->create([
            'status' => 'active',
            'hourly_rate' => 100.00,
            'overtime_rate' => 1.5,
            'holiday_rate' => 2.0,
        ]);
    }

    /**
     * Miércoles a media semana (mismo motivo que PayrollSplitTest: evitar el
     * borde del periodo, donde SQLite compara DATE vs DATETIME como strings).
     */
    private function presentRecord(Employee $employee): AttendanceRecord
    {
        return AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-06-03',
            'status' => 'present',
            'worked_hours' => 8.00,
            'overtime_hours' => 0,
            'overtime_authorized_hours' => 0,
        ]);
    }

    private function paidWeeklyPeriod(): PayrollPeriod
    {
        return PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
            'status' => 'paid',
        ]);
    }

    /**
     * Entry "pagada con las reglas viejas": neto 500 donde la sombra (sueldo
     * diario 800 × 7 días, semana base) produce 5600. Todos los demás
     * conceptos en 0 para que el diff sea determinista.
     */
    private function staleEntry(PayrollPeriod $period, Employee $employee): PayrollEntry
    {
        $zeroed = array_fill_keys(PayrollShadowRecalcService::MONEY_FIELDS, 0);

        return PayrollEntry::factory()->create(array_merge($zeroed, [
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'hourly_rate' => 100.00,
            'regular_hours' => 5.00,
            'overtime_hours' => 0,
            'regular_pay' => 500.00,
            'gross_pay' => 500.00,
            'net_pay' => 500.00,
        ]));
    }

    public function test_reports_differences_without_modifying_the_paid_entry(): void
    {
        $employee = $this->makeEmployee();
        $this->presentRecord($employee);
        $period = $this->paidWeeklyPeriod();
        $entry = $this->staleEntry($period, $employee);

        $incidentsBefore = Incident::count();

        $diff = $this->shadow()->diffPeriod($period);

        // El diff detecta la diferencia 500 → 5600.
        $this->assertCount(1, $diff['rows']);
        $row = $diff['rows'][0];
        $this->assertSame($employee->id, $row['employee_id']);
        $this->assertEqualsWithDelta(500.00, $row['old_net'], 0.01);
        $this->assertEqualsWithDelta(5600.00, $row['new_net'], 0.01);
        $this->assertEqualsWithDelta(5100.00, $row['net_delta'], 0.01);
        $this->assertArrayHasKey('regular_pay', $row['fields']);
        $this->assertArrayHasKey('net_pay', $row['fields']);
        $this->assertEqualsWithDelta(5100.00, $diff['totals']['delta_net'], 0.01);

        // NADA persistió: ni la entry recalculada, ni incidencias FRT de
        // paso, ni el status del periodo.
        $entry->refresh();
        $this->assertEqualsWithDelta(500.00, (float) $entry->net_pay, 0.01, 'la entry pagada es inmutable');
        $this->assertEqualsWithDelta(500.00, (float) $entry->regular_pay, 0.01);
        $this->assertSame($incidentsBefore, Incident::count(), 'las FRT generadas en sombra se revierten');
        $this->assertSame('paid', $period->fresh()->status);
        $this->assertSame(1, PayrollEntry::where('payroll_period_id', $period->id)->count());
    }

    public function test_reports_no_rows_when_shadow_matches_paid(): void
    {
        $employee = $this->makeEmployee();
        $this->presentRecord($employee);

        // Periodo calculado con las reglas ACTUALES y luego pagado: la sombra
        // debe coincidir exactamente.
        $period = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
            'status' => 'draft',
        ]);
        app(PayrollCalculatorService::class)->calculateEmployeePayroll($period, $employee);
        $period->update(['status' => 'paid']);

        $diff = $this->shadow()->diffPeriod($period);

        $this->assertSame([], $diff['rows']);
        $this->assertSame(1, $diff['unchanged_count']);
        $this->assertEqualsWithDelta(0.00, $diff['totals']['delta_net'], 0.01);
    }

    public function test_rejects_unpaid_periods(): void
    {
        $period = PayrollPeriod::factory()->weekly()->create(['status' => 'draft']);

        $this->expectException(\InvalidArgumentException::class);

        $this->shadow()->diffPeriod($period);
    }

    public function test_command_reports_paid_periods_and_writes_csv(): void
    {
        $employee = $this->makeEmployee();
        $this->presentRecord($employee);
        $period = $this->paidWeeklyPeriod();
        $this->staleEntry($period, $employee);

        $csvPath = sys_get_temp_dir().'/shadow_recalc_test_'.uniqid().'.csv';

        try {
            $this->artisan('payroll:shadow-recalc', ['--csv' => $csvPath])
                ->assertExitCode(0);

            $this->assertFileExists($csvPath);
            $csv = file_get_contents($csvPath);
            $this->assertStringContainsString($employee->full_name, $csv);
            $this->assertStringContainsString('net_pay', $csv);

            // El comando tampoco persiste nada.
            $this->assertEqualsWithDelta(
                500.00,
                (float) PayrollEntry::where('payroll_period_id', $period->id)->first()->net_pay,
                0.01,
            );
        } finally {
            @unlink($csvPath);
        }
    }

    public function test_command_warns_when_no_paid_periods(): void
    {
        PayrollPeriod::factory()->weekly()->create(['status' => 'draft']);

        $this->artisan('payroll:shadow-recalc')
            ->expectsOutput('No hay periodos pagados que recalcular.')
            ->assertExitCode(0);
    }
}
