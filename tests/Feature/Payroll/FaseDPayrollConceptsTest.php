<?php

namespace Tests\Feature\Payroll;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\PayrollPeriod;
use App\Models\Schedule;
use App\Services\PayrollCalculatorService;
use Tests\FeatureTestCase;

/**
 * Fase D + sueldo diario (Art. 72/90 LFT):
 *
 * - El sueldo se paga por DÍA: la semana vale sueldo_diario × 7 (séptimo día
 *   incluido). Las faltas injustificadas y la FRT descuentan el día + la parte
 *   proporcional del séptimo (SD × 7/D). Los días pagados aparte (vacación,
 *   incapacidad) o no pagados sin castigo (permiso sin goce) se restan del base.
 * - Prima vacacional: se paga con cada vacación como concepto separado.
 * - Incapacidades: con goce se pagan (mensual) y se restan del base; sin goce
 *   se restan del base sin castigo del séptimo día.
 * - count_mode por tipo: vacaciones en días hábiles, incapacidades en
 *   calendario — el mismo conteo en captura, saldo y nómina.
 */
class FaseDPayrollConceptsTest extends FeatureTestCase
{
    private function calculator(): PayrollCalculatorService
    {
        return app(PayrollCalculatorService::class);
    }

    private function employee(array $attrs = []): Employee
    {
        return Employee::factory()->create(array_merge([
            'status' => 'active',
            'hourly_rate' => 100.00, // sueldo diario 800
            'vacation_premium_percentage' => 25.00,
        ], $attrs));
    }

    private function typeWithCode(string $code, array $attributes): IncidentType
    {
        $existing = IncidentType::where('code', $code)->first();

        if ($existing) {
            $existing->update($attributes);

            return $existing->fresh();
        }

        return IncidentType::factory()->create(array_merge(['code' => $code], $attributes));
    }

    private function approvedIncident(Employee $employee, IncidentType $type, string $start, string $end, int $daysCount): Incident
    {
        return Incident::factory()->approved()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'start_date' => $start,
            'end_date' => $end,
            'days_count' => $daysCount,
        ]);
    }

    public function test_unjustified_absence_deducts_day_plus_seventh(): void
    {
        $employee = $this->employee();

        // Semana con 4 días trabajados y falta injustificada (FIN, sin goce) el
        // miércoles. Horario L-V (5 días), sueldo diario 800.
        foreach (['2026-06-01', '2026-06-02', '2026-06-04', '2026-06-05'] as $date) {
            AttendanceRecord::factory()->for($employee)->create([
                'work_date' => $date,
                'status' => 'present',
                'worked_hours' => 8.00,
            ]);
        }
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-06-03',
            'check_in' => null,
            'check_out' => null,
            'status' => 'absent',
            'worked_hours' => 0,
        ]);

        $fin = $this->typeWithCode('FIN', ['category' => 'absence', 'is_paid' => false]);
        $this->approvedIncident($employee, $fin, '2026-06-03', '2026-06-03', 1);

        $weekly = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($weekly, $employee);

        // El sueldo se paga por día: la semana (7 días) vale 800 × 7 = 5600, y
        // la falta injustificada descuenta el día + 1/6 del descanso (séptimo
        // día, divisor fijo 6): 800 × 7/6 = 933.33. Neto = 5600 − 933.33 = 4666.67.
        $this->assertEqualsWithDelta(5600.00, (float) $entry->regular_pay, 0.01, 'base = sueldo diario 800 × 7');
        $this->assertEqualsWithDelta(933.33, (float) $entry->deductions, 0.01, '1 falta × 800 × 7/6 (séptimo día)');
        $this->assertEqualsWithDelta(4666.67, (float) $entry->net_pay, 0.01);
        $this->assertSame(1, (int) $entry->days_absent, 'la falta sigue siendo visible');
    }

    public function test_unpaid_permission_is_not_paid_but_not_penalized(): void
    {
        $employee = $this->employee();

        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-06-02',
            'status' => 'present',
            'worked_hours' => 8.00,
        ]);

        $psg = $this->typeWithCode('PSG', ['category' => 'permission', 'is_paid' => false]);
        $this->approvedIncident($employee, $psg, '2026-06-03', '2026-06-03', 1);

        $weekly = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($weekly, $employee);

        // Permiso sin goce: el día NO se paga (se resta del base a monto plano),
        // pero NO castiga el séptimo día (no es falta). Base = 800 × (7 − 1) =
        // 4800; sin deducción.
        $this->assertEqualsWithDelta(4800.00, (float) $entry->regular_pay, 0.01, 'base = 800 × (7 − 1 permiso sin goce)');
        $this->assertEqualsWithDelta(0.00, (float) $entry->deductions, 0.01, 'permiso sin goce: el día no se paga, sin castigo del séptimo día');
    }

    public function test_vacation_premium_is_paid_as_separate_concept(): void
    {
        $employee = $this->employee(['vacation_premium_percentage' => 25.00]);

        // Vacaciones L-V del 1 al 5 jun (5 días hábiles); la semana con 3+ días de
        // vacaciones también suma el sábado 6 (regla de Dani) → 6 días pagados.
        $vac = $this->typeWithCode('VAC', [
            'category' => 'vacation',
            'is_paid' => true,
            'deducts_vacation' => true,
            'count_mode' => IncidentType::COUNT_WORKING_DAYS,
        ]);
        $this->approvedIncident($employee, $vac, '2026-06-01', '2026-06-05', 6);

        $monthly = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($monthly, $employee);

        $this->assertEqualsWithDelta(4800.00, (float) $entry->vacation_pay, 0.01, '6 días (5 hábiles + sábado) × 800');
        $this->assertEqualsWithDelta(1200.00, (float) $entry->vacation_premium_pay, 0.01, 'prima 25% sobre la vacación');
        $this->assertSame(6, (int) $entry->vacation_days_paid);
        $this->assertGreaterThanOrEqual(5000.00, (float) $entry->gross_pay);
    }

    /**
     * DECISIONES §11 (auditoría #87): el sueldo diario usa la JORNADA REAL
     * del horario efectivo, no 8 horas fijas. Un empleado de 6 horas cobra
     * su vacación (y prima) a 6 × tarifa.
     */
    public function test_daily_salary_uses_real_schedule_hours(): void
    {
        $schedule = Schedule::factory()->create(['daily_work_hours' => 6]);
        $employee = $this->employee([
            'schedule_id' => $schedule->id,
            'vacation_premium_percentage' => 25.00,
        ]);

        $vac = $this->typeWithCode('VAC', [
            'category' => 'vacation',
            'is_paid' => true,
            'count_mode' => IncidentType::COUNT_WORKING_DAYS,
        ]);
        // Miércoles, 1 día hábil.
        $this->approvedIncident($employee, $vac, '2026-06-03', '2026-06-03', 1);

        $monthly = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($monthly, $employee);

        $this->assertEqualsWithDelta(600.00, (float) $entry->vacation_pay, 0.01, '1 día × (100 × 6h), no × 8h');
        $this->assertEqualsWithDelta(150.00, (float) $entry->vacation_premium_pay, 0.01, 'prima 25% sobre la jornada real');
    }

    public function test_vacation_pays_working_days_not_calendar(): void
    {
        $employee = $this->employee(['vacation_premium_percentage' => 0]);

        // Lunes a domingo: 7 días calendario pero solo 5 hábiles (L-V).
        $vac = $this->typeWithCode('VAC', [
            'category' => 'vacation',
            'is_paid' => true,
            'count_mode' => IncidentType::COUNT_WORKING_DAYS,
        ]);
        $this->approvedIncident($employee, $vac, '2026-06-01', '2026-06-07', 5);

        $monthly = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($monthly, $employee);

        $this->assertEqualsWithDelta(4000.00, (float) $entry->vacation_pay, 0.01, '5 hábiles × 800 — el fin de semana no se paga doble vía nómina');
        $this->assertSame(5, (int) $entry->vacation_days_paid, 'mismo conteo que la captura y el saldo');
    }

    public function test_vacation_counts_saturday_after_three_days_in_payroll(): void
    {
        // Regla de Dani (2026-06-24): la nómina cuenta igual que la captura. Una
        // vacación L-D (jun 1-7) con 5 días hábiles ≥ 3 suma el sábado 6 (en
        // rango); el domingo no cuenta. 6 días × 800 = 4800.
        $employee = $this->employee(['vacation_premium_percentage' => 0]);

        $vac = $this->typeWithCode('VAC', [
            'category' => 'vacation',
            'is_paid' => true,
            'deducts_vacation' => true,
            'count_mode' => IncidentType::COUNT_WORKING_DAYS,
        ]);
        $this->approvedIncident($employee, $vac, '2026-06-01', '2026-06-07', 6);

        $monthly = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($monthly, $employee);

        $this->assertSame(6, (int) $entry->vacation_days_paid, '5 hábiles + 1 sábado por la regla');
        $this->assertEqualsWithDelta(4800.00, (float) $entry->vacation_pay, 0.01, '6 días × 800');
    }

    public function test_paid_sick_leave_pays_calendar_days(): void
    {
        $employee = $this->employee();

        // Incapacidad con goce: viernes a lunes = 4 días CALENDARIO (estándar IMSS).
        $inc = $this->typeWithCode('INC', [
            'category' => 'sick_leave',
            'is_paid' => true,
            'count_mode' => IncidentType::COUNT_CALENDAR_DAYS,
        ]);
        $this->approvedIncident($employee, $inc, '2026-06-05', '2026-06-08', 4);

        $monthly = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($monthly, $employee);

        $this->assertEqualsWithDelta(3200.00, (float) $entry->sick_leave_pay, 0.01, '4 días calendario × 800: is_paid por fin se respeta');
        $this->assertSame(4, (int) $entry->sick_leave_days);
    }

    public function test_unpaid_sick_leave_pays_and_deducts_nothing(): void
    {
        $employee = $this->employee();

        $incSg = $this->typeWithCode('ISG', [
            'category' => 'sick_leave',
            'is_paid' => false,
            'count_mode' => IncidentType::COUNT_CALENDAR_DAYS,
        ]);
        $this->approvedIncident($employee, $incSg, '2026-06-05', '2026-06-08', 4);

        $monthly = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($monthly, $employee);

        $this->assertEqualsWithDelta(0.00, (float) $entry->sick_leave_pay, 0.01, 'sin goce: no se paga');
        $this->assertEqualsWithDelta(0.00, (float) $entry->deductions, 0.01, 'ni se descuenta (el día ya vale $0 vía horas)');
        $this->assertSame(4, (int) $entry->sick_leave_days, 'los días sí se registran');
    }

    public function test_incident_capture_counts_days_per_type_mode(): void
    {
        $employee = $this->employee();
        $this->actingAsAdmin();

        // Incapacidad (calendario): viernes 5 a lunes 8 = 4 días.
        $inc = $this->typeWithCode('INC', [
            'category' => 'sick_leave',
            'is_paid' => true,
            'count_mode' => IncidentType::COUNT_CALENDAR_DAYS,
            'requires_approval' => true,
            'requires_document' => false,
        ]);

        $this->post(route('incidents.store'), [
            'employee_id' => $employee->id,
            'incident_type_id' => $inc->id,
            'start_date' => '2026-06-05',
            'end_date' => '2026-06-08',
            'reason' => 'incapacidad',
        ])->assertRedirect(route('incidents.index'));

        $this->assertSame(4, (int) Incident::where('employee_id', $employee->id)->latest('id')->first()->days_count, 'calendario: 4 días');

        // Vacaciones (hábiles): mismo rango = 2 días (viernes y lunes).
        $employee2 = $this->employee();
        $vac = $this->typeWithCode('VAC', [
            'category' => 'vacation',
            'is_paid' => true,
            'deducts_vacation' => false,
            'count_mode' => IncidentType::COUNT_WORKING_DAYS,
            'requires_approval' => true,
            'requires_document' => false,
        ]);

        $this->post(route('incidents.store'), [
            'employee_id' => $employee2->id,
            'incident_type_id' => $vac->id,
            'start_date' => '2026-06-05',
            'end_date' => '2026-06-08',
            'reason' => 'vacaciones',
        ])->assertRedirect(route('incidents.index'));

        $this->assertSame(2, (int) Incident::where('employee_id', $employee2->id)->latest('id')->first()->days_count, 'hábiles: viernes y lunes');
    }
}
