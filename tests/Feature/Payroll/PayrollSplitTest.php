<?php

namespace Tests\Feature\Payroll;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Employee;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\Schedule;
use App\Models\User;
use App\Services\PayrollCalculatorService;
use Tests\FeatureTestCase;

/**
 * Feature tests for the weekly/monthly payroll split and the
 * authorization-driven compensation pass.
 *
 * Weekly periods pay the base salary minus absences/lates; monthly periods
 * pay the extras (overtime, velada, holiday, weekend, special concepts),
 * vacations and all bonuses. The two never double-pay because each concept
 * is scoped to exactly one period type.
 */
class PayrollSplitTest extends FeatureTestCase
{
    private function calculator(): PayrollCalculatorService
    {
        return app(PayrollCalculatorService::class);
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
     * A weekday present record with 8 worked hours and 2 authorized overtime
     * hours, so we can prove the weekly period ignores the overtime.
     *
     * Dated mid-period (Wed 2026-06-03) so it is never on the period boundary,
     * where SQLite's string comparison of a DATE against a DATETIME bound would
     * drop it (a test-only quirk; MySQL treats them as equal).
     */
    private function presentRecord(Employee $employee): AttendanceRecord
    {
        return AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-06-03', // Wednesday, mid-period
            'status' => 'present',
            'worked_hours' => 8.00,
            'overtime_hours' => 2.00,
            'overtime_authorized_hours' => 2.00,
        ]);
    }

    public function test_weekly_period_pays_base_salary_without_extras(): void
    {
        $employee = $this->makeEmployee();
        $this->presentRecord($employee);

        $period = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($period, $employee);

        // Sueldo diario 800 (= hora 100 × jornada 8) × 7 días de la semana
        // (séptimo día incluido). Sin faltas no hay deducción.
        $this->assertEqualsWithDelta(5600.00, (float) $entry->regular_pay, 0.01, 'base = sueldo diario 800 × 7');
        $this->assertEqualsWithDelta(0.00, (float) $entry->overtime_pay, 0.01, 'no overtime on weekly');
        $this->assertEqualsWithDelta(0.00, (float) $entry->bonuses, 0.01, 'no bonuses on weekly');
        $this->assertEqualsWithDelta(0.00, (float) $entry->vacation_pay, 0.01, 'no vacation on weekly');
        $this->assertEqualsWithDelta(5600.00, (float) $entry->gross_pay, 0.01);
        $this->assertEqualsWithDelta(5600.00, (float) $entry->net_pay, 0.01);
    }

    /**
     * Ejemplo canónico del negocio (Art. 72 LFT): sueldo diario $200, horario
     * de 6 días (Lun–Sáb). La semana vale 200 × 7 = $1,400; una falta descuenta
     * el día + 1/6 del domingo (200 × 7/6 = $233.33), dejando $1,166.67.
     */
    public function test_six_day_schedule_one_absence_matches_legal_example(): void
    {
        $schedule = Schedule::factory()->create([
            'daily_work_hours' => 8,
            'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'],
        ]);

        $employee = Employee::factory()->create([
            'status' => 'active',
            'schedule_id' => $schedule->id,
            'daily_salary' => 200.00,
        ]);

        // Una falta injustificada el miércoles (día laborable, sin incidencia).
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-06-03', // Wednesday
            'status' => 'absent',
            'worked_hours' => 0,
        ]);

        $period = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($period, $employee);

        $this->assertEqualsWithDelta(1400.00, (float) $entry->regular_pay, 0.01, 'base = 200 × 7');
        $this->assertEqualsWithDelta(233.33, (float) $entry->deductions, 0.01, '1 falta × 200 × 7/6');
        $this->assertEqualsWithDelta(1166.67, (float) $entry->net_pay, 0.01, 'semana con 1 falta');
    }

    /**
     * Las horas extra se pagan por MONTO FIJO por hora del concepto asignado
     * (no como porcentaje de la tarifa por hora). 2 h autorizadas × $150 = $300.
     */
    public function test_overtime_pays_fixed_amount_per_hour_via_comp_type(): void
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

        $this->assertEqualsWithDelta(300.00, (float) $entry->overtime_pay, 0.01, '2h × $150 monto fijo');
    }

    /**
     * Vacación no se paga doble: el periodo semanal RESTA los días de vacación
     * del base (se pagan en el mensual), conservando el séptimo día.
     */
    public function test_vacation_is_subtracted_from_weekly_base_to_avoid_double_pay(): void
    {
        $employee = Employee::factory()->create([
            'status' => 'active',
            'daily_salary' => 200.00,
        ]);

        $vacType = IncidentType::factory()->create([
            'code' => 'VAC',
            'category' => 'vacation',
            'is_paid' => true,
        ]);
        Incident::factory()->approved()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $vacType->id,
            'start_date' => '2026-06-02', // martes
            'end_date' => '2026-06-03',   // miércoles (2 días hábiles L-V)
            'days_count' => 2,
        ]);

        $weekly = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($weekly, $employee);

        // Base = 200 × (7 − 2 vacaciones) = 1000; las vacaciones las paga el
        // periodo mensual, no el semanal. Sin faltas: sin deducción.
        $this->assertEqualsWithDelta(1000.00, (float) $entry->regular_pay, 0.01, 'base = 200 × (7 − 2 vac)');
        $this->assertEqualsWithDelta(0.00, (float) $entry->deductions, 0.01);
        $this->assertEqualsWithDelta(0.00, (float) $entry->vacation_pay, 0.01, 'la vacación se paga en el mensual, no aquí');
    }

    public function test_monthly_period_pays_extras_without_base(): void
    {
        $employee = $this->makeEmployee();
        $this->presentRecord($employee);

        $period = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($period, $employee);

        // Legacy fallback (no comp types): 2h * 100 * 1.5 = 300.
        $this->assertEqualsWithDelta(0.00, (float) $entry->regular_pay, 0.01, 'no base on monthly');
        $this->assertEqualsWithDelta(0.00, (float) $entry->deductions, 0.01, 'no deductions on monthly');
        $this->assertEqualsWithDelta(300.00, (float) $entry->overtime_pay, 0.01, 'overtime paid on monthly');
        $this->assertEqualsWithDelta(300.00, (float) $entry->gross_pay, 0.01);
    }

    public function test_monthly_pays_cena_via_generic_authorization_pass(): void
    {
        $employee = $this->makeEmployee();
        $this->presentRecord($employee);

        // CENA: dinner allowance, fixed $75/day, pulled from check-ins.
        $cena = CompensationType::factory()->fixed(75.00)->create([
            'code' => 'CENA',
            'name' => 'Cena',
            'application_mode' => CompensationType::APPLICATION_PER_DAY,
            'authorization_type' => Authorization::TYPE_SPECIAL,
            'attendance_pull_rule' => CompensationType::PULL_RULE_MEAL,
        ]);
        // Assign so the employee uses the CompensationType path.
        $employee->compensationTypes()->attach($cena->id, ['is_active' => true]);
        $employee->load(['compensationTypes' => fn ($q) => $q->wherePivot('is_active', true)]);
        $this->assertTrue($employee->compensationTypes->isNotEmpty(), 'CENA assignment is active');

        $user = User::factory()->create();
        Authorization::create([
            'employee_id' => $employee->id,
            'requested_by' => $user->id,
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $cena->id,
            'date' => '2026-06-03',
            'hours' => 1,
            'reason' => 'cena',
            'status' => Authorization::STATUS_APPROVED,
        ]);

        $period = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($period, $employee);

        $this->assertEqualsWithDelta(75.00, (float) $entry->other_compensation_pay, 0.01, 'CENA paid once via generic pass');
        // Legacy night-shift dinner allowance is suppressed for comp-type employees.
        $this->assertEqualsWithDelta(0.00, (float) $entry->dinner_allowance, 0.01);
        $this->assertGreaterThanOrEqual(75.00, (float) $entry->gross_pay);
    }

    /**
     * A velada that crosses midnight (22:00 → 06:00) is authorized for its real
     * 8h span, not a naive same-day 16h/20h diff. The midnight-aware hours are
     * stored on the Authorization and the attendance velada_authorized_hours;
     * payroll generation must surface exactly those — never recompute from the
     * start/end times. Legacy (no comp types) path: velada pay = hours * rate *
     * multiplier, so 8h proves through as 8 * 100 * 2.0 = 1600, not 20h → 4000.
     */
    public function test_monthly_velada_crossing_midnight_pays_real_hours(): void
    {
        $employee = $this->makeEmployee();

        // Attendance velada split as the midnight-aware VeladaCalculatorService /
        // sync would persist it: 8 detected, 8 authorized.
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-06-03',
            'status' => 'present',
            'worked_hours' => 8.00,
            'velada_hours' => 8.00,
            'velada_authorized_hours' => 8.00,
        ]);

        // The approved velada authorization, stored with the midnight-aware 8h the
        // fixed controller computes for a 22:00 → 06:00 (next-day) range.
        $user = User::factory()->create();
        Authorization::create([
            'employee_id' => $employee->id,
            'requested_by' => $user->id,
            'type' => Authorization::TYPE_NIGHT_SHIFT,
            'date' => '2026-06-03',
            'start_time' => '22:00',
            'end_time' => '06:00',
            'hours' => 8,
            'reason' => 'velada cruzando medianoche',
            'status' => Authorization::STATUS_APPROVED,
        ]);

        $period = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($period, $employee);

        $this->assertEqualsWithDelta(8.00, (float) $entry->night_shift_hours, 0.01, 'night_shift_hours = summed authorization hours (8), not 20');
        $this->assertEqualsWithDelta(1600.00, (float) $entry->velada_pay, 0.01, 'velada pay = 8h * 100 * 2.0, proving the 8h span flows through');
    }

    /**
     * Velada pagada por NOCHE a monto fijo vía conceptos (regla 2026-06-15):
     * el "Monto" del concepto VEL se paga tal cual por cada noche trabajada y
     * autorizada — 1 velada = monto, 2 veladas = 2 × monto — sin multiplicar por
     * horas ni por el multiplicador 2x. El conteo (velada_days) se guarda.
     */
    public function test_monthly_velada_pays_fixed_amount_per_night_via_comp_types(): void
    {
        $employee = $this->makeEmployee();

        // Concepto VEL como monto fijo por día/noche: $472 por velada.
        $velada = CompensationType::factory()->fixed(472.00)->create([
            'name' => 'Velada',
            'code' => 'VEL',
            'application_mode' => CompensationType::APPLICATION_PER_DAY,
            'authorization_type' => Authorization::TYPE_NIGHT_SHIFT,
        ]);
        $employee->compensationTypes()->attach($velada->id, ['is_active' => true]);

        $user = User::factory()->create();

        // Dos noches distintas con velada real en checadas + autorización aprobada.
        foreach (['2026-06-03', '2026-06-10'] as $date) {
            AttendanceRecord::factory()->for($employee)->create([
                'work_date' => $date,
                'status' => 'present',
                'worked_hours' => 10.00,
                'velada_hours' => 4.00,
                'velada_authorized_hours' => 4.00,
            ]);
            Authorization::create([
                'employee_id' => $employee->id,
                'requested_by' => $user->id,
                'type' => Authorization::TYPE_NIGHT_SHIFT,
                'date' => $date,
                'hours' => 4,
                'reason' => 'velada',
                'status' => Authorization::STATUS_APPROVED,
            ]);
        }

        $period = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($period, $employee->fresh());

        // 2 veladas × $472 = $944, sin importar las 4 h de cada noche.
        $this->assertSame(2, (int) $entry->velada_days, '2 noches de velada contadas');
        $this->assertEqualsWithDelta(944.00, (float) $entry->velada_pay, 0.01, '2 × monto fijo, no por horas');
    }

    /**
     * Sin "Monto" configurado, la velada por conceptos paga 0 (regla 2026-06-15):
     * el monto fijo global del concepto VEL es 0 y no hay override por empleado,
     * así que no se paga nada aunque la noche esté autorizada y trabajada.
     */
    public function test_monthly_velada_without_amount_pays_zero(): void
    {
        $employee = $this->makeEmployee();

        $velada = CompensationType::factory()->fixed(0.00)->create([
            'name' => 'Velada',
            'code' => 'VEL',
            'application_mode' => CompensationType::APPLICATION_PER_DAY,
            'authorization_type' => Authorization::TYPE_NIGHT_SHIFT,
        ]);
        $employee->compensationTypes()->attach($velada->id, ['is_active' => true]);

        $user = User::factory()->create();
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-06-03',
            'status' => 'present',
            'worked_hours' => 10.00,
            'velada_hours' => 4.00,
            'velada_authorized_hours' => 4.00,
        ]);
        Authorization::create([
            'employee_id' => $employee->id,
            'requested_by' => $user->id,
            'type' => Authorization::TYPE_NIGHT_SHIFT,
            'date' => '2026-06-03',
            'hours' => 4,
            'reason' => 'velada',
            'status' => Authorization::STATUS_APPROVED,
        ]);

        $period = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($period, $employee->fresh());

        $this->assertSame(1, (int) $entry->velada_days, 'la noche se cuenta');
        $this->assertEqualsWithDelta(0.00, (float) $entry->velada_pay, 0.01, 'sin monto → 0');
    }

    /**
     * A COMIDA (attendance_pull_rule = comida) authorization pays through the same
     * generic authorization pass as cena — one fixed lunch per approved weekend-work
     * day — and lands in other_compensation_pay (the monthly "otros" bucket), proving
     * the new pull rule is wired end-to-end into payroll generation.
     */
    public function test_monthly_pays_comida_via_generic_authorization_pass(): void
    {
        $employee = $this->makeEmployee();
        $this->presentRecord($employee);

        // Factory-generated unique code: a 'COM' may already exist in the seeded
        // catalog, and this test only cares about the comida pull rule, not the code.
        $comida = CompensationType::factory()->fixed(60.00)->create([
            'name' => 'Comida',
            'application_mode' => CompensationType::APPLICATION_PER_DAY,
            'authorization_type' => Authorization::TYPE_SPECIAL,
            'attendance_pull_rule' => CompensationType::PULL_RULE_COMIDA,
        ]);
        $employee->compensationTypes()->attach($comida->id, ['is_active' => true]);
        $employee->load(['compensationTypes' => fn ($q) => $q->wherePivot('is_active', true)]);

        $user = User::factory()->create();
        Authorization::create([
            'employee_id' => $employee->id,
            'requested_by' => $user->id,
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $comida->id,
            'date' => '2026-06-06', // Saturday worked
            'hours' => 1,
            'reason' => 'comida fin de semana',
            'status' => Authorization::STATUS_APPROVED,
        ]);

        $period = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($period, $employee);

        $this->assertEqualsWithDelta(60.00, (float) $entry->other_compensation_pay, 0.01, 'COMIDA paid once via generic pass into otros');
        $this->assertEqualsWithDelta(0.00, (float) $entry->weekend_pay, 0.01, 'comida is not a weekend premium');
    }

    public function test_contpaqi_export_columns_match_period_type(): void
    {
        $weekly = PayrollPeriod::factory()->weekly()->create();
        $monthly = PayrollPeriod::factory()->monthly()->create();

        $weeklyHeadings = (new \App\Exports\ContpaqiPrenominaExport($weekly))->headings();
        $monthlyHeadings = (new \App\Exports\ContpaqiPrenominaExport($monthly))->headings();

        // Weekly exports the base salary + deductions, not the extras.
        $this->assertContains('P001_SUELDO', $weeklyHeadings);
        $this->assertContains('D001_DEDUCCIONES', $weeklyHeadings);
        $this->assertNotContains('P002_HORAS_EXTRA', $weeklyHeadings);
        $this->assertNotContains('P007_OTROS', $weeklyHeadings);

        // El sueldo se paga por día: se exporta el sueldo diario y los días
        // pagados; las faltas (injustificadas + FRT) SÍ descuentan y concilian
        // con DEDUCCIONES vía DIAS_FALTA_DESCONTADOS.
        $this->assertContains('SUELDO_DIARIO', $weeklyHeadings);
        $this->assertContains('DIAS_PAGADOS', $weeklyHeadings);
        $this->assertContains('DIAS_FALTA_DESCONTADOS', $weeklyHeadings);
        $this->assertContains('DIAS_FALTA_RETARDOS', $weeklyHeadings);

        // Monthly exports the extras (incl. OTROS = cena/comida/dominical), not the base.
        $this->assertContains('P002_HORAS_EXTRA', $monthlyHeadings);
        $this->assertContains('P007_OTROS', $monthlyHeadings);
        $this->assertNotContains('P001_SUELDO', $monthlyHeadings);
    }

    public function test_paid_period_is_not_recalculated(): void
    {
        $employee = $this->makeEmployee();
        $this->presentRecord($employee);

        $period = PayrollPeriod::factory()->monthly()->paid()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);

        $this->calculator()->calculatePeriod($period);

        $this->assertSame('paid', $period->fresh()->status, 'paid period stays paid');
        $this->assertSame(0, PayrollEntry::where('payroll_period_id', $period->id)->count(), 'no entries written for a paid period');
    }
}
