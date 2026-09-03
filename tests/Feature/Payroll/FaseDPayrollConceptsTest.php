<?php

namespace Tests\Feature\Payroll;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\CompensationType;
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

    public function test_distinct_permission_concepts_on_same_date_count_one_day(): void
    {
        $employee = $this->employee();
        $first = $this->typeWithCode('PSG-A', ['category' => 'permission', 'is_paid' => false]);
        $second = $this->typeWithCode('PSG-B', ['category' => 'permission', 'is_paid' => false]);
        $this->approvedIncident($employee, $first, '2026-06-03', '2026-06-03', 1);
        $this->approvedIncident($employee, $second, '2026-06-03', '2026-06-03', 1);

        $weekly = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($weekly, $employee);

        $this->assertEqualsWithDelta(4800.00, (float) $entry->regular_pay, 0.01,
            'dos conceptos en la misma fecha restan un solo día de base');
        $this->assertSame(1, $entry->calculation_breakdown['incidents']['permission_days']);
        $this->assertSame(1, $entry->calculation_breakdown['incidents']['permission_unpaid_days']);
    }

    public function test_paid_sick_leave_wins_over_unpaid_permission_on_same_date(): void
    {
        $employee = $this->employee();
        $sick = $this->typeWithCode('INC-PAID-OVERLAP', [
            'category' => 'sick_leave',
            'is_paid' => true,
            'count_mode' => IncidentType::COUNT_CALENDAR_DAYS,
        ]);
        $unpaidPermission = $this->typeWithCode('PSG-OVERLAP', [
            'category' => 'permission',
            'is_paid' => false,
        ]);
        $this->approvedIncident($employee, $sick, '2026-06-03', '2026-06-03', 1);
        $this->approvedIncident($employee, $unpaidPermission, '2026-06-03', '2026-06-03', 1);

        $weekly = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
        ]);
        $entry = $this->calculator()->calculateEmployeePayroll($weekly, $employee);

        $this->assertEqualsWithDelta(4800.00, (float) $entry->regular_pay, 0.01,
            'la fecha se separa de la base una sola vez por la incapacidad');
        $this->assertSame(1, $entry->calculation_breakdown['incidents']['sick_leave_days']);
        $this->assertSame(0, $entry->calculation_breakdown['incidents']['permission_days']);
        $this->assertSame(0, $entry->calculation_breakdown['incidents']['permission_unpaid_days']);
    }

    public function test_two_paid_sick_types_on_same_date_pay_once(): void
    {
        $employee = $this->employee();
        foreach (['INC-A', 'INC-B'] as $code) {
            $type = $this->typeWithCode($code, [
                'category' => 'sick_leave',
                'is_paid' => true,
                'count_mode' => IncidentType::COUNT_CALENDAR_DAYS,
            ]);
            $this->approvedIncident($employee, $type, '2026-06-03', '2026-06-03', 1);
        }

        $monthly = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);
        $entry = $this->calculator()->calculateEmployeePayroll($monthly, $employee);

        $this->assertEqualsWithDelta(800.00, (float) $entry->sick_leave_pay, 0.01);
        $this->assertSame(1, $entry->calculation_breakdown['incidents']['sick_leave_days']);
    }

    public function test_vacation_wins_over_unpaid_permission_on_same_date(): void
    {
        $employee = $this->employee();
        $vacation = $this->typeWithCode('VAC-OVERLAP', [
            'category' => 'vacation',
            'is_paid' => true,
            'deducts_vacation' => true,
            'count_mode' => IncidentType::COUNT_WORKING_DAYS,
        ]);
        $unpaidPermission = $this->typeWithCode('PSG-VAC-OVERLAP', [
            'category' => 'permission',
            'is_paid' => false,
        ]);
        $this->approvedIncident($employee, $vacation, '2026-06-03', '2026-06-03', 1);
        $this->approvedIncident($employee, $unpaidPermission, '2026-06-03', '2026-06-03', 1);

        $weekly = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
        ]);
        $entry = $this->calculator()->calculateEmployeePayroll($weekly, $employee);

        $this->assertEqualsWithDelta(5600.00, (float) $entry->regular_pay, 0.01,
            'vacaciones ya van en el sueldo base; el permiso no vuelve a restar el día');
        $this->assertSame(1, $entry->calculation_breakdown['incidents']['vacation_days']);
        $this->assertSame(0, $entry->calculation_breakdown['incidents']['permission_days']);
    }

    public function test_manual_prima_capture_suppresses_automatic_vacation_premium(): void
    {
        // Luis 2026-08-28 (caso Sonia Reyes): ya había pagado la prima por
        // fuera, capturó el concepto "Prima Vacacional" en $0 (y a Gabriela
        // se lo rechazó) y la prima AUTOMÁTICA por días de vacaciones seguía
        // saliendo en el recibo. Capturar la manual — en cualquier estado — es
        // decidir manejarla a mano: la automática no se suma y el detalle
        // explica por qué.
        $employee = $this->employee(['vacation_premium_percentage' => 25.00]);
        $vac = $this->typeWithCode('VAC', [
            'category' => 'vacation',
            'is_paid' => true,
            'deducts_vacation' => true,
            'count_mode' => IncidentType::COUNT_WORKING_DAYS,
        ]);
        $this->approvedIncident($employee, $vac, '2026-06-01', '2026-06-05', 6);

        $prima = CompensationType::updateOrCreate(['code' => 'PVVP'], [
            'name' => 'Prima Vacacional VP',
            'calculation_type' => 'fixed',
            'fixed_amount' => 1.0,
            'application_mode' => CompensationType::APPLICATION_ONE_TIME,
            'authorization_type' => Authorization::TYPE_SPECIAL,
            'is_active' => true,
        ]);
        foreach ([[Authorization::STATUS_REJECTED, 80.0], [Authorization::STATUS_APPROVED, 0.0]] as [$status, $hours]) {
            $emp = $status === Authorization::STATUS_REJECTED ? $employee : $this->employee(['vacation_premium_percentage' => 25.00]);
            if ($emp->id !== $employee->id) {
                $this->approvedIncident($emp, $vac, '2026-06-01', '2026-06-05', 6);
            }
            Authorization::factory()->create([
                'employee_id' => $emp->id,
                'date' => '2026-06-10',
                'type' => Authorization::TYPE_SPECIAL,
                'compensation_type_id' => $prima->id,
                'hours' => $hours,
                'status' => $status,
            ]);

            $monthly = PayrollPeriod::factory()->monthly()->create([
                'start_date' => '2026-06-01',
                'end_date' => '2026-06-30',
            ]);
            $entry = $this->calculator()->calculateEmployeePayroll($monthly, $emp->fresh());

            $this->assertEqualsWithDelta(0.00, (float) $entry->vacation_premium_pay, 0.01,
                "capturar la prima manual {$status} suprime la automática");
            $this->assertEqualsWithDelta(0.00, (float) $entry->other_compensation_pay, 0.01,
                'una prima manual rechazada o en cero no se paga como otro concepto');
            $this->assertNotNull($entry->calculation_breakdown['suppressed_vacation_premium'] ?? null,
                'el detalle explica la supresión');
        }
    }

    public function test_rejected_manual_prima_suppresses_weekly_transfer_premium_without_being_paid(): void
    {
        $employee = $this->employee([
            'daily_salary' => 800.00,
            'vacation_premium_percentage' => 25.00,
            'is_imss_enrolled' => true,
            'imss_number' => '12345678901',
            'is_trial_period' => false,
        ]);
        $this->assertFalse($employee->paysBaseInCash());

        $vac = $this->typeWithCode('VAC', [
            'category' => 'vacation',
            'is_paid' => true,
            'deducts_vacation' => true,
            'count_mode' => IncidentType::COUNT_WORKING_DAYS,
        ]);
        $this->approvedIncident($employee, $vac, '2026-06-01', '2026-06-05', 6);

        $prima = CompensationType::updateOrCreate(['code' => 'PVVP'], [
            'name' => 'Prima Vacacional VP',
            'calculation_type' => 'fixed',
            'fixed_amount' => 1.0,
            'application_mode' => CompensationType::APPLICATION_ONE_TIME,
            'authorization_type' => Authorization::TYPE_SPECIAL,
            'is_active' => true,
        ]);
        Authorization::factory()->create([
            'employee_id' => $employee->id,
            'date' => '2026-06-03',
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $prima->id,
            'hours' => 80.0,
            'status' => Authorization::STATUS_REJECTED,
        ]);

        $weekly = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
        ]);
        $entry = $this->calculator()->calculateEmployeePayroll($weekly, $employee);

        $this->assertEqualsWithDelta(0.00, (float) $entry->vacation_premium_pay, 0.01,
            'la captura rechazada suprime también la prima automática semanal');
        $this->assertEqualsWithDelta(0.00, (float) $entry->other_compensation_pay, 0.01,
            'la captura rechazada no se paga como concepto');
        $this->assertNotNull($entry->calculation_breakdown['suppressed_vacation_premium'] ?? null);
    }

    public function test_other_rejected_concept_neither_pays_nor_suppresses_vacation_premium(): void
    {
        $employee = $this->employee(['vacation_premium_percentage' => 25.00]);
        $vac = $this->typeWithCode('VAC', [
            'category' => 'vacation',
            'is_paid' => true,
            'deducts_vacation' => true,
            'count_mode' => IncidentType::COUNT_WORKING_DAYS,
        ]);
        $this->approvedIncident($employee, $vac, '2026-06-01', '2026-06-05', 6);

        $bonus = CompensationType::updateOrCreate(['code' => 'BONREJ'], [
            'name' => 'Bono manual rechazado',
            'calculation_type' => 'fixed',
            'fixed_amount' => 500.0,
            'application_mode' => CompensationType::APPLICATION_ONE_TIME,
            'authorization_type' => Authorization::TYPE_SPECIAL,
            'is_active' => true,
        ]);
        Authorization::factory()->create([
            'employee_id' => $employee->id,
            'date' => '2026-06-10',
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $bonus->id,
            'hours' => 1.0,
            'status' => Authorization::STATUS_REJECTED,
        ]);

        $monthly = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);
        $entry = $this->calculator()->calculateEmployeePayroll($monthly, $employee);

        $this->assertEqualsWithDelta(1200.00, (float) $entry->vacation_premium_pay, 0.01,
            'solo un concepto Prima Vacacional suprime la prima automática');
        $this->assertEqualsWithDelta(0.00, (float) $entry->other_compensation_pay, 0.01,
            'el concepto rechazado no se paga');
        $this->assertNull($entry->calculation_breakdown['suppressed_vacation_premium'] ?? null);
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

        // El día de vacación se paga como base en el periodo semanal (como
        // Contpaq); en el mensual solo queda la prima. 6 días × 800 × 25% = 1200.
        $this->assertEqualsWithDelta(0.00, (float) $entry->vacation_pay, 0.01, 'el día de vacación va en el base semanal');
        $this->assertEqualsWithDelta(1200.00, (float) $entry->vacation_premium_pay, 0.01, 'prima 25% sobre 6 días');
    }

    /**
     * Los empleados FORMALIZADOS (transferencia) cobran su prima vacacional en la
     * nómina SEMANAL por transferencia, como Contpaq — no en la mensual y no en
     * efectivo. Los de efectivo la siguen cobrando en la mensual (test anterior).
     */
    public function test_formalized_vacation_premium_paid_in_weekly_transfer(): void
    {
        $employee = $this->employee([
            'daily_salary' => 800.00,
            'vacation_premium_percentage' => 25.00,
            'is_imss_enrolled' => true,
            'imss_number' => '12345678901',
            'is_trial_period' => false,
        ]);
        $this->assertFalse($employee->paysBaseInCash(), 'formalizado: cobra por transferencia');

        $vac = $this->typeWithCode('VAC', [
            'category' => 'vacation',
            'is_paid' => true,
            'deducts_vacation' => true,
            'count_mode' => IncidentType::COUNT_WORKING_DAYS,
        ]);
        $this->approvedIncident($employee, $vac, '2026-06-01', '2026-06-05', 6);

        // --- Semanal (lun 1 – dom 7 jun): la prima se paga aquí, por transferencia ---
        $weekly = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
        ]);
        $weeklyEntry = $this->calculator()->calculateEmployeePayroll($weekly, $employee);

        $this->assertEqualsWithDelta(1200.00, (float) $weeklyEntry->vacation_premium_pay, 0.01,
            'prima 6×800×25% se paga en la semanal para formalizados');
        $this->assertGreaterThanOrEqual(1200.00, (float) $weeklyEntry->bank_amount,
            'la prima se transfiere (banco), no en efectivo');
        $this->assertEqualsWithDelta(0.00, (float) $weeklyEntry->cash_amount, 0.01,
            'nada de la prima del formalizado cae en efectivo');

        // --- Mensual: la prima ya NO se repite aquí (evita doble pago) ---
        $monthly = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);
        $monthlyEntry = $this->calculator()->calculateEmployeePayroll($monthly, $employee);
        $this->assertEqualsWithDelta(0.00, (float) $monthlyEntry->vacation_premium_pay, 0.01,
            'la prima del formalizado no se repite en la mensual');
    }

    /**
     * DECISIONES §11 (auditoría #87): el sueldo diario usa la JORNADA REAL
     * del horario efectivo, no 8 horas fijas. La prima vacacional de un empleado
     * de 6 horas se calcula sobre 6 × tarifa (el día en sí va en el base).
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

        // SD real = 100 × 6h = 600. Prima = 1 × 600 × 25% = 150.
        $this->assertEqualsWithDelta(0.00, (float) $entry->vacation_pay, 0.01, 'el día va en el base');
        $this->assertEqualsWithDelta(150.00, (float) $entry->vacation_premium_pay, 0.01, 'prima 25% sobre jornada real (6h)');
    }

    public function test_vacation_pays_working_days_not_calendar(): void
    {
        $employee = $this->employee();

        // Lunes a domingo: 7 días calendario pero solo 5 hábiles (L-V).
        $vac = $this->typeWithCode('VAC', [
            'category' => 'vacation',
            'is_paid' => true,
            'count_mode' => IncidentType::COUNT_WORKING_DAYS,
        ]);
        $this->approvedIncident($employee, $vac, '2026-06-01', '2026-06-07', 5);

        $weekly = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($weekly, $employee);

        // La vacación se paga como base (800 × 7 = 5600); se cuentan 5 días
        // hábiles (el fin de semana no cuenta), reflejado en vacation_days_paid.
        $this->assertEqualsWithDelta(5600.00, (float) $entry->regular_pay, 0.01, 'base = 800 × 7 (vacación incluida)');
        $this->assertEqualsWithDelta(0.00, (float) $entry->vacation_pay, 0.01);
        $this->assertSame(5, (int) $entry->vacation_days_paid, 'mismo conteo que la captura y el saldo (5 hábiles)');
    }

    public function test_vacation_counts_saturday_after_three_days_in_payroll(): void
    {
        // Regla de Dani (2026-06-24): la nómina cuenta igual que la captura. Una
        // vacación L-D (jun 1-7) con 5 días hábiles ≥ 3 suma el sábado 6 (en
        // rango); el domingo no cuenta. Se paga como base (800 × 7) y cuenta 6.
        $employee = $this->employee();

        $vac = $this->typeWithCode('VAC', [
            'category' => 'vacation',
            'is_paid' => true,
            'deducts_vacation' => true,
            'count_mode' => IncidentType::COUNT_WORKING_DAYS,
        ]);
        $this->approvedIncident($employee, $vac, '2026-06-01', '2026-06-07', 6);

        $weekly = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($weekly, $employee);

        $this->assertSame(6, (int) $entry->vacation_days_paid, '5 hábiles + 1 sábado por la regla');
        $this->assertEqualsWithDelta(5600.00, (float) $entry->regular_pay, 0.01, 'base = 800 × 7');
        $this->assertEqualsWithDelta(0.00, (float) $entry->vacation_pay, 0.01);
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
