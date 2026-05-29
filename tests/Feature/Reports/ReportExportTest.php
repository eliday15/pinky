<?php

namespace Tests\Feature\Reports;

use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\Schedule;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FeatureTestCase;

/**
 * Front-to-back contract tests for ReportExportController (CSV downloads).
 *
 * Every export action shares a closure middleware requiring ANY of
 * reports.view_all|view_team|view_own. Per the seeder:
 *   - admin      => view_all   (allowed, all active employees)
 *   - employee   => view_own   (allowed through the gate, own row only)
 *   - supervisor => view_team  (allowed, team-scoped; empty-but-valid file
 *                               when the supervisor has no linked employee)
 *   - rrhh       => NO reports.* permission => 403
 *   - guest      => redirect to login
 *
 * Each action streams a UTF-8 CSV (text/csv) via Response::streamDownload.
 * We assert 200 + the text/csv content-type and an attachment disposition;
 * for a handful we capture the streamed body to confirm headers/rows render
 * (proving the data path doesn't throw). Body bytes beyond that are not
 * asserted, per convention.
 */
class ReportExportTest extends FeatureTestCase
{
    private const MONDAY = '2026-03-09';

    private const WEEK_END = '2026-03-15';

    /**
     * Active employee on the default Mon-Fri schedule.
     */
    private function weekdayEmployee(array $attrs = []): Employee
    {
        $schedule = Schedule::factory()->create();

        return Employee::factory()->create(array_merge([
            'schedule_id' => $schedule->id,
            'status' => 'active',
        ], $attrs));
    }

    /**
     * Assert a response is an OK CSV attachment download.
     */
    private function assertCsvDownload($response): void
    {
        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));
    }

    /**
     * Capture the streamed CSV body as a string.
     */
    private function streamedBody($response): string
    {
        ob_start();
        $response->sendContent();

        return ob_get_clean();
    }

    /**
     * All CSV export routes that share the same RBAC gate.
     *
     * @return array<string, array{0:string}>
     */
    public static function exportRouteProvider(): array
    {
        return [
            'daily' => ['reports.export.daily'],
            'weekly' => ['reports.export.weekly'],
            'monthly' => ['reports.export.monthly'],
            'absences' => ['reports.export.absences'],
            'lateArrivals' => ['reports.export.lateArrivals'],
            'vacationBalance' => ['reports.export.vacationBalance'],
            'incidents' => ['reports.export.incidents'],
            'overtime' => ['reports.export.overtime'],
            'faltas' => ['reports.export.faltas'],
            'asistencia' => ['reports.export.asistencia'],
            'retardos' => ['reports.export.retardos'],
            'earlyDepartures' => ['reports.export.earlyDepartures'],
        ];
    }

    // ------------------------------------------------------------------
    // RBAC across every export route (admin OK + CSV, others 403, guest login)
    // ------------------------------------------------------------------

    #[DataProvider('exportRouteProvider')]
    public function test_admin_can_download_export(string $routeName): void
    {
        $this->actingAsAdmin();

        $this->assertCsvDownload($this->get(route($routeName)));
    }

    #[DataProvider('exportRouteProvider')]
    public function test_rrhh_is_forbidden_from_export(string $routeName): void
    {
        $this->actingAsRrhh();

        $this->get(route($routeName))->assertForbidden();
    }

    #[DataProvider('exportRouteProvider')]
    public function test_supervisor_can_download_export_team_scoped(string $routeName): void
    {
        // The supervisor role now carries reports.view_team, so it passes the
        // export gate and downloads a team-scoped CSV (200). With no linked
        // employee the file is empty-but-valid; we only assert the download
        // contract, not the body bytes.
        $this->actingAsSupervisor();

        $this->assertCsvDownload($this->get(route($routeName)));
    }

    #[DataProvider('exportRouteProvider')]
    public function test_guest_is_redirected_to_login(string $routeName): void
    {
        $this->get(route($routeName))->assertRedirect(route('login'));
    }

    #[DataProvider('exportRouteProvider')]
    public function test_employee_with_view_own_can_download(string $routeName): void
    {
        $user = $this->actingAsEmployee();
        $this->attachEmployee($user);

        $this->assertCsvDownload($this->get(route($routeName)));
    }

    // ------------------------------------------------------------------
    // Content sanity: headers + a data row render without throwing
    // ------------------------------------------------------------------

    public function test_daily_export_includes_header_and_employee_row(): void
    {
        $this->actingAsAdmin();

        $dept = Department::factory()->create(['name' => 'Ventas']);
        $employee = $this->weekdayEmployee([
            'department_id' => $dept->id,
            'full_name' => 'Juan Perez',
            'employee_number' => 'EMP-DAILY-1',
        ]);

        AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => self::MONDAY,
            'status' => 'present',
            'worked_hours' => 8.00,
        ]);

        $response = $this->get(route('reports.export.daily', ['date' => self::MONDAY]));
        $this->assertCsvDownload($response);

        $body = $this->streamedBody($response);
        $this->assertStringContainsString('Empleado', $body);
        $this->assertStringContainsString('Juan Perez', $body);
        $this->assertStringContainsString('EMP-DAILY-1', $body);
        $this->assertStringContainsString('Presente', $body);
    }

    public function test_absences_export_lists_only_absent_records(): void
    {
        $this->actingAsAdmin();

        $employee = $this->weekdayEmployee([
            'full_name' => 'Ana Ausente',
            'employee_number' => 'EMP-ABS-1',
        ]);
        $present = $this->weekdayEmployee([
            'full_name' => 'Pedro Presente',
            'employee_number' => 'EMP-PRES-1',
        ]);

        AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => self::MONDAY,
            'status' => 'absent',
            'check_in' => null,
        ]);
        AttendanceRecord::factory()->create([
            'employee_id' => $present->id,
            'work_date' => self::MONDAY,
            'status' => 'present',
        ]);

        $response = $this->get(route('reports.export.absences', [
            'start_date' => self::MONDAY,
            'end_date' => self::WEEK_END,
        ]));
        $this->assertCsvDownload($response);

        $body = $this->streamedBody($response);
        $this->assertStringContainsString('Ana Ausente', $body);
        $this->assertStringNotContainsString('Pedro Presente', $body);
    }

    public function test_incidents_export_renders_incident_row(): void
    {
        $this->actingAsAdmin();

        $dept = Department::factory()->create(['name' => 'RH']);
        $employee = $this->weekdayEmployee([
            'department_id' => $dept->id,
            'full_name' => 'Carla Incidencia',
            'employee_number' => 'EMP-INC-1',
        ]);
        $type = IncidentType::factory()->vacation()->create(['name' => 'Vacaciones']);

        Incident::factory()->approved()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'start_date' => '2026-03-10',
            'end_date' => '2026-03-12',
            'days_count' => 3,
        ]);

        $response = $this->get(route('reports.export.incidents', [
            'start_date' => self::MONDAY,
            'end_date' => self::WEEK_END,
        ]));
        $this->assertCsvDownload($response);

        $body = $this->streamedBody($response);
        $this->assertStringContainsString('Carla Incidencia', $body);
        $this->assertStringContainsString('Vacaciones', $body);
        $this->assertStringContainsString('Aprobada', $body);
    }

    public function test_vacation_balance_export_computes_balance_row(): void
    {
        $this->actingAsAdmin();

        $this->weekdayEmployee([
            'full_name' => 'Vale Vacaciones',
            'employee_number' => 'EMP-VAC-1',
            'vacation_days_entitled' => 12,
            'vacation_days_used' => 4,
        ]);

        $response = $this->get(route('reports.export.vacationBalance'));
        $this->assertCsvDownload($response);

        $body = $this->streamedBody($response);
        $this->assertStringContainsString('Vale Vacaciones', $body);
        $this->assertStringContainsString('Saldo', $body); // header column present
    }

    public function test_overtime_export_includes_only_overtime_records(): void
    {
        $this->actingAsAdmin();

        $withOt = $this->weekdayEmployee([
            'full_name' => 'Otto Overtime',
            'employee_number' => 'EMP-OT-1',
        ]);
        $noOt = $this->weekdayEmployee([
            'full_name' => 'Nora NoOvertime',
            'employee_number' => 'EMP-OT-2',
        ]);

        AttendanceRecord::factory()->withOvertime()->create([
            'employee_id' => $withOt->id,
            'work_date' => self::MONDAY,
        ]);
        AttendanceRecord::factory()->create([
            'employee_id' => $noOt->id,
            'work_date' => self::MONDAY,
            'overtime_hours' => 0.00,
        ]);

        $response = $this->get(route('reports.export.overtime', [
            'start_date' => self::MONDAY,
            'end_date' => self::WEEK_END,
        ]));
        $this->assertCsvDownload($response);

        $body = $this->streamedBody($response);
        $this->assertStringContainsString('Otto Overtime', $body);
        $this->assertStringNotContainsString('Nora NoOvertime', $body);
    }

    public function test_retardos_export_includes_only_late_records(): void
    {
        $this->actingAsAdmin();

        $late = $this->weekdayEmployee([
            'full_name' => 'Tomas Tarde',
            'employee_number' => 'EMP-LATE-1',
        ]);

        AttendanceRecord::factory()->late()->create([
            'employee_id' => $late->id,
            'work_date' => self::MONDAY,
            'late_minutes' => 25,
        ]);

        $response = $this->get(route('reports.export.retardos', [
            'start_date' => self::MONDAY,
            'end_date' => self::WEEK_END,
        ]));
        $this->assertCsvDownload($response);

        $body = $this->streamedBody($response);
        $this->assertStringContainsString('Tomas Tarde', $body);
        $this->assertStringContainsString('Minutos Retardo', $body);
    }

    public function test_weekly_export_aggregates_employee_counts(): void
    {
        $this->actingAsAdmin();

        $employee = $this->weekdayEmployee([
            'full_name' => 'Wendy Semanal',
            'employee_number' => 'EMP-WK-1',
        ]);

        // One present day + one late day within the Mon-based week.
        AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => self::MONDAY,
            'status' => 'present',
            'worked_hours' => 8.00,
        ]);
        AttendanceRecord::factory()->late()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-10',
            'late_minutes' => 12,
        ]);

        $response = $this->get(route('reports.export.weekly', ['start_date' => self::MONDAY]));
        $this->assertCsvDownload($response);

        $body = $this->streamedBody($response);
        $this->assertStringContainsString('Horas Trabajadas', $body); // header column
        $this->assertStringContainsString('Wendy Semanal', $body);
        $this->assertStringContainsString('EMP-WK-1', $body);
    }

    public function test_monthly_export_renders_employee_with_late_minutes_column(): void
    {
        $this->actingAsAdmin();

        $employee = $this->weekdayEmployee([
            'full_name' => 'Mara Mensual',
            'employee_number' => 'EMP-MO-1',
        ]);

        AttendanceRecord::factory()->late()->create([
            'employee_id' => $employee->id,
            'work_date' => self::MONDAY,
            'late_minutes' => 20,
        ]);

        $response = $this->get(route('reports.export.monthly', ['month' => 3, 'year' => 2026]));
        $this->assertCsvDownload($response);

        $body = $this->streamedBody($response);
        $this->assertStringContainsString('Minutos Retardo', $body); // monthly-only header column
        $this->assertStringContainsString('Mara Mensual', $body);
    }

    public function test_late_arrivals_export_lists_only_late_records(): void
    {
        $this->actingAsAdmin();

        $late = $this->weekdayEmployee([
            'full_name' => 'Lalo Tarde',
            'employee_number' => 'EMP-LA-1',
        ]);
        $present = $this->weekdayEmployee([
            'full_name' => 'Puntual Pepe',
            'employee_number' => 'EMP-LA-2',
        ]);

        AttendanceRecord::factory()->late()->create([
            'employee_id' => $late->id,
            'work_date' => self::MONDAY,
            'late_minutes' => 30,
        ]);
        AttendanceRecord::factory()->create([
            'employee_id' => $present->id,
            'work_date' => self::MONDAY,
            'status' => 'present',
        ]);

        $response = $this->get(route('reports.export.lateArrivals', [
            'start_date' => self::MONDAY,
            'end_date' => self::WEEK_END,
        ]));
        $this->assertCsvDownload($response);

        $body = $this->streamedBody($response);
        $this->assertStringContainsString('Lalo Tarde', $body);
        $this->assertStringNotContainsString('Puntual Pepe', $body);
        $this->assertStringContainsString('Minutos Retardo', $body);
    }

    public function test_early_departures_export_lists_only_early_records(): void
    {
        $this->actingAsAdmin();

        $early = $this->weekdayEmployee([
            'full_name' => 'Eva Temprano',
            'employee_number' => 'EMP-ED-1',
        ]);
        $onTime = $this->weekdayEmployee([
            'full_name' => 'Quedado Quique',
            'employee_number' => 'EMP-ED-2',
        ]);

        AttendanceRecord::factory()->create([
            'employee_id' => $early->id,
            'work_date' => self::MONDAY,
            'status' => 'present',
            'early_departure_minutes' => 40,
            'check_out' => '16:20:00',
        ]);
        AttendanceRecord::factory()->create([
            'employee_id' => $onTime->id,
            'work_date' => self::MONDAY,
            'status' => 'present',
            'early_departure_minutes' => 0,
        ]);

        $response = $this->get(route('reports.export.earlyDepartures', [
            'start_date' => self::MONDAY,
            'end_date' => self::WEEK_END,
        ]));
        $this->assertCsvDownload($response);

        $body = $this->streamedBody($response);
        $this->assertStringContainsString('Eva Temprano', $body);
        $this->assertStringNotContainsString('Quedado Quique', $body);
        $this->assertStringContainsString('Minutos Temprano', $body);
    }

    public function test_faltas_export_renders_aggregated_falta_columns(): void
    {
        $this->actingAsAdmin();

        $employee = $this->weekdayEmployee([
            'full_name' => 'Fausto Falta',
            'employee_number' => 'EMP-FAL-1',
        ]);

        AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => self::MONDAY,
            'status' => 'absent',
            'check_in' => null,
        ]);

        $response = $this->get(route('reports.export.faltas', [
            'start_date' => self::MONDAY,
            'end_date' => self::WEEK_END,
        ]));
        $this->assertCsvDownload($response);

        $body = $this->streamedBody($response);
        $this->assertStringContainsString('Total Faltas', $body); // header column
        $this->assertStringContainsString('Fausto Falta', $body);
    }

    public function test_asistencia_export_renders_header_without_throwing(): void
    {
        $this->actingAsAdmin();

        // The day-name case bug (see DisciplineReportTest) means the body never
        // lists a perfect-attendance employee, but the export must still stream
        // valid CSV with its header row.
        $this->weekdayEmployee([
            'full_name' => 'Pura Perfecta',
            'employee_number' => 'EMP-AS-1',
        ]);

        $response = $this->get(route('reports.export.asistencia', [
            'start_date' => self::MONDAY,
            'end_date' => self::WEEK_END,
        ]));
        $this->assertCsvDownload($response);

        $body = $this->streamedBody($response);
        $this->assertStringContainsString('Dias Trabajados', $body);
        $this->assertStringContainsString('Horas Totales', $body);
    }

    /**
     * The CSV download must carry a filename in the Content-Disposition header
     * so the browser saves a sensibly-named file (exportCsv builds it from the
     * report name + date).
     */
    public function test_export_sets_named_attachment_filename(): void
    {
        $this->actingAsAdmin();

        $response = $this->get(route('reports.export.daily', ['date' => self::MONDAY]));
        $this->assertCsvDownload($response);

        $this->assertStringContainsString(
            "reporte_diario_".self::MONDAY.".csv",
            (string) $response->headers->get('content-disposition'),
        );
    }

    /**
     * FIXED: ReportExportController now scopes every CSV export through the
     * role-aware ScopesReportEmployees::scopedActiveEmployeeIds() instead of
     * Employee::active()->pluck('id'). A view_own employee downloading
     * reporte_diario_*.csv must receive ONLY their own attendance row — a
     * colleague's data must never appear (no cross-employee data leak).
     */
    public function test_employee_export_leaks_other_employees_data(): void
    {
        $user = $this->actingAsEmployee();
        $own = $this->attachEmployee($user, [
            'status' => 'active',
            'full_name' => 'Propio Empleado',
            'employee_number' => 'EMP-OWN-1',
        ]);
        $colleague = $this->weekdayEmployee([
            'full_name' => 'Colega Ajeno',
            'employee_number' => 'EMP-COLLEAGUE-1',
        ]);

        AttendanceRecord::factory()->create([
            'employee_id' => $own->id,
            'work_date' => self::MONDAY,
            'status' => 'present',
        ]);
        AttendanceRecord::factory()->create([
            'employee_id' => $colleague->id,
            'work_date' => self::MONDAY,
            'status' => 'present',
        ]);

        $response = $this->get(route('reports.export.daily', ['date' => self::MONDAY]));
        $this->assertCsvDownload($response);

        $body = $this->streamedBody($response);
        // The employee sees ONLY their own row; the colleague is scoped out.
        $this->assertStringContainsString('Propio Empleado', $body, 'Daily export should include the acting employee\'s own row.');
        $this->assertStringContainsString('EMP-OWN-1', $body);
        $this->assertStringNotContainsString('Colega Ajeno', $body, 'Daily export must NOT leak a colleague to a view_own employee (scoped query).');
        $this->assertStringNotContainsString('EMP-COLLEAGUE-1', $body);
    }
}
