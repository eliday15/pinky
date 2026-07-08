<?php

namespace Tests\Feature\Payroll;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\Schedule;
use App\Models\User;
use App\Services\PayrollCalculatorService;
use App\Services\Reports\WeeklyOvertimeReportService;
use Carbon\Carbon;
use Tests\FeatureTestCase;

/**
 * Conceptos por día aprobados en días SIN timecard medible (caso Julissa
 * 2026-07-08: entrada sin salida el domingo → su FIN y Comida aprobados no
 * aparecían en el reporte y el FIN no pagaba).
 *
 * Regla: cuando el día no tiene checada completa (sin fila, o entrada sin
 * salida), los conceptos POR DÍA aprobados (FIN, Comida, Cena, Velada) valen
 * por su autorización — no hay horas que medir. Cuando SÍ hay checada
 * completa, sigue mandando la regla de horas (umbral de 7 h para el FIN fuera
 * de Almacén PT). Las horas extra por hora siguen topadas al timecard para
 * quien checa (auditoría #20).
 */
class UnbackedDayAuthorizedConceptsTest extends FeatureTestCase
{
    private const SATURDAY = '2026-06-06';

    private const SUNDAY = '2026-06-07';

    private function calculator(): PayrollCalculatorService
    {
        return app(PayrollCalculatorService::class);
    }

    /** Depto normal (sin unidades de Almacén) con umbral de finde 7 h. */
    private function thresholdDepartment(): Department
    {
        return Department::factory()->create([
            'weekend_unit_hours' => null,
            'weekend_overtime_after_hours' => 7,
        ]);
    }

    private function employeeIn(Department $department): Employee
    {
        $schedule = Schedule::factory()->create([
            'entry_time' => '08:00',
            'exit_time' => '17:00',
            'daily_work_hours' => 8,
            'break_minutes' => 60,
        ]);

        return Employee::factory()->create([
            'status' => 'active',
            'department_id' => $department->id,
            'schedule_id' => $schedule->id,
            'daily_salary' => 800.00,
            'hourly_rate' => 100.00,
            // Alta fija anterior al periodo (el hire_date aleatorio del
            // factory puede caer dentro de la semana y recortar el base).
            'hire_date' => '2025-01-01',
        ]);
    }

    /** Concepto por código (reutiliza el seedeado si existe), asignado al empleado. */
    private function typeFor(Employee $employee, string $code, array $attributes): CompensationType
    {
        $existing = CompensationType::where('code', $code)->first();

        if ($existing) {
            $existing->update($attributes);
            $type = $existing->fresh();
        } else {
            $type = CompensationType::factory()->create(array_merge(['code' => $code], $attributes));
        }

        $employee->compensationTypes()->attach($type->id, ['is_active' => true]);

        return $type;
    }

    /** Concepto FIN por día a monto fijo, asignado al empleado. */
    private function finTypeFor(Employee $employee, float $amount = 472.00): CompensationType
    {
        return $this->typeFor($employee, 'FIN', [
            'calculation_type' => 'fixed',
            'percentage_value' => null,
            'fixed_amount' => $amount,
            'is_active' => true,
            'application_mode' => CompensationType::APPLICATION_PER_DAY,
            'authorization_type' => Authorization::TYPE_SPECIAL,
            'attendance_pull_rule' => CompensationType::PULL_RULE_WEEKEND,
        ]);
    }

    private function approvedConcept(Employee $employee, CompensationType $type, string $date, float $hours = 1.0): Authorization
    {
        return Authorization::create([
            'employee_id' => $employee->id,
            'requested_by' => User::factory()->create()->id,
            'type' => $type->authorization_type ?? Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $type->id,
            'date' => $date,
            'hours' => $hours,
            'reason' => 'concepto aprobado',
            'status' => Authorization::STATUS_APPROVED,
        ]);
    }

    private function monthly(): PayrollPeriod
    {
        return PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);
    }

    // ------------------------------------------------------------------
    // Nómina: unidades de fin de semana (deptos con umbral de 7 h)
    // ------------------------------------------------------------------

    public function test_fin_pays_when_day_has_entry_but_no_exit(): void
    {
        // Caso Julissa: vino el domingo (entrada) pero sin salida — la
        // autorización aprobada es la evidencia y el FIN paga 1 día.
        $employee = $this->employeeIn($this->thresholdDepartment());
        $fin = $this->finTypeFor($employee);
        $this->approvedConcept($employee, $fin, self::SUNDAY);

        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => self::SUNDAY,
            'check_in' => '07:11:00',
            'check_out' => null,
            'status' => 'present',
            'worked_hours' => 0,
            'overtime_hours' => 0,
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($this->monthly(), $employee);

        $this->assertEqualsWithDelta(472.00, (float) $entry->weekend_pay, 0.01, 'sin salida no hay horas que medir: manda la autorización');
    }

    public function test_fin_pays_when_day_has_no_attendance_row(): void
    {
        $employee = $this->employeeIn($this->thresholdDepartment());
        $fin = $this->finTypeFor($employee);
        $this->approvedConcept($employee, $fin, self::SATURDAY);

        $entry = $this->calculator()->calculateEmployeePayroll($this->monthly(), $employee);

        $this->assertEqualsWithDelta(472.00, (float) $entry->weekend_pay, 0.01);
    }

    public function test_fin_below_threshold_with_complete_punches_still_pays_zero(): void
    {
        // Con checada completa la regla de Dani sigue: < 7 h = 0 fines de
        // semana (esas horas van como tiempo extra).
        $employee = $this->employeeIn($this->thresholdDepartment());
        $fin = $this->finTypeFor($employee);
        $this->approvedConcept($employee, $fin, self::SATURDAY);

        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => self::SATURDAY,
            'check_in' => '08:00:00',
            'check_out' => '14:00:00',
            'status' => 'present',
            'worked_hours' => 5,
            'overtime_hours' => 0,
            'is_weekend_work' => true,
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($this->monthly(), $employee);

        $this->assertEqualsWithDelta(0.00, (float) $entry->weekend_pay, 0.01, '5 h medidas < 7: sin fin de semana');
    }

    public function test_fin_at_threshold_with_complete_punches_pays_one(): void
    {
        $employee = $this->employeeIn($this->thresholdDepartment());
        $fin = $this->finTypeFor($employee);
        $this->approvedConcept($employee, $fin, self::SATURDAY);

        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => self::SATURDAY,
            'check_in' => '08:00:00',
            'check_out' => '16:00:00',
            'status' => 'present',
            'worked_hours' => 7,
            'overtime_hours' => 0,
            'is_weekend_work' => true,
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($this->monthly(), $employee);

        $this->assertEqualsWithDelta(472.00, (float) $entry->weekend_pay, 0.01, '7 h medidas = 1 fin de semana');
    }

    // ------------------------------------------------------------------
    // Formato de Tiempo Extra semanal
    // ------------------------------------------------------------------

    public function test_weekly_report_shows_fin_and_comida_on_unbacked_day(): void
    {
        $department = $this->thresholdDepartment();
        $employee = $this->employeeIn($department);
        $fin = $this->finTypeFor($employee);

        $com = $this->typeFor($employee, 'COM', [
            'calculation_type' => 'fixed',
            'percentage_value' => null,
            'fixed_amount' => 50.00,
            'is_active' => true,
            'application_mode' => CompensationType::APPLICATION_PER_DAY,
            'authorization_type' => Authorization::TYPE_SPECIAL,
            'attendance_pull_rule' => CompensationType::PULL_RULE_COMIDA,
        ]);

        $this->approvedConcept($employee, $fin, self::SUNDAY);
        $this->approvedConcept($employee, $com, self::SUNDAY);

        // Entrada sin salida: día no medible.
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => self::SUNDAY,
            'check_in' => '07:11:00',
            'check_out' => null,
            'status' => 'present',
            'worked_hours' => 0,
        ]);

        $report = app(WeeklyOvertimeReportService::class)
            ->buildReport($department, Carbon::parse('2026-06-01'));

        $day = $report['rows'][0]['days'][self::SUNDAY];

        $this->assertEqualsWithDelta(1.0, $day['weekend_hours'], 0.01, 'el FIN aprobado se muestra aunque falte la salida');
        $this->assertTrue($day['has_weekend_auth']);
        $this->assertSame(1, $day['comida_marker']);
        $this->assertEqualsWithDelta(1.0, $report['rows'][0]['totals']['weekend_hours'], 0.01);
        $this->assertSame(1, $report['rows'][0]['totals']['comida_count']);
    }

    public function test_weekly_report_keeps_overtime_capped_for_punching_employee(): void
    {
        // Las horas extra POR HORA de quien checa siguen topadas al timecard:
        // un día con entrada sin salida no las muestra (auditoría #20).
        $department = $this->thresholdDepartment();
        $employee = $this->employeeIn($department);

        $he = CompensationType::factory()->fixed(50.00)->create([
            'code' => 'HE',
            'application_mode' => CompensationType::APPLICATION_PER_HOUR,
            'authorization_type' => Authorization::TYPE_OVERTIME,
        ]);
        $employee->compensationTypes()->attach($he->id, ['is_active' => true]);

        Authorization::create([
            'employee_id' => $employee->id,
            'requested_by' => User::factory()->create()->id,
            'type' => Authorization::TYPE_OVERTIME,
            'compensation_type_id' => $he->id,
            'date' => '2026-06-03',
            'hours' => 1.0,
            'reason' => 'TE sin salida marcada',
            'status' => Authorization::STATUS_APPROVED,
        ]);

        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-06-03',
            'check_in' => '07:07:00',
            'check_out' => null,
            'status' => 'present',
            'worked_hours' => 0,
        ]);

        $report = app(WeeklyOvertimeReportService::class)
            ->buildReport($department, Carbon::parse('2026-06-01'));

        $day = $report['rows'][0]['days']['2026-06-03'];

        $this->assertEqualsWithDelta(0.0, $day['overtime_hours'], 0.01, 'sin salida no hay TE que respaldar para quien checa');
    }
}
