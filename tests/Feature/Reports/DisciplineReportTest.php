<?php

namespace Tests\Feature\Reports;

use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Schedule;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FeatureTestCase;

/**
 * Front-to-back contract tests for AttendanceReportController.
 *
 * The four discipline reports (faltas / asistencia / retardos /
 * salidas-tempranas) share a closure middleware that requires ANY of
 * reports.view_all|view_team|view_own. Per the seeder:
 *   - admin      => view_all  (allowed)
 *   - employee   => view_own  (allowed through the gate)
 *   - rrhh       => NO reports.* permission => 403
 *   - supervisor => view_team (allowed through the gate, team-scoped data)
 *   - guest      => redirect to login
 *
 * Each action renders an Inertia 'Reports/<Name>' page. We assert the
 * component name and EVERY prop key the matching Vue page declares in
 * defineProps so a missing/renamed prop surfaces as a contract bug.
 *
 * The default Schedule factory ships working_days Mon-Fri with entry 08:00,
 * so report rows only materialise for weekday records. The week of
 * 2026-03-09 (Monday) is used throughout; 2026-03-09..12 are non-holiday
 * weekdays (2026-03-16 is the seeded Benito Juárez holiday, deliberately
 * avoided).
 */
class DisciplineReportTest extends FeatureTestCase
{
    private const MONDAY = '2026-03-09';

    private const TUESDAY = '2026-03-10';

    private const WEEK_END = '2026-03-15';

    /**
     * Range start used for queries: deliberately ONE day before the first
     * record (Sunday 2026-03-08) to dodge the start-date boundary bug in
     * AttendanceReportController (see test_faltas_drops_records_on_range_start_date,
     * skipped + reported). Records still sit on Mon-Fri working days.
     */
    private const RANGE_START = '2026-03-08';

    /**
     * Create an active employee on the default Mon-Fri schedule.
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
     * @return array<string, array{0:string,1:string}>
     */
    public static function disciplineRouteProvider(): array
    {
        return [
            'faltas' => ['reports.faltas', 'Reports/Faltas'],
            'asistencia' => ['reports.asistencia', 'Reports/Asistencia'],
            'retardos' => ['reports.retardos', 'Reports/Retardos'],
            'earlyDepartures' => ['reports.earlyDepartures', 'Reports/SalidasTempranas'],
        ];
    }

    // ------------------------------------------------------------------
    // RBAC trio (+ employee allowed-through) for all four reports
    // ------------------------------------------------------------------

    #[DataProvider('disciplineRouteProvider')]
    public function test_admin_can_view_discipline_report(string $routeName, string $component): void
    {
        $this->actingAsAdmin();

        $this->get(route($routeName))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component($component));
    }

    #[DataProvider('disciplineRouteProvider')]
    public function test_rrhh_is_forbidden_from_discipline_report(string $routeName): void
    {
        $this->actingAsRrhh();

        $this->get(route($routeName))->assertForbidden();
    }

    #[DataProvider('disciplineRouteProvider')]
    public function test_supervisor_can_view_discipline_report_team_scoped(string $routeName, string $component): void
    {
        $this->actingAsSupervisor();

        $this->get(route($routeName))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component($component));
    }

    #[DataProvider('disciplineRouteProvider')]
    public function test_guest_is_redirected_to_login(string $routeName): void
    {
        $this->get(route($routeName))->assertRedirect(route('login'));
    }

    #[DataProvider('disciplineRouteProvider')]
    public function test_employee_with_view_own_passes_the_gate(string $routeName, string $component): void
    {
        $user = $this->actingAsEmployee();
        $this->attachEmployee($user);

        $this->get(route($routeName))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component($component));
    }

    // ------------------------------------------------------------------
    // faltas: full prop contract + no-show / threshold classification
    // ------------------------------------------------------------------

    public function test_faltas_returns_all_props_and_classifies_no_show(): void
    {
        $this->actingAsAdmin();

        $dept = Department::factory()->create(['name' => 'Ventas']);
        $employee = $this->weekdayEmployee(['department_id' => $dept->id]);

        // True no-show: absent with NO check-in on a working weekday.
        AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => self::MONDAY,
            'status' => 'absent',
            'check_in' => null,
            'check_out' => null,
        ]);

        $this->get(route('reports.faltas', [
            'start_date' => self::RANGE_START,
            'end_date' => self::WEEK_END,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Faltas')
                ->where('startDate', self::RANGE_START)
                ->where('endDate', self::WEEK_END)
                ->has('byEmployee', 1)
                ->where('byEmployee.0.no_show_faltas', 1)
                ->where('byEmployee.0.threshold_faltas', 0)
                ->where('byEmployee.0.total_faltas', 1)
                ->has('byEmployee.0.no_show_dates', 1)
                ->has('summary', fn (Assert $s) => $s
                    ->where('total_faltas', 1)
                    ->where('employees_with_faltas', 1)
                    ->where('no_show_faltas', 1)
                    ->where('threshold_faltas', 0)
                    ->where('direct_faltas', 1)
                    ->where('retardo_faltas', 0))
                ->has('settings', fn (Assert $st) => $st
                    ->has('maxLate')
                    ->has('earlyThreshold')
                    ->has('earlyIsAbsence')
                    ->has('lateToAbsence')));
    }

    public function test_faltas_excludes_absences_on_holiday_dates(): void
    {
        $this->actingAsAdmin();

        $employee = $this->weekdayEmployee();

        // 2026-03-16 is the seeded Benito Juárez holiday (also a Monday).
        AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-16',
            'status' => 'absent',
            'check_in' => null,
            'check_out' => null,
        ]);

        $this->get(route('reports.faltas', [
            'start_date' => '2026-03-16',
            'end_date' => '2026-03-22',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Faltas')
                ->has('byEmployee', 0)
                ->where('summary.total_faltas', 0));
    }

    public function test_faltas_ignores_absences_on_non_working_days(): void
    {
        $this->actingAsAdmin();

        $employee = $this->weekdayEmployee();

        // 2026-03-13 is a Friday (working day) but we mark 2026-03-14 Saturday,
        // which is NOT a working day for a Mon-Fri schedule.
        AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-14',
            'status' => 'absent',
            'check_in' => null,
            'check_out' => null,
        ]);

        $this->get(route('reports.faltas', [
            'start_date' => self::RANGE_START,
            'end_date' => self::WEEK_END,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Faltas')
                ->has('byEmployee', 0)
                ->where('summary.total_faltas', 0));
    }

    // ------------------------------------------------------------------
    // asistencia: perfect-attendance detection + summary percentage
    // ------------------------------------------------------------------

    /**
     * FIX #4: AttendanceReportController::asistencia previously compared
     * Carbon::englishDayOfWeek (capitalised, e.g. "Monday") against
     * Schedule::working_days (stored lowercase, e.g. "monday"). The case
     * mismatch made in_array() always false, so expectedDays was 0 for every
     * employee and the report was ALWAYS empty. The comparison is now
     * case-insensitive (both sides lowercased), so a perfect week is reported.
     */
    public function test_asistencia_reports_perfect_attendance_for_perfect_week(): void
    {
        $this->actingAsAdmin();

        $dept = Department::factory()->create(['name' => 'Almacen']);
        $employee = $this->weekdayEmployee(['department_id' => $dept->id]);

        // A textbook perfect week: present, on-time, full hours, every weekday.
        foreach (['2026-03-09', '2026-03-10', '2026-03-11', '2026-03-12', '2026-03-13'] as $day) {
            AttendanceRecord::factory()->create([
                'employee_id' => $employee->id,
                'work_date' => $day,
                'status' => 'present',
                'worked_hours' => 8.00,
                'late_minutes' => 0,
                'early_departure_minutes' => 0,
            ]);
        }

        $this->get(route('reports.asistencia', [
            'start_date' => self::RANGE_START,
            'end_date' => self::WEEK_END,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Asistencia')
                ->has('byEmployee', 1)
                ->where('byEmployee.0.employee.id', $employee->id)
                ->where('byEmployee.0.days_worked', 5)
                ->where('byEmployee.0.total_hours', 40)
                ->where('summary.perfect_count', 1)
                ->where('summary.total_active', 1)
                ->where('summary.percentage', 100));
    }

    /**
     * Prop-contract test for asistencia: regardless of the perfect-attendance
     * counting bug above, the page must declare exactly the props its Vue
     * component consumes (startDate, endDate, byEmployee, summary with
     * perfect_count/total_active/percentage). total_active counts every active
     * employee even when none qualifies as perfect.
     */
    public function test_asistencia_returns_full_prop_contract(): void
    {
        $this->actingAsAdmin();

        $employee = $this->weekdayEmployee();
        AttendanceRecord::factory()->late()->create([
            'employee_id' => $employee->id,
            'work_date' => self::TUESDAY,
            'late_minutes' => 15,
        ]);

        $this->get(route('reports.asistencia', [
            'start_date' => self::RANGE_START,
            'end_date' => self::WEEK_END,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Asistencia')
                ->where('startDate', self::RANGE_START)
                ->where('endDate', self::WEEK_END)
                ->has('byEmployee')
                ->has('summary', fn (Assert $s) => $s
                    ->where('total_active', 1)
                    ->has('perfect_count')
                    ->has('percentage')));
    }

    // ------------------------------------------------------------------
    // retardos: full prop contract + aggregation + falta threshold
    // ------------------------------------------------------------------

    public function test_retardos_returns_all_props_and_aggregates(): void
    {
        $this->actingAsAdmin();

        $employee = $this->weekdayEmployee();

        AttendanceRecord::factory()->late()->create([
            'employee_id' => $employee->id,
            'work_date' => self::MONDAY,
            'late_minutes' => 20,
        ]);
        AttendanceRecord::factory()->late()->create([
            'employee_id' => $employee->id,
            'work_date' => self::TUESDAY,
            'late_minutes' => 10,
        ]);

        $this->get(route('reports.retardos', [
            'start_date' => self::RANGE_START,
            'end_date' => self::WEEK_END,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Retardos')
                ->where('startDate', self::RANGE_START)
                ->where('endDate', self::WEEK_END)
                ->has('lateToAbsenceCount')
                ->has('byEmployee', 1)
                ->where('byEmployee.0.late_count', 2)
                ->where('byEmployee.0.total_late_minutes', 30)
                ->where('byEmployee.0.generates_falta', false)
                ->has('byEmployee.0.dates', 2)
                ->has('summary', fn (Assert $s) => $s
                    ->where('total_retardos', 2)
                    ->where('employees_with_retardos', 1)
                    ->where('total_late_minutes', 30)
                    ->where('faltas_generated', 0)
                    ->etc()));
    }

    public function test_retardos_marks_generates_falta_at_threshold(): void
    {
        $this->actingAsAdmin();

        $employee = $this->weekdayEmployee();

        // Default late_to_absence_count is 6. Six lates on working weekdays.
        foreach (['2026-03-09', '2026-03-10', '2026-03-11', '2026-03-12', '2026-03-13', '2026-03-17'] as $day) {
            AttendanceRecord::factory()->late()->create([
                'employee_id' => $employee->id,
                'work_date' => $day,
                'late_minutes' => 12,
            ]);
        }

        $this->get(route('reports.retardos', [
            'start_date' => '2026-03-08',
            'end_date' => '2026-03-20',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Retardos')
                ->where('byEmployee.0.late_count', 6)
                ->where('byEmployee.0.generates_falta', true)
                ->where('summary.faltas_generated', 1));
    }

    // ------------------------------------------------------------------
    // earlyDepartures (Reports/SalidasTempranas): props + falta marking
    // ------------------------------------------------------------------

    public function test_early_departures_returns_all_props_and_marks_faltas(): void
    {
        $this->actingAsAdmin();

        $employee = $this->weekdayEmployee();

        // 45 min early >= default 30 min threshold => counts as a falta.
        AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => self::MONDAY,
            'status' => 'present',
            'early_departure_minutes' => 45,
            'check_out' => '16:15:00',
        ]);
        // 10 min early < threshold => no falta, still listed.
        AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => self::TUESDAY,
            'status' => 'present',
            'early_departure_minutes' => 10,
            'check_out' => '16:50:00',
        ]);

        $this->get(route('reports.earlyDepartures', [
            'start_date' => self::RANGE_START,
            'end_date' => self::WEEK_END,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/SalidasTempranas')
                ->where('startDate', self::RANGE_START)
                ->where('endDate', self::WEEK_END)
                ->has('byEmployee', 1)
                ->where('byEmployee.0.departure_count', 2)
                ->where('byEmployee.0.total_early_minutes', 55)
                ->where('byEmployee.0.faltas_count', 1)
                ->has('byEmployee.0.dates', 2)
                ->has('summary', fn (Assert $s) => $s
                    ->where('total_early_departures', 2)
                    ->where('employees_with_early_departures', 1)
                    ->where('total_early_minutes', 55)
                    ->where('faltas_generated', 1)
                    ->etc())
                ->has('settings', fn (Assert $st) => $st
                    ->has('earlyThreshold')
                    ->has('earlyIsAbsence')));
    }

    // ------------------------------------------------------------------
    // Scoping: view_all sees every active employee's data
    // ------------------------------------------------------------------

    public function test_faltas_scopes_to_all_active_employees_for_admin(): void
    {
        $this->actingAsAdmin();

        $a = $this->weekdayEmployee();
        $b = $this->weekdayEmployee();

        foreach ([$a, $b] as $emp) {
            AttendanceRecord::factory()->create([
                'employee_id' => $emp->id,
                'work_date' => self::MONDAY,
                'status' => 'absent',
                'check_in' => null,
                'check_out' => null,
            ]);
        }

        $this->get(route('reports.faltas', [
            'start_date' => self::RANGE_START,
            'end_date' => self::WEEK_END,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Faltas')
                ->has('byEmployee', 2)
                ->where('summary.total_faltas', 2));
    }

    /**
     * Scoping contract: an employee with reports.view_own must see ONLY their
     * own faltas in the report, never a colleague's. The controller scopes via
     * ScopesReportEmployees::scopedActiveEmployeeIds(), which for view_own
     * restricts to the acting user's own Employee id. Both employees have a
     * no-show on the same working day; only the acting employee's row may appear.
     */
    public function test_faltas_scopes_to_own_employee_only_for_view_own(): void
    {
        $user = $this->actingAsEmployee();
        $schedule = Schedule::factory()->create();
        $own = $this->attachEmployee($user, [
            'schedule_id' => $schedule->id,
            'status' => 'active',
        ]);
        $other = $this->weekdayEmployee();

        foreach ([$own, $other] as $emp) {
            AttendanceRecord::factory()->create([
                'employee_id' => $emp->id,
                'work_date' => self::MONDAY,
                'status' => 'absent',
                'check_in' => null,
                'check_out' => null,
            ]);
        }

        $this->get(route('reports.faltas', [
            'start_date' => self::RANGE_START,
            'end_date' => self::WEEK_END,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Faltas')
                ->has('byEmployee', 1)
                ->where('byEmployee.0.employee.id', $own->id)
                ->where('summary.total_faltas', 1));
    }

    /**
     * Empty-state contract: with no qualifying records the page still renders
     * with zeroed summary keys (not a 500 / missing-prop), so the Vue summary
     * cards bind to defined values.
     */
    public function test_faltas_renders_zeroed_summary_with_no_records(): void
    {
        $this->actingAsAdmin();
        $this->weekdayEmployee();

        $this->get(route('reports.faltas', [
            'start_date' => self::RANGE_START,
            'end_date' => self::WEEK_END,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Faltas')
                ->has('byEmployee', 0)
                ->where('summary.total_faltas', 0)
                ->where('summary.employees_with_faltas', 0)
                ->where('summary.direct_faltas', 0)
                ->where('summary.no_show_faltas', 0)
                ->where('summary.threshold_faltas', 0)
                ->where('summary.retardo_faltas', 0));
    }

    /**
     * retardos empty-state contract: lateToAbsenceCount prop is always present
     * (the Vue page declares it as a Number) and summary keys are zeroed.
     */
    public function test_retardos_renders_zeroed_summary_with_no_records(): void
    {
        $this->actingAsAdmin();
        $this->weekdayEmployee();

        $this->get(route('reports.retardos', [
            'start_date' => self::RANGE_START,
            'end_date' => self::WEEK_END,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Retardos')
                ->has('lateToAbsenceCount')
                ->has('byEmployee', 0)
                ->where('summary.total_retardos', 0)
                ->where('summary.employees_with_retardos', 0)
                ->where('summary.total_late_minutes', 0)
                ->where('summary.faltas_generated', 0));
    }

    /**
     * earlyDepartures empty-state contract: full summary + settings present
     * even when nobody left early.
     */
    public function test_early_departures_renders_zeroed_summary_with_no_records(): void
    {
        $this->actingAsAdmin();
        $this->weekdayEmployee();

        $this->get(route('reports.earlyDepartures', [
            'start_date' => self::RANGE_START,
            'end_date' => self::WEEK_END,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/SalidasTempranas')
                ->has('byEmployee', 0)
                ->where('summary.total_early_departures', 0)
                ->where('summary.employees_with_early_departures', 0)
                ->where('summary.total_early_minutes', 0)
                ->where('summary.faltas_generated', 0)
                ->has('settings.earlyThreshold')
                ->has('settings.earlyIsAbsence'));
    }

    /**
     * Retardos detail-row contract: each date entry exposes the keys the Vue
     * table renders (date, minutes, check_in, expected_entry).
     */
    public function test_retardos_date_rows_expose_expected_keys(): void
    {
        $this->actingAsAdmin();
        $employee = $this->weekdayEmployee();

        AttendanceRecord::factory()->late()->create([
            'employee_id' => $employee->id,
            'work_date' => self::MONDAY,
            'late_minutes' => 18,
            'check_in' => '08:18:00',
        ]);

        $this->get(route('reports.retardos', [
            'start_date' => self::RANGE_START,
            'end_date' => self::WEEK_END,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Retardos')
                ->has('byEmployee.0.dates.0', fn (Assert $d) => $d
                    ->where('minutes', 18)
                    ->has('date')
                    ->has('check_in')
                    ->has('expected_entry')));
    }

    // ------------------------------------------------------------------
    // BUG: start-date boundary off-by-one in AttendanceReportController
    // ------------------------------------------------------------------

    /**
     * FIX #12: AttendanceReportController::getDateRange returns Carbon instances
     * and every report query used ->whereBetween('work_date', [$startDate, $endDate])
     * with those Carbon objects. Carbon binds as a full datetime
     * ("2026-03-09 00:00:00"), so on SQLite a DATE-typed work_date equal to the
     * start date ("2026-03-09") sorted BEFORE the lower bound and was silently
     * excluded. The whereBetween bounds are now bound via ->toDateString(), so a
     * record exactly on the requested start date is included.
     */
    public function test_faltas_includes_records_on_range_start_date(): void
    {
        $this->actingAsAdmin();

        $employee = $this->weekdayEmployee();

        // A single no-show that sits exactly on the requested start date.
        AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => self::MONDAY, // == start_date below
            'status' => 'absent',
            'check_in' => null,
            'check_out' => null,
        ]);

        $this->get(route('reports.faltas', [
            'start_date' => self::MONDAY,
            'end_date' => self::WEEK_END,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Faltas')
                ->has('byEmployee', 1)
                ->where('byEmployee.0.employee.id', $employee->id)
                ->where('byEmployee.0.no_show_faltas', 1)
                ->where('byEmployee.0.total_faltas', 1)
                ->where('summary.total_faltas', 1));
    }
}
