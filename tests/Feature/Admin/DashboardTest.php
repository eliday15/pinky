<?php

namespace Tests\Feature\Admin;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\Employee;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\PayrollPeriod;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\FeatureTestCase;

/**
 * Feature tests for DashboardController.
 *
 * Covers the role-specific dashboards: admin/rrhh global view, supervisor
 * redirect to incidents, and the employee personal view (with and without a
 * linked Employee). Asserts the Inertia prop contract consumed by Dashboard.vue.
 */
class DashboardTest extends FeatureTestCase
{
    // ---------------------------------------------------------------------
    // admin / rrhh
    // ---------------------------------------------------------------------

    public function test_admin_dashboard_renders_with_expected_props(): void
    {
        $this->actingAsAdmin();

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('userRole', 'admin')
                ->has('stats.present')
                ->has('stats.late')
                ->has('stats.absent')
                ->has('stats.total')
                ->has('stats.pendingIncidents')
                ->has('stats.pendingAuthorizations')
                ->has('recentAttendance')
                ->has('pendingApprovals')
                ->has('can.sync')
                ->has('can.createEmployee')
                ->has('can.calculatePayroll'));
    }

    public function test_rrhh_dashboard_renders_as_admin_view(): void
    {
        $this->actingAsRrhh();

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('userRole', 'admin'));
    }

    public function test_admin_dashboard_counts_pending_approvals(): void
    {
        $this->actingAsAdmin();

        $employee = Employee::factory()->create();
        $incidentType = IncidentType::factory()->create();
        Incident::factory()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $incidentType->id,
            'status' => 'pending',
        ]);
        Authorization::factory()->create([
            'employee_id' => $employee->id,
            'status' => Authorization::STATUS_PENDING,
            'requested_by' => $this->adminUser()->id,
        ]);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.pendingIncidents', 1)
                ->where('stats.pendingAuthorizations', 1)
                ->has('pendingApprovals', 2));
    }

    public function test_admin_dashboard_aggregates_today_attendance_stats(): void
    {
        $this->actingAsAdmin();
        $today = now()->toDateString();

        $present = Employee::factory()->create();
        $late = Employee::factory()->create();
        $absent = Employee::factory()->create();

        AttendanceRecord::factory()->create([
            'employee_id' => $present->id, 'work_date' => $today, 'status' => 'present',
        ]);
        AttendanceRecord::factory()->create([
            'employee_id' => $late->id, 'work_date' => $today, 'status' => 'late',
        ]);
        AttendanceRecord::factory()->create([
            'employee_id' => $absent->id, 'work_date' => $today, 'status' => 'absent',
        ]);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // stats.present aggregates present + late (1 + 1 = 2).
                ->where('stats.present', 2)
                ->where('stats.late', 1)
                ->where('stats.absent', 1)
                ->has('recentAttendance', 3)
                ->where('can.createIncident', true)
                ->where('can.generateReport', true));
    }

    public function test_admin_dashboard_exposes_current_payroll_when_present(): void
    {
        $admin = $this->actingAsAdmin();
        $period = PayrollPeriod::factory()->draft()->create(['created_by' => $admin->id]);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('currentPayroll')
                ->where('currentPayroll.id', $period->id)
                ->where('currentPayroll.status', $period->status));
    }

    public function test_admin_dashboard_current_payroll_null_when_only_paid_exists(): void
    {
        $admin = $this->actingAsAdmin();
        // Only a 'paid' period exists; the controller excludes status='paid'.
        PayrollPeriod::factory()->paid()->create(['created_by' => $admin->id]);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('currentPayroll', null));
    }

    // ---------------------------------------------------------------------
    // supervisor (redirect — supervisorDashboard() is not used by index())
    // ---------------------------------------------------------------------

    public function test_supervisor_is_redirected_to_incidents(): void
    {
        $supervisor = $this->actingAsSupervisor();
        $this->attachEmployee($supervisor);

        $this->get(route('dashboard'))->assertRedirect(route('incidents.index'));
    }

    // ---------------------------------------------------------------------
    // employee
    // ---------------------------------------------------------------------

    public function test_employee_with_linked_record_sees_personal_dashboard(): void
    {
        $employeeUser = $this->actingAsEmployee();
        $this->attachEmployee($employeeUser, [
            'vacation_days_entitled' => 12,
            'vacation_days_used' => 4,
        ]);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('userRole', 'employee')
                ->has('employeeName')
                ->has('stats.presentDays')
                ->has('stats.lateDays')
                ->has('stats.absentDays')
                ->where('stats.vacationBalance', 8)
                ->where('stats.vacationEntitled', 12)
                ->has('myRequests')
                ->has('can.createIncident'));
    }

    public function test_employee_dashboard_exposes_today_attendance(): void
    {
        $employeeUser = $this->actingAsEmployee();
        $employee = $this->attachEmployee($employeeUser);
        $today = now()->toDateString();

        AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => $today,
            'status' => 'present',
            'check_in' => '09:00:00',
            'check_out' => '18:00:00',
        ]);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('userRole', 'employee')
                ->has('todayAttendance')
                ->where('todayAttendance.status', 'present')
                ->where('todayAttendance.checkIn', '09:00:00')
                ->where('todayAttendance.checkOut', '18:00:00'));
    }

    public function test_employee_without_linked_record_sees_empty_dashboard(): void
    {
        $this->actingAsEmployee();

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('userRole', 'employee')
                ->where('stats', [])
                ->where('recentAttendance', [])
                ->where('myRequests', [])
                ->has('can.createIncident'));
    }

    public function test_guest_redirected_to_login_from_dashboard(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }
}
