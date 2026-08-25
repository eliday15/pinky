<?php

namespace Tests\Feature\Payroll;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Services\PayrollCalculatorService;
use App\Services\Reports\WeeklyOvertimeReportService;
use Carbon\Carbon;
use Tests\FeatureTestCase;

/**
 * Regla de Almacén PT (DECISIONES de negocio, WhatsApp 2026-06-08):
 * cuando se detectan 12 h trabajadas en fin de semana se cuenta (y se paga)
 * como 2 fines de semana — proporcional, 6 h = 1 unidad. El departamento se
 * marca con `weekend_unit_hours = 6`; los demás conservan el comportamiento
 * por día/hora de siempre.
 */
class WeekendUnitsTest extends FeatureTestCase
{
    private const SATURDAY = '2026-03-14'; // sábado dentro de la semana del 09 mar

    /**
     * Crea el concepto FIN (fin de semana) como monto fijo por unidad.
     */
    private function weekendCompType(float $fixed = 200.0): CompensationType
    {
        // updateOrCreate por código: idempotente si se invoca más de una vez en
        // un mismo test y a prueba de un 'FIN' ya sembrado por migraciones.
        return CompensationType::updateOrCreate(
            ['code' => 'FIN'],
            [
                'name' => 'Fin de Semana',
                'calculation_type' => 'fixed',
                'fixed_amount' => $fixed,
                'percentage_value' => null,
                'application_mode' => CompensationType::APPLICATION_PER_DAY,
                'authorization_type' => Authorization::TYPE_SPECIAL,
                'attendance_pull_rule' => CompensationType::PULL_RULE_WEEKEND,
                'is_active' => true,
            ],
        );
    }

    /**
     * El concepto COM (comida) como monto fijo por unidad.
     */
    private function comidaCompType(float $fixed = 50.0): CompensationType
    {
        // El COM se siembra por migración (code 'COM'); reusarlo y fijarle el
        // monto. El reporte detecta la comida por su código 'COM'.
        return CompensationType::updateOrCreate(
            ['code' => 'COM'],
            [
                'name' => 'Comida',
                'calculation_type' => 'fixed',
                'fixed_amount' => $fixed,
                'percentage_value' => null,
                'application_mode' => CompensationType::APPLICATION_PER_DAY,
                'authorization_type' => Authorization::TYPE_SPECIAL,
                'attendance_pull_rule' => CompensationType::PULL_RULE_COMIDA,
                'is_active' => true,
            ],
        );
    }

    /**
     * Un sábado de fin de semana trabajado + autorización FIN aprobada. Las horas
     * contables para unidades son las CORRIDAS de entrada a salida (Dani
     * 2026-08-19); la salida por omisión se deriva de worked + overtime para que
     * las corridas coincidan con las netas salvo que el caso pida otra cosa.
     */
    private function seedWeekendWork(Employee $employee, CompensationType $fin, float $workedHours = 12.0, float $overtimeHours = 0.0, ?string $checkOut = null): void
    {
        $checkOut ??= Carbon::parse(self::SATURDAY.' 08:00:00')
            ->addMinutes((int) round(($workedHours + $overtimeHours) * 60))
            ->format('H:i:s');

        AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => self::SATURDAY,
            'check_in' => '08:00:00',
            'check_out' => $checkOut,
            'worked_hours' => $workedHours,
            'overtime_hours' => $overtimeHours,
            'status' => 'present',
            'is_weekend_work' => true,
        ]);

        Authorization::factory()->create([
            'employee_id' => $employee->id,
            'date' => self::SATURDAY,
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $fin->id,
            'hours' => 1,
            'status' => Authorization::STATUS_APPROVED,
        ]);
    }

    /**
     * Una autorización COM (comida) aprobada para el mismo sábado, como la
     * compañera que se genera al aprobar el fin de semana.
     */
    private function seedComida(Employee $employee, CompensationType $com): void
    {
        Authorization::factory()->create([
            'employee_id' => $employee->id,
            'date' => self::SATURDAY,
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $com->id,
            'hours' => 1,
            'status' => Authorization::STATUS_APPROVED,
        ]);
    }

    public function test_report_counts_weekend_by_units_for_almacen_pt(): void
    {
        $dept = Department::factory()->create([
            'name' => 'Almacén PT',
            'code' => 'ALMACENPT',
            'weekend_unit_hours' => 6,
        ]);
        $employee = Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active']);
        $this->seedWeekendWork($employee, $this->weekendCompType());

        $report = app(WeeklyOvertimeReportService::class)
            ->buildReport($dept, Carbon::parse('2026-03-09'));

        // 12 h trabajadas ÷ 6 = 2 fines de semana.
        $this->assertSame(6, $report['weekend_unit_hours']);
        $this->assertEqualsWithDelta(2.0, $report['totals']['weekend_units'], 0.01);
        $this->assertEqualsWithDelta(2.0, $report['rows'][0]['totals']['weekend_units'], 0.01);
        $this->assertEqualsWithDelta(12.0, $report['rows'][0]['totals']['weekend_worked_hours'], 0.01);
    }

    public function test_report_counts_threshold_department_fines_with_double_at_twelve(): void
    {
        // Deptos de umbral (Dani 2026-08-25, caso Angelica/Saldos): el reporte
        // expone el conteo REAL de fines — 12 h corridas = fin DOBLE.
        $dept = Department::factory()->create(['name' => 'Producción', 'code' => 'PROD']); // sin regla de bloques
        $employee = Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active']);
        $this->seedWeekendWork($employee, $this->weekendCompType()); // 12 h corridas

        $report = app(WeeklyOvertimeReportService::class)
            ->buildReport($dept, Carbon::parse('2026-03-09'));

        $this->assertNull($report['weekend_unit_hours']);
        $this->assertSame(2, $report['totals']['weekend_units'], '12 h corridas en depto de umbral = fin doble');
    }

    public function test_payroll_pays_weekend_by_units_for_almacen_pt(): void
    {
        $dept = Department::factory()->create([
            'name' => 'Almacén PT',
            'code' => 'ALMACENPT',
            'weekend_unit_hours' => 6,
        ]);
        $employee = Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active']);

        $fin = $this->weekendCompType(200.0); // $200 por unidad de fin de semana
        $employee->compensationTypes()->attach($fin->id, ['is_active' => true]);

        $this->seedWeekendWork($employee, $fin, 12.0);

        // Periodo MENSUAL: paga los extras (incluido el fin de semana).
        $period = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
            'payment_date' => '2026-04-03',
        ]);

        $entry = app(PayrollCalculatorService::class)
            ->calculateEmployeePayroll($period, $employee->fresh());

        // 12 h ÷ 6 = 2 unidades × $200 = $400.
        $this->assertEqualsWithDelta(400.0, (float) $entry->weekend_pay, 0.01);
    }

    public function test_weekend_units_use_floor_not_rounding(): void
    {
        // Unidades a números cerrados SIN redondear hacia arriba (WhatsApp
        // 2026-06-24, Dani): 9 h ÷ 6 = 1.5 → 1 unidad (no 2). Igual en reporte
        // y nómina.
        $dept = Department::factory()->create([
            'name' => 'Almacén PT',
            'code' => 'ALMACENPT',
            'weekend_unit_hours' => 6,
        ]);
        $employee = Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active']);

        $fin = $this->weekendCompType(200.0);
        $employee->compensationTypes()->attach($fin->id, ['is_active' => true]);

        $this->seedWeekendWork($employee, $fin, 9.0); // 9 h trabajadas en sábado

        // Reporte: 9 ÷ 6 = 1.5 → 1 unidad (floor, no se redondea a 2).
        $report = app(WeeklyOvertimeReportService::class)
            ->buildReport($dept, Carbon::parse('2026-03-09'));
        $this->assertSame(1, $report['totals']['weekend_units']);
        $this->assertSame(1, $report['rows'][0]['totals']['weekend_units']);

        // Nómina: 1 unidad × $200 = $200 (no 1.5/2 × $200).
        $period = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
            'payment_date' => '2026-04-03',
        ]);
        $entry = app(PayrollCalculatorService::class)
            ->calculateEmployeePayroll($period, $employee->fresh());

        $this->assertEqualsWithDelta(200.0, (float) $entry->weekend_pay, 0.01);
    }

    public function test_eleven_hours_is_one_unit_twelve_is_two(): void
    {
        // Regla explícita de Dani: 11 h = 1 fin de semana, 12 h = 2.
        $dept = Department::factory()->create([
            'name' => 'Almacén PT',
            'code' => 'ALMACENPT',
            'weekend_unit_hours' => 6,
        ]);

        foreach ([[11.0, 1], [12.0, 2]] as [$worked, $expectedUnits]) {
            $employee = Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active']);
            $this->seedWeekendWork($employee, $this->weekendCompType(), $worked);

            $report = app(WeeklyOvertimeReportService::class)
                ->buildReport($dept, Carbon::parse('2026-03-09'));

            // El reporte trae a todos los empleados del depto; ubica la fila de este.
            $row = collect($report['rows'])->first(fn ($r) => $r['employee']['id'] === $employee->id);
            $this->assertSame($expectedUnits, $row['totals']['weekend_units'], "{$worked} h debe dar {$expectedUnits} unidad(es)");
        }
    }

    public function test_weekend_units_count_overtime_hours_too(): void
    {
        // Caso Miriam (prod): worked_hours topa a la jornada base (8 h) y el resto
        // es overtime_hours (5 h). En fin de semana TODA la jornada (13 h) cuenta:
        // 13 ÷ 6 = 2 unidades, no round(8/6)=1.
        $dept = Department::factory()->create([
            'name' => 'Almacén PT',
            'code' => 'ALMACENPT',
            'weekend_unit_hours' => 6,
        ]);
        $employee = Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active']);

        $fin = $this->weekendCompType(200.0);
        $employee->compensationTypes()->attach($fin->id, ['is_active' => true]);

        $this->seedWeekendWork($employee, $fin, 8.0, 5.0); // 8 base + 5 extra = 13 h

        $report = app(WeeklyOvertimeReportService::class)
            ->buildReport($dept, Carbon::parse('2026-03-09'));
        $this->assertEqualsWithDelta(13.0, $report['rows'][0]['totals']['weekend_worked_hours'], 0.01);
        $this->assertSame(2, $report['rows'][0]['totals']['weekend_units']);

        $period = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
            'payment_date' => '2026-04-03',
        ]);
        $entry = app(PayrollCalculatorService::class)
            ->calculateEmployeePayroll($period, $employee->fresh());

        $this->assertEqualsWithDelta(400.0, (float) $entry->weekend_pay, 0.01); // 2 × 200
    }

    public function test_weekend_units_count_gross_span_not_net_of_lunch(): void
    {
        // Caso Elizabeth (Dani 2026-08-19): checó 6:55–19:00 (12.08 h corridas)
        // pero el neto con la comida descontada queda en 11.58, y floor(11.58/6)
        // daba 1 unidad. Las unidades se cuentan sobre las horas CORRIDAS de
        // entrada a salida: 12.08 ÷ 6 = 2, en reporte y nómina por igual.
        $dept = Department::factory()->create([
            'name' => 'Almacén PT',
            'code' => 'ALMACENPT',
            'weekend_unit_hours' => 6,
        ]);
        $employee = Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active']);
        $fin = $this->weekendCompType(200.0);
        $employee->compensationTypes()->attach($fin->id, ['is_active' => true]);

        // El helper siembra entrada 08:00; el caso real entró 06:55 → se ajusta
        // el registro para reproducir las checadas exactas (6:55–19:00).
        $this->seedWeekendWork($employee, $fin, 11.58, 0.0, '19:00:00');
        AttendanceRecord::where('employee_id', $employee->id)
            ->whereDate('work_date', self::SATURDAY)
            ->update(['check_in' => '06:55:00']);

        $report = app(WeeklyOvertimeReportService::class)
            ->buildReport($dept, Carbon::parse('2026-03-09'));
        $this->assertEqualsWithDelta(12.08, $report['rows'][0]['totals']['weekend_worked_hours'], 0.01);
        $this->assertSame(2, $report['rows'][0]['totals']['weekend_units']);

        $period = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
            'payment_date' => '2026-04-03',
        ]);
        $entry = app(PayrollCalculatorService::class)
            ->calculateEmployeePayroll($period, $employee->fresh());
        $this->assertEqualsWithDelta(400.0, (float) $entry->weekend_pay, 0.01, '12.08 h corridas = 2 unidades × $200');
    }

    public function test_velada_hours_do_not_generate_weekend_units(): void
    {
        // Caso Miguel (Dani 2026-08-24): domingo 15:00–05:00 (+1) = 14 h
        // corridas, pero 4.5 son VELADA pagada aparte. La base de unidades es
        // 14 − 4.5 = 9.5 → 1 unidad (no 2): una hora nunca paga doble.
        $dept = Department::factory()->create([
            'name' => 'Almacén PT',
            'code' => 'ALMACENPT',
            'weekend_unit_hours' => 6,
        ]);
        $employee = Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active']);
        $fin = $this->weekendCompType(200.0);
        $employee->compensationTypes()->attach($fin->id, ['is_active' => true]);

        AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => self::SATURDAY,
            'check_in' => '15:00:00',
            'check_out' => '05:00:00', // cruza medianoche: 14 h corridas
            'worked_hours' => 9.0,
            'overtime_hours' => 4.5,
            'velada_hours' => 4.5,
            'lunch_out' => null,
            'lunch_in' => null,
            'actual_break_minutes' => 0,
            'status' => 'present',
            'is_weekend_work' => true,
        ]);
        Authorization::factory()->create([
            'employee_id' => $employee->id,
            'date' => self::SATURDAY,
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $fin->id,
            'hours' => 1,
            'status' => Authorization::STATUS_APPROVED,
        ]);

        $report = app(WeeklyOvertimeReportService::class)
            ->buildReport($dept, Carbon::parse('2026-03-09'));
        $this->assertEqualsWithDelta(9.5, $report['rows'][0]['totals']['weekend_worked_hours'], 0.01);
        $this->assertSame(1, $report['rows'][0]['totals']['weekend_units'], 'la velada no genera unidades de finde');

        $period = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
            'payment_date' => '2026-04-03',
        ]);
        $entry = app(PayrollCalculatorService::class)
            ->calculateEmployeePayroll($period, $employee->fresh());
        $this->assertEqualsWithDelta(200.0, (float) $entry->weekend_pay, 0.01, '1 unidad × $200: la velada se paga aparte');
    }

    public function test_incomplete_checada_uses_captured_units(): void
    {
        // Caso Elsa Laura (Dani 2026-08-24): sábado con entrada 18:30 y SIN
        // salida checada, FIN capturado con 2 unidades ("el 22 es doble") +
        // domingo normal de 6.2 h con FIN de 1. Sin horas que medir, la
        // autorización aprobada es la evidencia: 2 + 1 = 3 unidades.
        $dept = Department::factory()->create([
            'name' => 'Almacén PT',
            'code' => 'ALMACENPT',
            'weekend_unit_hours' => 6,
        ]);
        $employee = Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active']);
        $fin = $this->weekendCompType(200.0);
        $employee->compensationTypes()->attach($fin->id, ['is_active' => true]);

        // Sábado: checada incompleta, FIN capturado con 2.
        AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => self::SATURDAY,
            'check_in' => '18:30:00',
            'check_out' => null,
            'worked_hours' => 0,
            'overtime_hours' => 0,
            'lunch_out' => null,
            'lunch_in' => null,
            'actual_break_minutes' => 0,
            'status' => 'present',
            'is_weekend_work' => true,
        ]);
        Authorization::factory()->create([
            'employee_id' => $employee->id,
            'date' => self::SATURDAY,
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $fin->id,
            'hours' => 2,
            'status' => Authorization::STATUS_APPROVED,
        ]);

        // Domingo: 6.2 h corridas medibles, FIN de 1.
        AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-15',
            'check_in' => '07:50:00',
            'check_out' => '14:02:00',
            'worked_hours' => 5.7,
            'overtime_hours' => 0,
            'lunch_out' => null,
            'lunch_in' => null,
            'actual_break_minutes' => 0,
            'status' => 'present',
            'is_weekend_work' => true,
        ]);
        Authorization::factory()->create([
            'employee_id' => $employee->id,
            'date' => '2026-03-15',
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $fin->id,
            'hours' => 1,
            'status' => Authorization::STATUS_APPROVED,
        ]);

        $report = app(WeeklyOvertimeReportService::class)
            ->buildReport($dept, Carbon::parse('2026-03-09'));
        $this->assertSame(3, $report['rows'][0]['totals']['weekend_units'], 'sábado capturado 2 + domingo medido 1');

        $period = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
            'payment_date' => '2026-04-03',
        ]);
        $entry = app(PayrollCalculatorService::class)
            ->calculateEmployeePayroll($period, $employee->fresh());
        $this->assertEqualsWithDelta(600.0, (float) $entry->weekend_pay, 0.01, '3 unidades × $200');
    }

    public function test_comida_paid_by_units_for_almacen_pt(): void
    {
        // Una comida por cada unidad de fin de semana (12 h = 2 comidas), solo
        // en deptos por unidades (Almacén PT).
        $dept = Department::factory()->create([
            'name' => 'Almacén PT',
            'code' => 'ALMACENPT',
            'weekend_unit_hours' => 6,
        ]);
        $employee = Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active']);

        $fin = $this->weekendCompType(200.0);
        $com = $this->comidaCompType(50.0);
        $employee->compensationTypes()->attach([
            $fin->id => ['is_active' => true],
            $com->id => ['is_active' => true],
        ]);

        $this->seedWeekendWork($employee, $fin, 12.0);
        $this->seedComida($employee, $com);

        // Reporte: comida igualada al fin de semana → 2.
        $report = app(WeeklyOvertimeReportService::class)
            ->buildReport($dept, Carbon::parse('2026-03-09'));
        $this->assertSame(2, $report['rows'][0]['totals']['weekend_units']);
        $this->assertSame(2, $report['rows'][0]['totals']['comida_count']);

        // Nómina: 2 comidas × $50 = $100 (otros) + 2 fines × $200 = $400.
        $period = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
            'payment_date' => '2026-04-03',
        ]);
        $entry = app(PayrollCalculatorService::class)
            ->calculateEmployeePayroll($period, $employee->fresh());

        $this->assertEqualsWithDelta(400.0, (float) $entry->weekend_pay, 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $entry->other_compensation_pay, 0.01);
    }

    public function test_short_or_absent_weekend_day_still_counts_one_unit(): void
    {
        // Caso Anyelo (Dani 2026-06-28): domingo trabajado 5.84h, marcado
        // "ausente", con FIN aprobado. floor(5.84/6)=0 pero la regla da mínimo 1.
        $dept = Department::factory()->create([
            'name' => 'Almacén PT',
            'code' => 'ALMACENPT',
            'weekend_unit_hours' => 6,
        ]);
        $employee = Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active']);
        $fin = $this->weekendCompType(200.0);
        $employee->compensationTypes()->attach($fin->id, ['is_active' => true]);

        AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => self::SATURDAY,
            'check_in' => '07:41:00',
            'check_out' => '14:01:00',
            'worked_hours' => 5.84,
            'overtime_hours' => 0,
            'status' => 'absent', // como en prod: sábado no programado marcado ausente
            'is_weekend_work' => true,
        ]);
        Authorization::factory()->create([
            'employee_id' => $employee->id,
            'date' => self::SATURDAY,
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $fin->id,
            'hours' => 1,
            'status' => Authorization::STATUS_APPROVED,
        ]);

        // Reporte: 1 unidad (antes 0 → no se visualizaba).
        $report = app(WeeklyOvertimeReportService::class)
            ->buildReport($dept, Carbon::parse('2026-03-09'));
        $this->assertSame(1, $report['rows'][0]['totals']['weekend_units']);

        // Nómina: 1 × 200 = 200 (antes 0 porque el día estaba "ausente").
        $period = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
            'payment_date' => '2026-04-03',
        ]);
        $entry = app(PayrollCalculatorService::class)
            ->calculateEmployeePayroll($period, $employee->fresh());
        $this->assertEqualsWithDelta(200.0, (float) $entry->weekend_pay, 0.01);
    }

    public function test_two_short_weekend_days_count_two_units(): void
    {
        // Sáb + Dom, 4h cada uno, ambos con FIN: 1 + 1 = 2 unidades (por día).
        $dept = Department::factory()->create([
            'name' => 'Almacén PT',
            'code' => 'ALMACENPT',
            'weekend_unit_hours' => 6,
        ]);
        $employee = Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active']);
        $fin = $this->weekendCompType(200.0);
        $employee->compensationTypes()->attach($fin->id, ['is_active' => true]);

        foreach (['2026-03-14', '2026-03-15'] as $date) { // sábado y domingo
            AttendanceRecord::factory()->create([
                'employee_id' => $employee->id,
                'work_date' => $date,
                'check_in' => '08:00:00',
                'check_out' => '12:00:00',
                'worked_hours' => 4.0,
                'overtime_hours' => 0,
                'status' => 'present',
                'is_weekend_work' => true,
            ]);
            Authorization::factory()->create([
                'employee_id' => $employee->id,
                'date' => $date,
                'type' => Authorization::TYPE_SPECIAL,
                'compensation_type_id' => $fin->id,
                'hours' => 1,
                'status' => Authorization::STATUS_APPROVED,
            ]);
        }

        $report = app(WeeklyOvertimeReportService::class)
            ->buildReport($dept, Carbon::parse('2026-03-09'));
        $this->assertSame(2, $report['rows'][0]['totals']['weekend_units'], 'cada día de fin de semana cuenta al menos 1');

        $period = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
            'payment_date' => '2026-04-03',
        ]);
        $entry = app(PayrollCalculatorService::class)
            ->calculateEmployeePayroll($period, $employee->fresh());
        $this->assertEqualsWithDelta(400.0, (float) $entry->weekend_pay, 0.01); // 2 × 200
    }

    public function test_threshold_department_case_angelica_double_fin_and_captured_comidas(): void
    {
        // Caso Angelica Rangel (Saldos, Dani 2026-08-25): sábado 12.38 h
        // corridas con FIN y COM capturados con 2 ("doble") + domingo 7.26 h
        // con FIN y COM de 1. Nómina y reporte: 3 fines (2 + 1, el sábado
        // dobla por llegar a 12 h) y 3 comidas (la COM vale su cantidad
        // capturada). El TE del finde (excedente sobre 7) no cambia.
        $dept = Department::factory()->create(['name' => 'Saldos', 'code' => 'SALDOS']); // umbral, sin bloques
        $employee = Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active']);
        $fin = $this->weekendCompType(200.0);
        $com = $this->comidaCompType(50.0);
        $employee->compensationTypes()->attach($fin->id, ['is_active' => true]);
        $employee->compensationTypes()->attach($com->id, ['is_active' => true]);

        foreach ([
            [self::SATURDAY, '05:41:00', '18:04:00', 9.0, 2.88, 2], // 12.38 h corridas
            ['2026-03-15', '07:50:00', '15:05:00', 6.75, 0.0, 1],   // 7.25 h corridas
        ] as [$date, $in, $out, $worked, $ot, $capturedUnits]) {
            AttendanceRecord::factory()->create([
                'employee_id' => $employee->id,
                'work_date' => $date,
                'check_in' => $in,
                'check_out' => $out,
                'worked_hours' => $worked,
                'overtime_hours' => $ot,
                'lunch_out' => null,
                'lunch_in' => null,
                'actual_break_minutes' => 0,
                'status' => 'present',
                'is_weekend_work' => true,
            ]);
            foreach ([$fin, $com] as $ct) {
                Authorization::factory()->create([
                    'employee_id' => $employee->id,
                    'date' => $date,
                    'type' => Authorization::TYPE_SPECIAL,
                    'compensation_type_id' => $ct->id,
                    'hours' => $capturedUnits,
                    'status' => Authorization::STATUS_APPROVED,
                ]);
            }
        }

        $report = app(WeeklyOvertimeReportService::class)
            ->buildReport($dept, Carbon::parse('2026-03-09'));
        $this->assertSame(3, $report['totals']['weekend_units'], 'sábado doble (12.38 h) + domingo = 3 fines');
        $this->assertSame(3, $report['rows'][0]['totals']['comida_count'], 'la COM capturada con 2 vale 2 comidas');

        $period = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
            'payment_date' => '2026-04-03',
        ]);
        $entry = app(PayrollCalculatorService::class)
            ->calculateEmployeePayroll($period, $employee->fresh());
        $this->assertEqualsWithDelta(600.0, (float) $entry->weekend_pay, 0.01, '3 fines × $200');
    }

    public function test_threshold_department_incomplete_checada_uses_captured_units(): void
    {
        // Umbral + checada sin salida: la autorización aprobada es la
        // evidencia — valen sus unidades capturadas (misma regla que Almacén).
        $dept = Department::factory()->create(['name' => 'Saldos', 'code' => 'SALDOS2']);
        $employee = Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active']);
        $fin = $this->weekendCompType(200.0);
        $employee->compensationTypes()->attach($fin->id, ['is_active' => true]);

        AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => self::SATURDAY,
            'check_in' => '18:30:00',
            'check_out' => null,
            'worked_hours' => 0,
            'overtime_hours' => 0,
            'lunch_out' => null,
            'lunch_in' => null,
            'actual_break_minutes' => 0,
            'status' => 'present',
            'is_weekend_work' => true,
        ]);
        Authorization::factory()->create([
            'employee_id' => $employee->id,
            'date' => self::SATURDAY,
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $fin->id,
            'hours' => 2,
            'status' => Authorization::STATUS_APPROVED,
        ]);

        $report = app(WeeklyOvertimeReportService::class)
            ->buildReport($dept, Carbon::parse('2026-03-09'));
        $this->assertSame(2, $report['totals']['weekend_units']);

        $period = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
            'payment_date' => '2026-04-03',
        ]);
        $entry = app(PayrollCalculatorService::class)
            ->calculateEmployeePayroll($period, $employee->fresh());
        $this->assertEqualsWithDelta(400.0, (float) $entry->weekend_pay, 0.01, '2 unidades capturadas × $200');
    }

    public function test_normal_department_pays_one_weekend_unit_at_threshold(): void
    {
        // Dani 2026-07-07: en deptos que NO pagan por unidades fijas, un día de fin
        // de semana con >= 7 h trabajadas y FIN aprobado = 1 fin de semana, pagado
        // con el monto del concepto FIN del empleado.
        $dept = Department::factory()->create(['name' => 'Calidad', 'code' => 'CAL']); // sin weekend_unit_hours
        $employee = Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active']);

        $fin = $this->weekendCompType(200.0);
        $employee->compensationTypes()->attach($fin->id, ['is_active' => true]);

        $this->seedWeekendWork($employee, $fin, 9.0); // 9 h el sábado → 1 fin de semana

        $period = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
            'payment_date' => '2026-04-03',
        ]);
        $entry = app(PayrollCalculatorService::class)
            ->calculateEmployeePayroll($period, $employee->fresh());

        $this->assertEqualsWithDelta(200.0, (float) $entry->weekend_pay, 0.01); // 1 × 200
    }

    public function test_normal_department_below_threshold_pays_no_weekend_unit(): void
    {
        // < 7 h CORRIDAS (08:00–13:00, Dani 2026-07-08) no gana fin de semana
        // aunque exista un FIN aprobado (defensa: la nómina reconfirma el
        // umbral). Esas horas van como tiempo extra, no aquí.
        $dept = Department::factory()->create(['name' => 'Calidad', 'code' => 'CAL']);
        $employee = Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active']);

        $fin = $this->weekendCompType(200.0);
        $employee->compensationTypes()->attach($fin->id, ['is_active' => true]);

        $this->seedWeekendWork($employee, $fin, 5.0, checkOut: '13:00:00'); // 5 h corridas < 7

        $period = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
            'payment_date' => '2026-04-03',
        ]);
        $entry = app(PayrollCalculatorService::class)
            ->calculateEmployeePayroll($period, $employee->fresh());

        $this->assertEqualsWithDelta(0.0, (float) $entry->weekend_pay, 0.01);
    }

    public function test_normal_department_report_overtime_uses_weekend_threshold(): void
    {
        // El reporte muestra el OT del fin de semana con el mismo umbral que la
        // nómina: 9 h CORRIDAS (08:00–17:00) − 7 = 2 h (no lo que exceda del
        // horario, y sin descontar comida — Dani 2026-07-08).
        $dept = Department::factory()->create(['name' => 'Calidad', 'code' => 'CAL']);
        $employee = Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active']);
        $fin = $this->weekendCompType(200.0);

        $this->seedWeekendWork($employee, $fin, 9.0, checkOut: '17:00:00'); // 9 h corridas el sábado

        // Autoriza 2 h de tiempo extra ese día (el excedente sobre 7). El reporte
        // empareja el OT por CÓDIGO del concepto (HE), no por tipo.
        $he = CompensationType::updateOrCreate(
            ['code' => 'HE'],
            [
                'name' => 'Horas Extra',
                'calculation_type' => 'percentage',
                'percentage_value' => 100,
                'application_mode' => CompensationType::APPLICATION_PER_HOUR,
                'authorization_type' => Authorization::TYPE_OVERTIME,
                'is_active' => true,
            ],
        );
        Authorization::factory()->create([
            'employee_id' => $employee->id,
            'date' => self::SATURDAY,
            'type' => Authorization::TYPE_OVERTIME,
            'compensation_type_id' => $he->id,
            'hours' => 2,
            'status' => Authorization::STATUS_APPROVED,
        ]);

        $report = app(WeeklyOvertimeReportService::class)
            ->buildReport($dept, Carbon::parse('2026-03-09'));
        $row = collect($report['rows'])->first(fn ($r) => $r['employee']['id'] === $employee->id);

        // Detectado por umbral = 9 − 7 = 2 h; aprobado = 2 h.
        $this->assertEqualsWithDelta(2.0, $row['days'][self::SATURDAY]['detected_overtime_hours'], 0.01);
        $this->assertEqualsWithDelta(2.0, $row['days'][self::SATURDAY]['overtime_hours'], 0.01);
        // El fin de semana se ve como 1 (la autorización FIN cuenta como una unidad).
        $this->assertEqualsWithDelta(1.0, $row['totals']['weekend_hours'], 0.01);
    }
}
