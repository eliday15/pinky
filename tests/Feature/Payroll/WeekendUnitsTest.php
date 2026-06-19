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
        return CompensationType::factory()->fixed($fixed)->create([
            'name' => 'Fin de Semana',
            'code' => 'FIN',
            'application_mode' => CompensationType::APPLICATION_PER_DAY,
            'authorization_type' => Authorization::TYPE_SPECIAL,
            'attendance_pull_rule' => CompensationType::PULL_RULE_WEEKEND,
            'is_active' => true,
        ]);
    }

    /**
     * Un sábado de 12 h trabajadas + autorización FIN aprobada.
     */
    private function seedWeekendWork(Employee $employee, CompensationType $fin, float $workedHours = 12.0): void
    {
        AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => self::SATURDAY,
            'check_in' => '08:00:00',
            'check_out' => '20:00:00',
            'worked_hours' => $workedHours,
            'overtime_hours' => 0,
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

    public function test_weekend_units_round_to_nearest_whole_number(): void
    {
        // Fin de semana a números cerrados (WhatsApp 2026-06-19): 9 h ÷ 6 = 1.5,
        // que redondea a 2 unidades (no fracciones), tanto en reporte como en
        // nómina.
        $dept = Department::factory()->create([
            'name' => 'Almacén PT',
            'code' => 'ALMACENPT',
            'weekend_unit_hours' => 6,
        ]);
        $employee = Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active']);

        $fin = $this->weekendCompType(200.0);
        $employee->compensationTypes()->attach($fin->id, ['is_active' => true]);

        $this->seedWeekendWork($employee, $fin, 9.0); // 9 h trabajadas en sábado

        // Reporte: 9 ÷ 6 = 1.5 → 2 unidades cerradas.
        $report = app(WeeklyOvertimeReportService::class)
            ->buildReport($dept, Carbon::parse('2026-03-09'));
        $this->assertEqualsWithDelta(2.0, $report['totals']['weekend_units'], 0.01);
        $this->assertEqualsWithDelta(2.0, $report['rows'][0]['totals']['weekend_units'], 0.01);

        // Nómina: 2 unidades × $200 = $400 (no 1.5 × $200 = $300).
        $period = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
            'payment_date' => '2026-04-03',
        ]);
        $entry = app(PayrollCalculatorService::class)
            ->calculateEmployeePayroll($period, $employee->fresh());

        $this->assertEqualsWithDelta(400.0, (float) $entry->weekend_pay, 0.01);
    }
}
