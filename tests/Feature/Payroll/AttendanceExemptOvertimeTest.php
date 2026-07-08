<?php

namespace Tests\Feature\Payroll;

use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\PayrollCalculatorService;
use App\Services\Reports\WeeklyOvertimeReportService;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\FeatureTestCase;

/**
 * Horas extra de empleados exentos de asistencia (no checan).
 *
 * Estos empleados no tienen checadas, así que su tiempo extra aprobado no
 * existe en attendance_records (donde vive overtime_authorized_hours). La
 * fuente de verdad para ellos son las autorizaciones aprobadas, sin tope al
 * timecard, en TODAS las superficies:
 *
 *   - Nómina (PayrollCalculatorService): paga las horas aprobadas.
 *   - Formato de Tiempo Extra semanal (WeeklyOvertimeReportService).
 *   - Reporte de Tiempo Extra (/reports/overtime) y su CSV.
 */
class AttendanceExemptOvertimeTest extends FeatureTestCase
{
    private const WEDNESDAY = '2026-06-03';

    private function calculator(): PayrollCalculatorService
    {
        return app(PayrollCalculatorService::class);
    }

    /** Empleado exento (no checa) con sueldo diario 800 en un depto dado. */
    private function exemptEmployee(?Department $department = null): Employee
    {
        return Employee::factory()->create([
            'status' => 'active',
            'is_attendance_exempt' => true,
            'zkteco_user_id' => null,
            'daily_salary' => 800.00,
            'hourly_rate' => 100.00,
            'department_id' => ($department ?? Department::factory()->create())->id,
            // Alta fija anterior al periodo (el hire_date aleatorio del
            // factory puede caer dentro de la semana y recortar el base).
            'hire_date' => '2025-01-01',
        ]);
    }

    /** Concepto HE por hora a monto fijo, asignado al empleado. */
    private function heTypeFor(Employee $employee, float $amount = 50.00): CompensationType
    {
        $he = CompensationType::factory()->fixed($amount)->create([
            'code' => 'HE',
            'application_mode' => CompensationType::APPLICATION_PER_HOUR,
            'authorization_type' => Authorization::TYPE_OVERTIME,
        ]);
        $employee->compensationTypes()->attach($he->id, ['is_active' => true]);

        return $he;
    }

    private function approvedOvertime(Employee $employee, CompensationType $type, string $date, float $hours): Authorization
    {
        return Authorization::create([
            'employee_id' => $employee->id,
            'requested_by' => User::factory()->create()->id,
            'type' => Authorization::TYPE_OVERTIME,
            'compensation_type_id' => $type->id,
            'date' => $date,
            'hours' => $hours,
            'reason' => 'TE de empleado sin checador',
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

    private function weekly(): PayrollPeriod
    {
        return PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-01', // lunes
            'end_date' => '2026-06-07',   // domingo
        ]);
    }

    // ------------------------------------------------------------------
    // Nómina
    // ------------------------------------------------------------------

    public function test_monthly_payroll_pays_approved_overtime_without_punches(): void
    {
        $employee = $this->exemptEmployee();
        $he = $this->heTypeFor($employee, 50.00);
        $this->approvedOvertime($employee, $he, self::WEDNESDAY, 3.0);

        $entry = $this->calculator()->calculateEmployeePayroll($this->monthly(), $employee);

        $this->assertEqualsWithDelta(3.0, (float) $entry->overtime_authorized_hours, 0.01, 'las horas aprobadas son la fuente de verdad sin checadas');
        $this->assertEqualsWithDelta(150.00, (float) $entry->overtime_pay, 0.01, '3h × $50 del concepto HE');
    }

    public function test_multiple_approved_authorizations_sum_their_hours(): void
    {
        $employee = $this->exemptEmployee();
        $he = $this->heTypeFor($employee, 50.00);
        $this->approvedOvertime($employee, $he, '2026-06-02', 2.0);
        $this->approvedOvertime($employee, $he, self::WEDNESDAY, 1.5);

        $entry = $this->calculator()->calculateEmployeePayroll($this->monthly(), $employee);

        $this->assertEqualsWithDelta(3.5, (float) $entry->overtime_authorized_hours, 0.01);
        $this->assertEqualsWithDelta(175.00, (float) $entry->overtime_pay, 0.01);
    }

    public function test_pending_authorization_does_not_pay(): void
    {
        $employee = $this->exemptEmployee();
        $he = $this->heTypeFor($employee, 50.00);
        $auth = $this->approvedOvertime($employee, $he, self::WEDNESDAY, 3.0);
        $auth->update(['status' => Authorization::STATUS_PENDING]);

        $entry = $this->calculator()->calculateEmployeePayroll($this->monthly(), $employee);

        $this->assertEqualsWithDelta(0.0, (float) $entry->overtime_pay, 0.01, 'solo pagan las aprobadas/pagadas');
    }

    public function test_weekly_period_does_not_pay_exempt_overtime(): void
    {
        // Los extras son de la pasada mensual; el semanal solo paga base.
        $employee = $this->exemptEmployee();
        $he = $this->heTypeFor($employee, 50.00);
        $this->approvedOvertime($employee, $he, self::WEDNESDAY, 3.0);

        $entry = $this->calculator()->calculateEmployeePayroll($this->weekly(), $employee);

        $this->assertEqualsWithDelta(0.0, (float) $entry->overtime_pay, 0.01);
        $this->assertEqualsWithDelta(5600.00, (float) $entry->regular_pay, 0.01, 'base SD×7 intacta');
    }

    public function test_weekend_pull_rule_authorization_is_not_counted_as_overtime(): void
    {
        // Un FIN (weekend pull rule) de tipo overtime NO entra a la métrica de
        // horas extra del exento: el fin de semana paga por su propio camino.
        $employee = $this->exemptEmployee();
        $this->heTypeFor($employee, 50.00);

        $fin = CompensationType::factory()->fixed(100.00)->create([
            'code' => 'FIN',
            'application_mode' => CompensationType::APPLICATION_PER_DAY,
            'authorization_type' => Authorization::TYPE_OVERTIME,
            'attendance_pull_rule' => CompensationType::PULL_RULE_WEEKEND,
        ]);
        $this->approvedOvertime($employee, $fin, '2026-06-06', 5.0); // sábado

        $entry = $this->calculator()->calculateEmployeePayroll($this->monthly(), $employee);

        $this->assertEqualsWithDelta(0.0, (float) $entry->overtime_authorized_hours, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $entry->overtime_pay, 0.01);
    }

    // ------------------------------------------------------------------
    // Formato de Tiempo Extra semanal (WeeklyOvertimeReportService)
    // ------------------------------------------------------------------

    public function test_weekly_te_report_shows_exempt_overtime_without_punches(): void
    {
        $department = Department::factory()->create(['name' => 'Diseño', 'code' => 'DIS']);
        $employee = $this->exemptEmployee($department);
        $he = $this->heTypeFor($employee, 50.00);
        $this->approvedOvertime($employee, $he, self::WEDNESDAY, 3.0);

        $report = app(WeeklyOvertimeReportService::class)
            ->buildReport($department, Carbon::parse('2026-06-01'));

        $day = $report['rows'][0]['days'][self::WEDNESDAY];

        $this->assertEqualsWithDelta(3.0, $day['overtime_hours'], 0.01, 'las horas aprobadas se muestran sin tope al timecard');
        $this->assertEqualsWithDelta(3.0, $day['m_hours'], 0.01);
        $this->assertEqualsWithDelta(0.0, $day['pending_overtime_hours'], 0.01, 'sin checadas no hay TE detectado pendiente');
        $this->assertEqualsWithDelta(3.0, $report['rows'][0]['totals']['total_hours'], 0.01);
    }

    public function test_weekly_te_report_is_blank_for_exempt_without_authorizations(): void
    {
        $department = Department::factory()->create();
        $this->exemptEmployee($department);

        $report = app(WeeklyOvertimeReportService::class)
            ->buildReport($department, Carbon::parse('2026-06-01'));

        $this->assertEqualsWithDelta(0.0, $report['rows'][0]['totals']['total_hours'], 0.01);
    }

    // ------------------------------------------------------------------
    // Reporte de Tiempo Extra (/reports/overtime) + CSV
    // ------------------------------------------------------------------

    public function test_overtime_report_page_lists_exempt_employee(): void
    {
        $this->actingAsAdmin();

        $employee = $this->exemptEmployee();
        $he = $this->heTypeFor($employee, 50.00);
        $this->approvedOvertime($employee, $he, self::WEDNESDAY, 3.0);

        $this->get(route('reports.overtime', [
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Overtime')
                ->where('byEmployee', fn ($rows) => collect($rows)->contains(
                    fn ($row) => ($row['employee']['id'] ?? null) === $employee->id
                        && abs((float) $row['total_authorized'] - 3.0) < 0.01
                        && abs((float) $row['estimated_cost'] - 150.0) < 0.01
                ))
                ->where('summary.total_authorized_hours', fn ($v) => abs((float) $v - 3.0) < 0.01));
    }

    public function test_overtime_export_includes_exempt_employee(): void
    {
        $this->actingAsAdmin();

        $employee = $this->exemptEmployee();
        $employee->update(['full_name' => 'Xenia Exenta', 'employee_number' => 'EMP-EX-1']);
        $he = $this->heTypeFor($employee, 50.00);
        $this->approvedOvertime($employee, $he, self::WEDNESDAY, 3.0);

        $response = $this->get(route('reports.export.overtime', [
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]));
        $response->assertOk();

        ob_start();
        $response->sendContent();
        $body = ob_get_clean();

        $this->assertStringContainsString('Xenia Exenta', $body);
        $this->assertStringContainsString(self::WEDNESDAY, $body);
    }
}
