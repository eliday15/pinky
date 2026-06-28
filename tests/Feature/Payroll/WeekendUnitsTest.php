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
     * de fin de semana contables son worked_hours + overtime_hours (toda la
     * jornada del fin de semana cuenta para las unidades).
     */
    private function seedWeekendWork(Employee $employee, CompensationType $fin, float $workedHours = 12.0, float $overtimeHours = 0.0): void
    {
        AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => self::SATURDAY,
            'check_in' => '08:00:00',
            'check_out' => '20:00:00',
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

    public function test_report_keeps_hours_for_departments_without_the_rule(): void
    {
        $dept = Department::factory()->create(['name' => 'Producción', 'code' => 'PROD']); // sin regla
        $employee = Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active']);
        $this->seedWeekendWork($employee, $this->weekendCompType());

        $report = app(WeeklyOvertimeReportService::class)
            ->buildReport($dept, Carbon::parse('2026-03-09'));

        $this->assertNull($report['weekend_unit_hours']);
        $this->assertNull($report['totals']['weekend_units']);
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
}
