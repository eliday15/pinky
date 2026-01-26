<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\Employee;
use App\Models\Incident;
use App\Models\PayrollPeriod;
use App\Models\SyncLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for the dashboard.
 *
 * Displays role-specific dashboards:
 * - Admin/RRHH: Global KPIs, pending approvals, payroll summary, system alerts
 * - Supervisor: Team KPIs, team pending approvals, team attendance
 * - Employee: Personal attendance, vacation balance, personal requests
 */
class DashboardController extends Controller
{
    /**
     * Display the dashboard.
     */
    public function index(): Response|\Illuminate\Http\RedirectResponse
    {
        $user = Auth::user();
        $today = now()->toDateString();

        // Get role-specific data
        if ($user->hasRole('admin') || $user->hasRole('rrhh')) {
            return $this->adminDashboard($today);
        }

        // Supervisors are redirected to incidents - they only have access to incidents and authorizations
        if ($user->hasRole('supervisor')) {
            return redirect()->route('incidents.index');
        }

        return $this->employeeDashboard($today, $user);
    }

    /**
     * Admin/RRHH Dashboard - Global view of all data.
     */
    private function adminDashboard(string $today): Response
    {
        // Get only active employee IDs for filtering
        $activeEmployeeIds = Employee::active()->pluck('id');

        // Get attendance stats for today - only active employees
        $presentCount = AttendanceRecord::where('work_date', $today)
            ->whereIn('employee_id', $activeEmployeeIds)
            ->where('status', 'present')
            ->count();

        $lateCount = AttendanceRecord::where('work_date', $today)
            ->whereIn('employee_id', $activeEmployeeIds)
            ->where('status', 'late')
            ->count();

        $absentCount = AttendanceRecord::where('work_date', $today)
            ->whereIn('employee_id', $activeEmployeeIds)
            ->where('status', 'absent')
            ->count();

        $totalEmployees = $activeEmployeeIds->count();

        // Get pending approvals
        $pendingIncidents = Incident::pending()->count();
        $pendingAuthorizations = Authorization::pending()->count();

        // Get last sync info
        $lastSync = SyncLog::completed()->latest('completed_at')->first();
        $lastSyncTime = $lastSync ? $lastSync->completed_at->diffForHumans() : 'Nunca';

        // Check if there's a sync in progress
        $syncInProgress = SyncLog::where('status', 'running')
            ->where('started_at', '>', now()->subMinutes(30))
            ->exists();

        // Get payroll info
        $currentPayroll = PayrollPeriod::where('status', '!=', 'paid')
            ->orderBy('created_at', 'desc')
            ->first();

        // Get recent attendance records - only active employees
        $recentAttendance = $this->getRecentAttendance($today, $activeEmployeeIds->toArray());

        // Get recent pending approvals
        $pendingApprovals = $this->getPendingApprovals();

        return Inertia::render('Dashboard', [
            'userRole' => 'admin',
            'stats' => [
                'present' => $presentCount + $lateCount,
                'late' => $lateCount,
                'absent' => $absentCount,
                'total' => $totalEmployees,
                'lastSync' => $lastSyncTime,
                'syncInProgress' => $syncInProgress,
                'activeDevices' => 4,
                'pendingIncidents' => $pendingIncidents,
                'pendingAuthorizations' => $pendingAuthorizations,
            ],
            'recentAttendance' => $recentAttendance,
            'pendingApprovals' => $pendingApprovals,
            'currentPayroll' => $currentPayroll ? [
                'id' => $currentPayroll->id,
                'period' => $currentPayroll->start_date->format('d/m') . ' - ' . $currentPayroll->end_date->format('d/m'),
                'status' => $currentPayroll->status,
            ] : null,
            'can' => [
                'sync' => true,
                'createEmployee' => true,
                'createIncident' => true,
                'generateReport' => true,
                'calculatePayroll' => true,
            ],
        ]);
    }

    /**
     * Supervisor Dashboard - Team-focused view.
     */
    private function supervisorDashboard(string $today, $user): Response
    {
        $userEmployee = $user->employee;
        $departmentId = $userEmployee?->department_id;

        // Team employee IDs
        $teamQuery = Employee::active();
        if ($departmentId) {
            $teamQuery->where(function ($q) use ($departmentId, $userEmployee) {
                $q->where('department_id', $departmentId)
                    ->orWhere('supervisor_id', $userEmployee->id);
            });
        }
        $teamEmployeeIds = $teamQuery->pluck('id');
        $totalTeam = $teamEmployeeIds->count();

        // Team attendance stats for today
        $presentCount = AttendanceRecord::where('work_date', $today)
            ->whereIn('employee_id', $teamEmployeeIds)
            ->where('status', 'present')
            ->count();

        $lateCount = AttendanceRecord::where('work_date', $today)
            ->whereIn('employee_id', $teamEmployeeIds)
            ->where('status', 'late')
            ->count();

        $absentCount = AttendanceRecord::where('work_date', $today)
            ->whereIn('employee_id', $teamEmployeeIds)
            ->where('status', 'absent')
            ->count();

        // Team pending approvals
        $pendingIncidents = Incident::pending()
            ->whereIn('employee_id', $teamEmployeeIds)
            ->count();

        $pendingAuthorizations = Authorization::pending()
            ->whereIn('employee_id', $teamEmployeeIds)
            ->count();

        // Team recent attendance
        $recentAttendance = AttendanceRecord::with('employee')
            ->where('work_date', $today)
            ->whereIn('employee_id', $teamEmployeeIds)
            ->orderBy('updated_at', 'desc')
            ->limit(10)
            ->get()
            ->map(fn ($r) => $this->formatAttendanceRecord($r));

        // Team pending approvals list
        $pendingApprovals = $this->getPendingApprovals($teamEmployeeIds->toArray());

        return Inertia::render('Dashboard', [
            'userRole' => 'supervisor',
            'stats' => [
                'present' => $presentCount + $lateCount,
                'late' => $lateCount,
                'absent' => $absentCount,
                'total' => $totalTeam,
                'pendingIncidents' => $pendingIncidents,
                'pendingAuthorizations' => $pendingAuthorizations,
            ],
            'recentAttendance' => $recentAttendance,
            'pendingApprovals' => $pendingApprovals,
            'can' => [
                'sync' => false,
                'createEmployee' => false,
                'createIncident' => true,
                'generateReport' => true,
                'calculatePayroll' => false,
            ],
        ]);
    }

    /**
     * Employee Dashboard - Personal view.
     */
    private function employeeDashboard(string $today, $user): Response
    {
        $employee = $user->employee;

        if (! $employee) {
            return Inertia::render('Dashboard', [
                'userRole' => 'employee',
                'stats' => [],
                'recentAttendance' => [],
                'myRequests' => [],
                'can' => [
                    'createIncident' => true,
                ],
            ]);
        }

        // Get this month's attendance
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();

        $monthlyAttendance = AttendanceRecord::where('employee_id', $employee->id)
            ->whereBetween('work_date', [$monthStart, $monthEnd])
            ->get();

        $presentDays = $monthlyAttendance->whereIn('status', ['present', 'late'])->count();
        $lateDays = $monthlyAttendance->where('status', 'late')->count();
        $absentDays = $monthlyAttendance->where('status', 'absent')->count();

        // Today's attendance
        $todayRecord = AttendanceRecord::where('employee_id', $employee->id)
            ->where('work_date', $today)
            ->first();

        // Vacation balance
        $vacationBalance = $employee->vacation_days_entitled - $employee->vacation_days_used;

        // My recent requests
        $myRequests = $this->getMyRequests($employee->id);

        return Inertia::render('Dashboard', [
            'userRole' => 'employee',
            'employeeName' => $employee->full_name,
            'stats' => [
                'presentDays' => $presentDays,
                'lateDays' => $lateDays,
                'absentDays' => $absentDays,
                'vacationBalance' => $vacationBalance,
                'vacationEntitled' => $employee->vacation_days_entitled,
            ],
            'todayAttendance' => $todayRecord ? [
                'checkIn' => $todayRecord->check_in,
                'checkOut' => $todayRecord->check_out,
                'status' => $todayRecord->status,
                'workedHours' => $todayRecord->worked_hours,
            ] : null,
            'myRequests' => $myRequests,
            'can' => [
                'createIncident' => true,
            ],
        ]);
    }

    /**
     * Get recent attendance records.
     */
    private function getRecentAttendance(string $today, ?array $employeeIds = null)
    {
        $query = AttendanceRecord::with('employee')
            ->where('work_date', $today)
            ->orderBy('updated_at', 'desc')
            ->limit(10);

        if ($employeeIds) {
            $query->whereIn('employee_id', $employeeIds);
        }

        return $query->get()->map(fn ($r) => $this->formatAttendanceRecord($r));
    }

    /**
     * Format attendance record for frontend.
     */
    private function formatAttendanceRecord($record): array
    {
        return [
            'id' => $record->id,
            'employee' => [
                'name' => $record->employee->full_name ?? 'Desconocido',
            ],
            'type' => $record->check_out ? 'out' : 'in',
            'time' => $record->check_out
                ? Carbon::parse($record->check_out)->format('H:i')
                : Carbon::parse($record->check_in)->format('H:i'),
        ];
    }

    /**
     * Get pending approvals.
     */
    private function getPendingApprovals(?array $employeeIds = null): array
    {
        $incidentsQuery = Incident::with('employee', 'incidentType')
            ->pending()
            ->orderBy('created_at', 'desc')
            ->limit(5);

        $authQuery = Authorization::with('employee')
            ->pending()
            ->orderBy('created_at', 'desc')
            ->limit(5);

        if ($employeeIds) {
            $incidentsQuery->whereIn('employee_id', $employeeIds);
            $authQuery->whereIn('employee_id', $employeeIds);
        }

        $incidents = $incidentsQuery->get()->map(fn ($i) => [
            'id' => $i->id,
            'type' => 'incident',
            'typeName' => $i->incidentType->name ?? 'Incidencia',
            'employee' => $i->employee->full_name ?? 'Desconocido',
            'date' => $i->start_date->format('d/m/Y'),
            'route' => route('incidents.show', $i->id),
        ]);

        $authorizations = $authQuery->get()->map(fn ($a) => [
            'id' => $a->id,
            'type' => 'authorization',
            'typeName' => $a->type_name,
            'employee' => $a->employee->full_name ?? 'Desconocido',
            'date' => $a->date->format('d/m/Y'),
            'route' => route('authorizations.show', $a->id),
        ]);

        return $incidents->merge($authorizations)->sortByDesc('date')->values()->toArray();
    }

    /**
     * Get user's own requests.
     */
    private function getMyRequests(int $employeeId): array
    {
        $incidents = Incident::with('incidentType')
            ->where('employee_id', $employeeId)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(fn ($i) => [
                'id' => $i->id,
                'type' => 'incident',
                'typeName' => $i->incidentType->name ?? 'Incidencia',
                'date' => $i->start_date->format('d/m/Y'),
                'status' => $i->status,
                'route' => route('incidents.show', $i->id),
            ]);

        $authorizations = Authorization::where('employee_id', $employeeId)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'type' => 'authorization',
                'typeName' => $a->type_name,
                'date' => $a->date->format('d/m/Y'),
                'status' => $a->status,
                'route' => route('authorizations.show', $a->id),
            ]);

        return $incidents->merge($authorizations)->sortByDesc('date')->values()->take(5)->toArray();
    }
}
