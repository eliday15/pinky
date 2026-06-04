<?php

namespace Tests\Feature\Reports;

use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FeatureTestCase;

/**
 * Front-to-back contract tests for ReportController.
 *
 * The controller is gated by a closure middleware that requires ANY of
 * reports.view_all|view_team|view_own. Per the seeder:
 *   - admin     => view_all (full reports access)
 *   - employee  => view_own (scoped reports access)
 *   - supervisor=> view_team (team-scoped reports access)
 *   - rrhh      => NO reports.* permission => 403
 *   - guest     => redirect to login
 *
 * Each report action renders Inertia 'Reports/<Name>'. We assert the
 * component name and EVERY prop key the matching Vue page declares in
 * defineProps, so a missing/renamed prop is caught as a contract bug.
 */
class ReportControllerTest extends FeatureTestCase
{
    /**
     * All report routes that share the same RBAC gate, mapped to the
     * Inertia component they should render.
     *
     * @return array<string, array{0:string,1:string}>
     */
    public static function reportRouteProvider(): array
    {
        return [
            'index' => ['reports.index', 'Reports/Index'],
            'daily' => ['reports.daily', 'Reports/Daily'],
            'weekly' => ['reports.weekly', 'Reports/Weekly'],
            'monthly' => ['reports.monthly', 'Reports/Monthly'],
            'payroll' => ['reports.payroll', 'Reports/Payroll'],
            'overtime' => ['reports.overtime', 'Reports/Overtime'],
            'absences' => ['reports.absences', 'Reports/Absences'],
            'lateArrivals' => ['reports.lateArrivals', 'Reports/LateArrivals'],
            'vacationBalance' => ['reports.vacationBalance', 'Reports/VacationBalance'],
            'departmentComparison' => ['reports.departmentComparison', 'Reports/DepartmentComparison'],
            'incidents' => ['reports.incidents', 'Reports/Incidents'],
            'productivity' => ['reports.productivity', 'Reports/Productivity'],
            'payrollTrends' => ['reports.payrollTrends', 'Reports/PayrollTrends'],
        ];
    }

    // ------------------------------------------------------------------
    // RBAC: authorized role (admin) gets 200 + correct component on all
    // ------------------------------------------------------------------

    #[DataProvider('reportRouteProvider')]
    public function test_admin_can_view_every_report(string $routeName, string $component): void
    {
        $this->actingAsAdmin();

        $this->get(route($routeName))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component($component));
    }

    // ------------------------------------------------------------------
    // RBAC: unauthorized roles (rrhh, supervisor) get 403 on every route
    // ------------------------------------------------------------------

    #[DataProvider('reportRouteProvider')]
    public function test_rrhh_is_forbidden_from_every_report(string $routeName): void
    {
        $this->actingAsRrhh();

        $this->get(route($routeName))->assertForbidden();
    }

    #[DataProvider('reportRouteProvider')]
    public function test_supervisor_can_view_every_report_team_scoped(string $routeName, string $component): void
    {
        // The supervisor role now holds reports.view_team, so it PASSES the
        // report gate and receives a 200 (team-scoped) on every route. A bare
        // supervisorUser() has no linked Employee, so report DATA is empty, but
        // the HTTP status is still 200 and the expected component renders.
        $this->actingAsSupervisor();

        $this->get(route($routeName))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component($component));
    }

    // ------------------------------------------------------------------
    // RBAC: guest is redirected to login on every route
    // ------------------------------------------------------------------

    #[DataProvider('reportRouteProvider')]
    public function test_guest_is_redirected_to_login(string $routeName): void
    {
        $this->get(route($routeName))->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------
    // RBAC: employee (reports.view_own) IS allowed through the gate
    // ------------------------------------------------------------------

    public function test_employee_can_view_index_with_own_scope(): void
    {
        $user = $this->actingAsEmployee();
        $this->attachEmployee($user);

        $this->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Index')
                ->where('scope', 'own'));
    }

    public function test_employee_can_view_a_scoped_report(): void
    {
        $user = $this->actingAsEmployee();
        $this->attachEmployee($user);

        // A view_own employee still passes the gate for data reports.
        $this->get(route('reports.daily'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Reports/Daily'));
    }

    // ------------------------------------------------------------------
    // index: scope prop reflects the acting user's permission level
    // ------------------------------------------------------------------

    public function test_index_reports_all_scope_for_admin(): void
    {
        $this->actingAsAdmin();

        $this->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Index')
                ->where('scope', 'all'));
    }

    // ------------------------------------------------------------------
    // daily: full prop contract + value + grouping
    // ------------------------------------------------------------------

    public function test_daily_returns_all_props_and_summary_counts(): void
    {
        $this->actingAsAdmin();

        $dept = Department::factory()->create(['name' => 'Ventas']);
        $employee = Employee::factory()->create(['department_id' => $dept->id]);

        $date = '2026-03-10';
        AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => $date,
            'status' => 'present',
            'worked_hours' => 8.00,
        ]);

        $this->get(route('reports.daily', ['date' => $date]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Daily')
                ->where('date', $date)
                ->has('records', 1)
                ->has('summary', fn (Assert $s) => $s
                    ->where('total', 1)
                    ->where('present', 1)
                    ->where('late', 0)
                    ->where('absent', 0)
                    ->where('partial', 0)
                    ->where('vacation', 0)
                    ->where('sick_leave', 0))
                ->has('byDepartment.Ventas'));
    }

    public function test_daily_defaults_to_today_when_no_date(): void
    {
        $this->actingAsAdmin();

        $this->get(route('reports.daily'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Daily')
                ->where('date', Carbon::today()->toDateString())
                ->has('records')
                ->has('summary')
                ->has('byDepartment'));
    }

    // ------------------------------------------------------------------
    // weekly: full prop contract + per-employee aggregation
    // ------------------------------------------------------------------

    public function test_weekly_returns_props_and_aggregates_by_employee(): void
    {
        $this->actingAsAdmin();

        $employee = Employee::factory()->create();

        // Two days within the same ISO week: Monday (first day of week) + Tuesday.
        $monday = Carbon::parse('2026-03-09'); // Monday == start of week
        AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => $monday->toDateString(),
            'status' => 'present',
            'worked_hours' => 8.00,
        ]);
        AttendanceRecord::factory()->late()->create([
            'employee_id' => $employee->id,
            'work_date' => $monday->copy()->addDay()->toDateString(),
            'worked_hours' => 7.00,
        ]);

        $response = $this->get(route('reports.weekly', ['start_date' => $monday->toDateString()]));
        $response->assertOk();

        // FIXED: ReportController::weekly() now binds ->toDateString() bounds to
        // whereBetween('work_date', ...), so the FIRST day of the week (the
        // Monday present record) is included alongside the Tuesday late record —
        // days_worked=2, days_late=1.
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Reports/Weekly')
            ->where('startDate', Carbon::parse('2026-03-09')->startOfWeek()->toDateString())
            ->has('endDate')
            ->has('byEmployee', 1)
            ->has('byEmployee.0.employee')
            ->where('byEmployee.0.days_worked', 2)
            ->where('byEmployee.0.days_late', 1)
            ->has('summary', fn (Assert $s) => $s
                ->where('total_employees', 1)
                ->where('total_late', 1)
                ->etc()));
    }

    // ------------------------------------------------------------------
    // monthly: full prop contract
    // ------------------------------------------------------------------

    public function test_monthly_returns_all_props(): void
    {
        $this->actingAsAdmin();

        $dept = Department::factory()->create(['name' => 'Almacen']);
        $employee = Employee::factory()->create(['department_id' => $dept->id]);

        AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-15',
            'status' => 'present',
            'worked_hours' => 8.00,
        ]);

        $this->get(route('reports.monthly', ['month' => '2026-03']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Monthly')
                ->where('month', '2026-03')
                ->has('monthName')
                ->has('byEmployee', 1)
                ->has('byDepartment.Almacen')
                ->has('summary', fn (Assert $s) => $s
                    ->where('total_employees', 1)
                    ->etc()));
    }

    // ------------------------------------------------------------------
    // payroll: props with and without a selected period.
    // NOTE: the report only lists CLOSED periods (approved/paid) — a draft
    // or calculating period is not valid payroll and must not be reported
    // (same criterion as payrollTrends). Hence the ->approved() states.
    // ------------------------------------------------------------------

    public function test_payroll_without_period_returns_empty_props(): void
    {
        $this->actingAsAdmin();

        PayrollPeriod::factory()->weekly()->approved()->create();

        $this->get(route('reports.payroll'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Payroll')
                ->has('periods', 1)
                ->where('selectedPeriod', null)
                ->has('entries')
                ->where('summary', null));
    }

    public function test_payroll_with_period_returns_entries_and_summary(): void
    {
        $admin = $this->actingAsAdmin();

        $period = PayrollPeriod::factory()->weekly()->approved()->create(['created_by' => $admin->id]);
        $employee = Employee::factory()->create();
        PayrollEntry::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
        ]);

        $this->get(route('reports.payroll', ['period' => $period->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Payroll')
                ->has('periods')
                ->has('selectedPeriod')
                ->where('selectedPeriod.id', $period->id)
                ->has('entries', 1)
                ->has('summary', fn (Assert $s) => $s
                    ->where('total_employees', 1)
                    ->etc()));
    }

    // ------------------------------------------------------------------
    // payroll: a view_own employee sees ONLY their own payroll entry.
    // FIXED: ReportController::payroll() now scopes $entries via
    // scopedActiveEmployeeIds() like the sibling actions, so a colleague's
    // payroll row never leaks into a view_own employee's report.
    // ------------------------------------------------------------------

    public function test_payroll_scopes_entries_to_own_employee_for_view_own(): void
    {
        $user = $this->actingAsEmployee();
        $self = $this->attachEmployee($user);
        $other = Employee::factory()->create();

        $period = PayrollPeriod::factory()->weekly()->approved()->create();
        PayrollEntry::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $self->id,
        ]);
        // A colleague's payroll entry in the same period MUST NOT leak.
        PayrollEntry::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $other->id,
        ]);

        $this->get(route('reports.payroll', ['period' => $period->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Payroll')
                ->has('entries', 1)
                ->where('entries.0.employee_id', $self->id)
                ->has('summary', fn (Assert $s) => $s
                    ->where('total_employees', 1)
                    ->etc()));
    }

    // ------------------------------------------------------------------
    // overtime: props + only records with overtime are included
    // ------------------------------------------------------------------

    public function test_overtime_returns_props_and_filters_to_overtime_records(): void
    {
        $this->actingAsAdmin();

        $employee = Employee::factory()->create(['hourly_rate' => 100.00]);

        AttendanceRecord::factory()->withOvertime()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-12',
        ]);
        // A record with no overtime in the same window must be excluded.
        AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-13',
            'overtime_hours' => 0.00,
        ]);

        $this->get(route('reports.overtime', [
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Overtime')
                ->where('startDate', '2026-03-01')
                ->where('endDate', '2026-03-31')
                ->has('byEmployee', 1)
                ->where('byEmployee.0.days_with_overtime', 1)
                ->has('summary', fn (Assert $s) => $s
                    ->where('total_employees', 1)
                    ->where('total_days_with_overtime', 1)
                    ->etc()));
    }

    // ------------------------------------------------------------------
    // absences: props (default date range, no data) render cleanly
    // ------------------------------------------------------------------

    public function test_absences_returns_all_props(): void
    {
        $this->actingAsAdmin();

        $this->get(route('reports.absences'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Absences')
                ->has('startDate')
                ->has('endDate')
                ->has('byEmployee')
                ->has('summary', fn (Assert $s) => $s
                    ->where('total_absence_records', 0)
                    ->where('employees_with_absences', 0)
                    ->etc()));
    }

    // ------------------------------------------------------------------
    // lateArrivals: props + aggregation
    // ------------------------------------------------------------------

    public function test_late_arrivals_returns_props_and_aggregates(): void
    {
        $this->actingAsAdmin();

        $employee = Employee::factory()->create();
        AttendanceRecord::factory()->late()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-05',
            'late_minutes' => 20,
        ]);

        $this->get(route('reports.lateArrivals', [
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/LateArrivals')
                ->where('startDate', '2026-03-01')
                ->where('endDate', '2026-03-31')
                ->has('byEmployee', 1)
                ->where('byEmployee.0.late_count', 1)
                ->has('summary', fn (Assert $s) => $s
                    ->where('total_late_records', 1)
                    ->where('employees_with_lates', 1)
                    ->where('critical_employees', 0)
                    ->etc()));
    }

    // ------------------------------------------------------------------
    // vacationBalance: props + per-employee balance math + filter
    // ------------------------------------------------------------------

    public function test_vacation_balance_returns_props_and_computes_balance(): void
    {
        $this->actingAsAdmin();

        $dept = Department::factory()->create();
        Employee::factory()->create([
            'department_id' => $dept->id,
            'vacation_days_entitled' => 12,
            'vacation_days_used' => 3,
        ]);

        $this->get(route('reports.vacationBalance'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/VacationBalance')
                ->has('employees', 1)
                ->where('employees.0.entitled', 12)
                ->where('employees.0.used', 3)
                ->where('employees.0.available', 9)
                ->has('departments')
                ->where('selectedDepartment', null)
                ->has('summary', fn (Assert $s) => $s
                    ->where('total_employees', 1)
                    ->where('total_available', 9)
                    ->etc()));
    }

    public function test_vacation_balance_respects_department_filter(): void
    {
        $this->actingAsAdmin();

        $deptA = Department::factory()->create();
        $deptB = Department::factory()->create();
        Employee::factory()->create(['department_id' => $deptA->id]);
        Employee::factory()->create(['department_id' => $deptB->id]);

        $this->get(route('reports.vacationBalance', ['department' => $deptA->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/VacationBalance')
                ->has('employees', 1)
                ->where('selectedDepartment', (string) $deptA->id));
    }

    // ------------------------------------------------------------------
    // departmentComparison: props + per-department rollup
    // ------------------------------------------------------------------

    public function test_department_comparison_returns_props(): void
    {
        $this->actingAsAdmin();

        $dept = Department::factory()->create(['name' => 'Soporte']);
        $employee = Employee::factory()->create(['department_id' => $dept->id]);
        AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-04',
            'status' => 'present',
            'worked_hours' => 8.00,
        ]);

        $this->get(route('reports.departmentComparison', [
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/DepartmentComparison')
                ->where('startDate', '2026-03-01')
                ->where('endDate', '2026-03-31')
                ->has('departments', 1)
                ->where('departments.0.name', 'Soporte')
                ->where('departments.0.employee_count', 1)
                ->has('summary', fn (Assert $s) => $s
                    ->where('total_departments', 1)
                    ->where('total_employees', 1)
                    ->etc()));
    }

    // ------------------------------------------------------------------
    // incidents: full prop contract + grouping
    // ------------------------------------------------------------------

    public function test_incidents_returns_all_props_and_groupings(): void
    {
        $this->actingAsAdmin();

        $dept = Department::factory()->create(['name' => 'RH']);
        $employee = Employee::factory()->create(['department_id' => $dept->id]);
        $type = IncidentType::factory()->vacation()->create();

        Incident::factory()->approved()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'start_date' => '2026-03-10',
            'end_date' => '2026-03-12',
            'days_count' => 3,
        ]);

        $this->get(route('reports.incidents', [
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Incidents')
                ->where('startDate', '2026-03-01')
                ->where('endDate', '2026-03-31')
                ->has('incidents', 1)
                ->has('byType', 1)
                ->has('byDepartment', 1)
                ->has('byStatus', fn (Assert $bs) => $bs
                    ->where('pending', 0)
                    ->where('approved', 1)
                    ->where('rejected', 0))
                ->has('summary', fn (Assert $s) => $s
                    ->where('total_incidents', 1)
                    ->where('total_days', 3)
                    ->where('approved_count', 1)
                    ->etc()));
    }

    // ------------------------------------------------------------------
    // productivity: full prop contract + scoring
    // ------------------------------------------------------------------

    public function test_productivity_returns_props_and_scores(): void
    {
        $this->actingAsAdmin();

        $employee = Employee::factory()->create();
        AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-06',
            'status' => 'present',
            'worked_hours' => 8.00,
        ]);

        $this->get(route('reports.productivity', [
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Productivity')
                ->where('startDate', '2026-03-01')
                ->where('endDate', '2026-03-31')
                ->has('byEmployee', 1)
                ->where('byEmployee.0.worked_days', 1)
                ->where('byEmployee.0.punctuality_score', 100)
                ->has('summary', fn (Assert $s) => $s
                    ->where('total_employees', 1)
                    ->etc()));
    }

    // ------------------------------------------------------------------
    // payrollTrends: props + only approved/paid periods are trended
    // ------------------------------------------------------------------

    public function test_payroll_trends_returns_props_and_only_finalized_periods(): void
    {
        $admin = $this->actingAsAdmin();

        $approved = PayrollPeriod::factory()->approved()->create(['created_by' => $admin->id]);
        PayrollEntry::factory()->create([
            'payroll_period_id' => $approved->id,
            'employee_id' => Employee::factory()->create()->id,
        ]);

        // A draft period must NOT appear in the trend (only approved|paid).
        PayrollPeriod::factory()->draft()->create(['created_by' => $admin->id]);

        $this->get(route('reports.payrollTrends'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/PayrollTrends')
                ->has('trendData', 1)
                ->has('summary', fn (Assert $s) => $s
                    ->where('periods_count', 1)
                    ->etc()));
    }

    // ==================================================================
    // STRENGTHENING ADDITIONS (adversarial review pass)
    // ==================================================================

    // ------------------------------------------------------------------
    // index: full prop contract (the page declares exactly one prop:
    // `scope`). Assert it is present and constrained to the enum values.
    // ------------------------------------------------------------------

    public function test_index_declares_only_the_scope_prop(): void
    {
        $this->actingAsAdmin();

        $this->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Index')
                ->has('scope'));
    }

    // ------------------------------------------------------------------
    // RBAC data isolation: a view_own employee must ONLY see their own
    // attendance data in a data report — never a co-worker's. This guards
    // against a scoping leak (ScopesReportEmployees::scopedActiveEmployeeIds).
    // ------------------------------------------------------------------

    public function test_daily_scopes_data_to_own_employee_for_view_own(): void
    {
        $user = $this->actingAsEmployee();
        $self = $this->attachEmployee($user);
        $other = Employee::factory()->create();

        $date = '2026-03-17';
        AttendanceRecord::factory()->create([
            'employee_id' => $self->id,
            'work_date' => $date,
            'status' => 'present',
            'worked_hours' => 8.00,
        ]);
        // A co-worker's record on the same day MUST NOT leak to this employee.
        AttendanceRecord::factory()->create([
            'employee_id' => $other->id,
            'work_date' => $date,
            'status' => 'present',
            'worked_hours' => 8.00,
        ]);

        $this->get(route('reports.daily', ['date' => $date]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Daily')
                ->has('records', 1)
                ->where('records.0.employee_id', $self->id)
                ->where('summary.total', 1));
    }

    // ------------------------------------------------------------------
    // RBAC edge: an employee WITHOUT a linked Employee record gets an
    // empty (but valid) report — no leak, no 500.
    // ------------------------------------------------------------------

    public function test_daily_returns_empty_for_employee_without_linked_record(): void
    {
        $this->actingAsEmployee(); // no attachEmployee() call

        // Seed someone else's data that must NOT appear.
        $other = Employee::factory()->create();
        AttendanceRecord::factory()->create([
            'employee_id' => $other->id,
            'work_date' => '2026-03-18',
            'status' => 'present',
            'worked_hours' => 8.00,
        ]);

        $this->get(route('reports.daily', ['date' => '2026-03-18']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Daily')
                ->has('records', 0)
                ->where('summary.total', 0));
    }

    public function test_vacation_balance_returns_empty_for_employee_without_linked_record(): void
    {
        $this->actingAsEmployee();

        Employee::factory()->create(['vacation_days_entitled' => 12, 'vacation_days_used' => 0]);

        $this->get(route('reports.vacationBalance'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/VacationBalance')
                ->has('employees', 0)
                ->where('summary.total_employees', 0));
    }

    // ------------------------------------------------------------------
    // daily: byDepartment rollup math (the page consumes nested keys).
    // ------------------------------------------------------------------

    public function test_daily_by_department_rollup_math(): void
    {
        $this->actingAsAdmin();

        $dept = Department::factory()->create(['name' => 'Caja']);
        $e1 = Employee::factory()->create(['department_id' => $dept->id]);
        $e2 = Employee::factory()->create(['department_id' => $dept->id]);

        $date = '2026-03-19';
        AttendanceRecord::factory()->create([
            'employee_id' => $e1->id, 'work_date' => $date,
            'status' => 'present', 'worked_hours' => 8.00, 'overtime_hours' => 1.00,
        ]);
        AttendanceRecord::factory()->late()->create([
            'employee_id' => $e2->id, 'work_date' => $date,
            'worked_hours' => 7.00, 'overtime_hours' => 0.00,
        ]);

        $this->get(route('reports.daily', ['date' => $date]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Daily')
                ->has('records', 2)
                ->has('byDepartment.Caja', fn (Assert $d) => $d
                    ->where('total', 2)
                    ->where('present', 2) // present + late counted as present
                    ->where('absent', 0)
                    ->etc())
                ->where('summary.late', 1)
                ->where('summary.present', 1));
    }

    // ------------------------------------------------------------------
    // monthly: defaults to current month when no `month` param given.
    // ------------------------------------------------------------------

    public function test_monthly_defaults_to_current_month(): void
    {
        $this->actingAsAdmin();

        $this->get(route('reports.monthly'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Monthly')
                ->where('month', Carbon::now()->format('Y-m'))
                ->has('monthName')
                ->has('byEmployee')
                ->has('byDepartment')
                ->has('summary'));
    }

    // ------------------------------------------------------------------
    // payroll: a non-existent period id => selectedPeriod null, summary
    // null, entries empty (controller does PayrollPeriod::find()).
    // ------------------------------------------------------------------

    public function test_payroll_with_unknown_period_returns_null_selection(): void
    {
        $this->actingAsAdmin();

        $this->get(route('reports.payroll', ['period' => 999999]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Payroll')
                ->where('selectedPeriod', null)
                ->where('summary', null)
                ->has('entries', 0));
    }

    // ------------------------------------------------------------------
    // payroll: only the most-recent 12 periods are listed.
    // ------------------------------------------------------------------

    public function test_payroll_lists_at_most_twelve_periods(): void
    {
        $admin = $this->actingAsAdmin();

        PayrollPeriod::factory()->count(14)->weekly()->approved()->create(['created_by' => $admin->id]);

        $this->get(route('reports.payroll'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Payroll')
                ->has('periods', 12));
    }

    // ------------------------------------------------------------------
    // overtime: el costo estimado se calcula sobre las horas AUTORIZADAS
    // (las que paga nómina), nunca sobre las crudas detectadas. El reporte
    // muestra ambas columnas para conciliar (Fase C, DECISIONES derivadas).
    // ------------------------------------------------------------------

    public function test_overtime_computes_estimated_cost_from_authorized_hours(): void
    {
        $this->actingAsAdmin();

        $employee = Employee::factory()->create(['hourly_rate' => 100.00, 'overtime_rate' => 1.5]);
        AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-12',
            'status' => 'present',
            'worked_hours' => 8.00,
            'overtime_hours' => 2.50,
            'overtime_authorized_hours' => 1.50,
        ]);

        $this->get(route('reports.overtime', [
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Overtime')
                ->has('byEmployee', 1)
                ->where('byEmployee.0.total_overtime', 2.5)
                ->where('byEmployee.0.total_authorized', 1.5)
                // 1.5h autorizadas * 100 * 1.5 = 225 — lo que pagará nómina
                ->where('byEmployee.0.estimated_cost', 225)
                ->where('summary.total_overtime_hours', 2.5)
                ->where('summary.total_authorized_hours', 1.5));
    }

    public function test_overtime_unauthorized_hours_cost_nothing(): void
    {
        $this->actingAsAdmin();

        $employee = Employee::factory()->create(['hourly_rate' => 100.00]);
        AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-12',
            'status' => 'present',
            'worked_hours' => 8.00,
            'overtime_hours' => 2.00,
            'overtime_authorized_hours' => 0,
        ]);

        $this->get(route('reports.overtime', [
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Overtime')
                ->where('byEmployee.0.total_overtime', 2)
                ->where('byEmployee.0.total_authorized', 0)
                ->where('byEmployee.0.estimated_cost', 0, 'sin autorización no hay costo: nómina no las paga'));
    }

    // ------------------------------------------------------------------
    // absences: a true absence inside the window is reported, while an
    // 'absent' row on a non-scheduled day is dropped by the controller's
    // isEffectiveWorkingDay() filter. Uses MID-RANGE dates to avoid the
    // first-day whereBetween boundary bug confounding the result.
    // ------------------------------------------------------------------

    public function test_absences_reports_scheduled_day_absences(): void
    {
        $this->actingAsAdmin();

        $employee = Employee::factory()->create();
        // 2026-03-18 is a Wednesday (a standard working day for the
        // default Mon-Fri schedule the factory builds).
        AttendanceRecord::factory()->absent()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-18',
        ]);

        $this->get(route('reports.absences', [
            'start_date' => '2026-03-02',
            'end_date' => '2026-03-28',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Absences')
                ->where('startDate', '2026-03-02')
                ->where('endDate', '2026-03-28')
                ->has('byEmployee', 1)
                ->where('byEmployee.0.absence_days', 1)
                ->has('summary', fn (Assert $s) => $s
                    ->where('total_absence_records', 1)
                    ->where('employees_with_absences', 1)
                    ->etc()));
    }

    // ------------------------------------------------------------------
    // lateArrivals: critical_employees counts those with 6+ tardies.
    // ------------------------------------------------------------------

    public function test_late_arrivals_flags_critical_employees(): void
    {
        $this->actingAsAdmin();

        $employee = Employee::factory()->create();
        // 6 late records (mid-range dates) => critical.
        foreach (['2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05', '2026-03-06', '2026-03-09'] as $i => $d) {
            AttendanceRecord::factory()->late()->create([
                'employee_id' => $employee->id,
                'work_date' => $d,
                'late_minutes' => 10 + $i,
            ]);
        }

        $this->get(route('reports.lateArrivals', [
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/LateArrivals')
                ->has('byEmployee', 1)
                ->where('byEmployee.0.late_count', 6)
                ->where('summary.critical_employees', 1)
                ->where('summary.employees_with_lates', 1));
    }

    // ------------------------------------------------------------------
    // vacationBalance: inactive employees are excluded (active() scope),
    // and the summary aggregates only active rows.
    // ------------------------------------------------------------------

    public function test_vacation_balance_excludes_inactive_employees(): void
    {
        $this->actingAsAdmin();

        Employee::factory()->create([
            'vacation_days_entitled' => 12,
            'vacation_days_used' => 4,
        ]);
        Employee::factory()->inactive()->create([
            'vacation_days_entitled' => 20,
            'vacation_days_used' => 0,
        ]);

        $this->get(route('reports.vacationBalance'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/VacationBalance')
                ->has('employees', 1)
                ->where('employees.0.available', 8)
                ->where('summary.total_employees', 1)
                ->where('summary.total_available', 8));
    }

    // ------------------------------------------------------------------
    // departmentComparison: attendance_rate / punctuality_rate math.
    // Uses mid-range dates to avoid the first-day boundary bug.
    // ------------------------------------------------------------------

    public function test_department_comparison_rate_math(): void
    {
        $this->actingAsAdmin();

        $dept = Department::factory()->create(['name' => 'Logistica']);
        $employee = Employee::factory()->create(['department_id' => $dept->id]);

        // 3 present + 1 late + 0 absent => 4 worked / 4 total = 100% attendance,
        // (4-1)/4 = 75% punctuality.
        foreach (['2026-03-02', '2026-03-03', '2026-03-04'] as $d) {
            AttendanceRecord::factory()->create([
                'employee_id' => $employee->id, 'work_date' => $d,
                'status' => 'present', 'worked_hours' => 8.00,
            ]);
        }
        AttendanceRecord::factory()->late()->create([
            'employee_id' => $employee->id, 'work_date' => '2026-03-05',
            'worked_hours' => 7.00,
        ]);

        $this->get(route('reports.departmentComparison', [
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/DepartmentComparison')
                ->has('departments', 1)
                ->where('departments.0.name', 'Logistica')
                ->where('departments.0.employee_count', 1)
                ->where('departments.0.worked_days', 4)
                ->where('departments.0.late_days', 1)
                // round() of a whole number JSON-serializes to an int (100 not 100.0).
                ->where('departments.0.attendance_rate', 100)
                ->where('departments.0.punctuality_rate', 75));
    }

    // ------------------------------------------------------------------
    // incidents: byType + byDepartment array shapes and byStatus rollup.
    // Uses mid-range start_date to avoid the boundary bug.
    // ------------------------------------------------------------------

    public function test_incidents_by_type_and_department_shapes(): void
    {
        $this->actingAsAdmin();

        $dept = Department::factory()->create(['name' => 'Compras']);
        $employee = Employee::factory()->create(['department_id' => $dept->id]);
        $vacationType = IncidentType::factory()->vacation()->create(['name' => 'Vacaciones']);
        $sickType = IncidentType::factory()->sickLeave()->create(['name' => 'Incapacidad']);

        Incident::factory()->approved()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $vacationType->id,
            'start_date' => '2026-03-10',
            'end_date' => '2026-03-12',
            'days_count' => 3,
        ]);
        Incident::factory()->create([ // pending sick leave
            'employee_id' => $employee->id,
            'incident_type_id' => $sickType->id,
            'status' => 'pending',
            'start_date' => '2026-03-15',
            'end_date' => '2026-03-16',
            'days_count' => 2,
        ]);

        $this->get(route('reports.incidents', [
            'start_date' => '2026-03-02',
            'end_date' => '2026-03-28',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Incidents')
                ->has('incidents', 2)
                ->has('byType', 2)
                ->has('byType.0', fn (Assert $t) => $t
                    ->has('type')
                    ->has('count')
                    ->has('total_days')
                    ->has('approved')
                    ->has('pending')
                    ->has('rejected'))
                ->has('byDepartment', 1)
                ->where('byDepartment.0.department', 'Compras')
                ->where('byDepartment.0.count', 2)
                ->where('byDepartment.0.total_days', 5)
                ->has('byStatus', fn (Assert $bs) => $bs
                    ->where('pending', 1)
                    ->where('approved', 1)
                    ->where('rejected', 0))
                ->has('summary', fn (Assert $s) => $s
                    ->where('total_incidents', 2)
                    ->where('total_days', 5)
                    ->where('pending_count', 1)
                    ->where('approved_count', 1)
                    ->where('rejected_count', 0)));
    }

    // ------------------------------------------------------------------
    // productivity: punctuality_score penalties (-5/late, -10/absent).
    // Uses mid-range dates to avoid the boundary bug.
    // ------------------------------------------------------------------

    public function test_productivity_punctuality_score_penalties(): void
    {
        $this->actingAsAdmin();

        $employee = Employee::factory()->create();
        // 1 present, 1 late (-5), 1 absent (-10) => score 85.
        AttendanceRecord::factory()->create([
            'employee_id' => $employee->id, 'work_date' => '2026-03-03',
            'status' => 'present', 'worked_hours' => 8.00,
        ]);
        AttendanceRecord::factory()->late()->create([
            'employee_id' => $employee->id, 'work_date' => '2026-03-04',
            'worked_hours' => 7.00,
        ]);
        AttendanceRecord::factory()->absent()->create([
            'employee_id' => $employee->id, 'work_date' => '2026-03-05',
        ]);

        $this->get(route('reports.productivity', [
            'start_date' => '2026-03-02',
            'end_date' => '2026-03-28',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Productivity')
                ->has('byEmployee', 1)
                ->where('byEmployee.0.late_count', 1)
                ->where('byEmployee.0.absent_count', 1)
                ->where('byEmployee.0.punctuality_score', 85)
                ->where('summary.total_employees', 1));
    }

    // ------------------------------------------------------------------
    // payrollTrends: a draft period that was later flagged paid IS
    // included; verifies the status filter includes both approved & paid.
    // ------------------------------------------------------------------

    public function test_payroll_trends_includes_paid_periods(): void
    {
        $admin = $this->actingAsAdmin();

        $paid = PayrollPeriod::factory()->paid()->create(['created_by' => $admin->id]);
        PayrollEntry::factory()->create([
            'payroll_period_id' => $paid->id,
            'employee_id' => Employee::factory()->create()->id,
        ]);
        $approved = PayrollPeriod::factory()->approved()->create(['created_by' => $admin->id]);
        PayrollEntry::factory()->create([
            'payroll_period_id' => $approved->id,
            'employee_id' => Employee::factory()->create()->id,
        ]);
        // review/calculating/draft are excluded.
        PayrollPeriod::factory()->review()->create(['created_by' => $admin->id]);
        PayrollPeriod::factory()->draft()->create(['created_by' => $admin->id]);

        $this->get(route('reports.payrollTrends'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/PayrollTrends')
                ->has('trendData', 2)
                ->where('summary.periods_count', 2));
    }

    // ------------------------------------------------------------------
    // payrollTrends: empty state renders cleanly with zeroed summary.
    // ------------------------------------------------------------------

    public function test_payroll_trends_empty_state(): void
    {
        $this->actingAsAdmin();

        $this->get(route('reports.payrollTrends'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/PayrollTrends')
                ->has('trendData', 0)
                ->has('summary', fn (Assert $s) => $s
                    ->where('periods_count', 0)
                    ->where('max_total_net', 0)
                    ->where('min_total_net', 0)
                    ->where('total_paid', 0)
                    ->etc()));
    }
}
