<?php

namespace Tests\Feature\Payroll;

use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PayrollCfdi;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\Schedule;
use App\Services\PayrollCalculatorService;
use Tests\FeatureTestCase;

/**
 * Deducciones por falta que quedaron obsoletas (caso Elsa, 2026-09-23).
 *
 * La nómina se calcula con una checada incompleta, el sync corrige la checada
 * después y — como el periodo ya se pagó — el descuento se queda congelado. El
 * empleado aparece con la checada bien en Asistencia y con la falta en su
 * recibo. `payroll:reconcile-stale` detecta y corrige exactamente eso.
 */
class StaleAbsenceReconciliationTest extends FeatureTestCase
{
    private const ABSENCE_DATE = '2026-06-03'; // miércoles

    private function calculator(): PayrollCalculatorService
    {
        return app(PayrollCalculatorService::class);
    }

    private function employee(): Employee
    {
        $schedule = Schedule::factory()->create([
            'daily_work_hours' => 8,
            'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'],
        ]);

        return Employee::factory()->create([
            'status' => 'active',
            'hire_date' => '2025-01-01',
            'schedule_id' => $schedule->id,
            'daily_salary' => 200.00,
        ]);
    }

    /**
     * Escenario real: el día quedó como falta, se calculó y se pagó la nómina,
     * y DESPUÉS llegó la checada que prueba que sí trabajó.
     *
     * @return array{0: PayrollPeriod, 1: PayrollEntry, 2: AttendanceRecord}
     */
    private function staleScenario(): array
    {
        $employee = $this->employee();

        $record = AttendanceRecord::factory()->for($employee)->create([
            'work_date' => self::ABSENCE_DATE,
            'status' => 'absent',
            'worked_hours' => 0,
        ]);

        $period = PayrollPeriod::factory()->weekly()->paid()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($period, $employee);
        $this->assertEqualsWithDelta(233.33, (float) $entry->deductions, 0.01, 'precondición: la falta descontó');

        // La checada se corrige DESPUÉS de pagar la nómina.
        $record->update([
            'status' => 'present',
            'check_in' => '08:00:00',
            'check_out' => '17:00:00',
            'worked_hours' => 8.00,
        ]);

        return [$period, $entry, $record];
    }

    public function test_dry_run_reports_the_stale_deduction_without_touching_the_entry(): void
    {
        [, $entry] = $this->staleScenario();

        $this->artisan('payroll:reconcile-stale')
            ->expectsOutputToContain('1 recibo(s) con falta obsoleta')
            ->assertSuccessful();

        $this->assertEqualsWithDelta(233.33, (float) $entry->fresh()->deductions, 0.01, 'sin --apply no se escribe nada');
        $this->assertEqualsWithDelta(0.00, (float) $entry->fresh()->net_pay - 1166.67, 0.01);
    }

    public function test_apply_recalculates_the_entry_and_leaves_an_audit_trail(): void
    {
        [, $entry] = $this->staleScenario();

        $this->artisan('payroll:reconcile-stale --apply')
            ->expectsOutputToContain('Recibos recalculados: 1')
            ->assertSuccessful();

        $entry->refresh();

        $this->assertEqualsWithDelta(0.00, (float) $entry->deductions, 0.01, 'la falta obsoleta desaparece');
        $this->assertEqualsWithDelta(1400.00, (float) $entry->net_pay, 0.01, 'se le devuelve el día + el séptimo día');
        $this->assertSame(0, (int) $entry->days_absent);
        $this->assertSame([], collect($entry->calculation_breakdown['deduction_detail'] ?? [])->all());

        $this->assertDatabaseHas('audit_logs', [
            'module' => AuditLog::MODULE_PAYROLL,
            'action' => AuditLog::ACTION_RECALCULATE,
        ]);
    }

    public function test_it_ignores_entries_whose_attendance_still_shows_an_absence(): void
    {
        $employee = $this->employee();

        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => self::ABSENCE_DATE,
            'status' => 'absent',
            'worked_hours' => 0,
        ]);

        $period = PayrollPeriod::factory()->weekly()->paid()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
        ]);

        $this->calculator()->calculateEmployeePayroll($period, $employee);

        // La checada SIGUE faltando: la falta es legítima, no se toca.
        $this->artisan('payroll:reconcile-stale')
            ->expectsOutputToContain('Sin deducciones obsoletas')
            ->assertSuccessful();
    }

    public function test_it_leaves_alone_an_absent_day_that_has_worked_hours(): void
    {
        $employee = $this->employee();

        // Llegó muy tarde / salió muy temprano: el día tiene horas trabajadas
        // pero la falta es correcta por regla del sync. Tomar las horas como
        // "sí trabajó" marcaría como error 61 recibos sanos.
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => self::ABSENCE_DATE,
            'status' => 'absent',
            'check_in' => '15:00:00',
            'check_out' => '17:00:00',
            'worked_hours' => 2.00,
        ]);

        $period = PayrollPeriod::factory()->weekly()->paid()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($period, $employee);
        $this->assertGreaterThan(0.0, (float) $entry->deductions, 'precondición: el día ausente descuenta');

        $this->artisan('payroll:reconcile-stale')
            ->expectsOutputToContain('Sin deducciones obsoletas')
            ->assertSuccessful();

        $this->assertGreaterThan(0.0, (float) $entry->fresh()->deductions);
    }

    public function test_it_does_not_touch_the_late_accumulation_sanction(): void
    {
        $employee = $this->employee();

        // Día trabajado con FRT: la deducción es deliberada, no un dato obsoleto.
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => self::ABSENCE_DATE,
            'status' => 'present',
            'check_in' => '08:00:00',
            'check_out' => '17:00:00',
            'worked_hours' => 8.00,
        ]);

        $period = PayrollPeriod::factory()->weekly()->paid()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
        ]);

        $entry = PayrollEntry::factory()->for($period)->for($employee)->create([
            'deductions' => 233.33,
            'net_pay' => 1166.67,
            'calculation_breakdown' => [
                'deduction_detail' => [
                    ['date' => self::ABSENCE_DATE, 'days' => 1, 'reason' => 'Falta por acumulación de retardos'],
                ],
            ],
        ]);

        $this->artisan('payroll:reconcile-stale')
            ->expectsOutputToContain('Sin deducciones obsoletas')
            ->assertSuccessful();

        $this->assertEqualsWithDelta(233.33, (float) $entry->fresh()->deductions, 0.01);
    }

    public function test_it_skips_periods_with_stamped_cfdi(): void
    {
        [$period, $entry] = $this->staleScenario();

        PayrollCfdi::create([
            'payroll_entry_id' => $entry->id,
            'status' => PayrollCfdi::STATUS_STAMPED,
            'uuid' => '11111111-2222-3333-4444-555555555555',
        ]);

        $this->artisan('payroll:reconcile-stale --apply')
            ->expectsOutputToContain('Omitidos por CFDI timbrado: 1')
            ->assertSuccessful();

        $this->assertEqualsWithDelta(233.33, (float) $entry->fresh()->deductions, 0.01, 'un CFDI timbrado no se reescribe');
        $this->assertSame('paid', $period->fresh()->status);
    }
}
