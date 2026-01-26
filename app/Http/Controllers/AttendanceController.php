<?php

namespace App\Http\Controllers;

use App\Jobs\SyncZktecoJob;
use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\Department;
use App\Models\Employee;
use App\Models\SyncLog;
use App\Policies\AttendanceRecordPolicy;
use App\Services\ZktecoSyncService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceController extends Controller
{
    /**
     * Display attendance records.
     *
     * Filters data based on user permissions:
     * - attendance.view_all: All attendance records
     * - attendance.view_team: Only team attendance records
     * - attendance.view_own: Only the user's own attendance records
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', AttendanceRecord::class);

        $user = Auth::user();

        // Get last sync info (no auto-sync - it's too slow and blocks the UI)
        // Users should manually sync using the sync button
        $lastSync = SyncLog::completed()->latest('completed_at')->first();

        $date = $request->date ? Carbon::parse($request->date) : Carbon::today();

        // Get only active employee IDs for filtering
        $activeEmployeeIds = Employee::active()->pluck('id');

        $query = AttendanceRecord::with(['employee.department', 'employee.position'])
            ->where('work_date', $date->toDateString())
            ->whereIn('employee_id', $activeEmployeeIds);

        // Apply permission-based filtering (using whereIn for better performance)
        if (! $user->hasPermissionTo('attendance.view_all')) {
            if ($user->hasPermissionTo('attendance.view_team')) {
                $userEmployee = $user->employee;
                if ($userEmployee) {
                    $teamEmployeeIds = Employee::active()
                        ->where(function ($q) use ($userEmployee) {
                            $q->where('department_id', $userEmployee->department_id)
                                ->orWhere('supervisor_id', $userEmployee->id);
                        })
                        ->pluck('id');
                    $query->whereIn('employee_id', $teamEmployeeIds);
                } else {
                    $query->whereRaw('1 = 0');
                }
            } elseif ($user->hasPermissionTo('attendance.view_own')) {
                $query->where('employee_id', $user->employee?->id);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        // Apply search filters (optimized with whereIn) - only active employees
        $query->when($request->department, function ($q, $department) {
            $deptEmployeeIds = Employee::active()->where('department_id', $department)->pluck('id');
            $q->whereIn('employee_id', $deptEmployeeIds);
        })
            ->when($request->status, function ($q, $status) {
                $q->where('status', $status);
            })
            ->when($request->search, function ($q, $search) {
                $searchEmployeeIds = Employee::active()
                    ->where(function ($q) use ($search) {
                        $q->where('full_name', 'like', "%{$search}%")
                            ->orWhere('employee_number', 'like', "%{$search}%");
                    })
                    ->pluck('id');
                $q->whereIn('employee_id', $searchEmployeeIds);
            });

        $records = $query->orderBy('check_in')->paginate(20)->withQueryString();

        // Add authorization flags to each record for frontend visibility control
        $records->getCollection()->transform(function ($record) {
            $record->overtime_authorized = AttendanceRecordPolicy::isOvertimeAuthorized($record);
            $record->night_shift_authorized = AttendanceRecordPolicy::isNightShiftAuthorized($record);
            return $record;
        });

        // Get summary for the day in a single query (optimized) - only active employees
        $summaryQuery = AttendanceRecord::where('work_date', $date->toDateString())
            ->whereIn('employee_id', $activeEmployeeIds);
        if (! $user->hasPermissionTo('attendance.view_all')) {
            if ($user->hasPermissionTo('attendance.view_team')) {
                $userEmployee = $user->employee;
                if ($userEmployee) {
                    $teamEmployeeIds = Employee::active()
                        ->where(function ($q) use ($userEmployee) {
                            $q->where('department_id', $userEmployee->department_id)
                                ->orWhere('supervisor_id', $userEmployee->id);
                        })
                        ->pluck('id');
                    $summaryQuery->whereIn('employee_id', $teamEmployeeIds);
                }
            } elseif ($user->hasPermissionTo('attendance.view_own')) {
                $summaryQuery->where('employee_id', $user->employee?->id);
            }
        }

        // Single query with grouping instead of 4 separate queries
        $summaryCounts = (clone $summaryQuery)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $summary = [
            'present' => $summaryCounts['present'] ?? 0,
            'late' => $summaryCounts['late'] ?? 0,
            'absent' => $summaryCounts['absent'] ?? 0,
            'partial' => $summaryCounts['partial'] ?? 0,
        ];

        return Inertia::render('Attendance/Index', [
            'records' => $records,
            'date' => $date->toDateString(),
            'summary' => $summary,
            'lastSync' => $lastSync ? $lastSync->completed_at->diffForHumans() : 'Nunca',
            'departments' => Department::active()->get(['id', 'name']),
            'filters' => $request->only(['department', 'status', 'search']),
            'can' => [
                'sync' => $user->hasPermissionTo('attendance.sync'),
                'edit' => $user->hasPermissionTo('attendance.edit'),
                'viewOvertimeDetails' => $user->hasPermissionTo('attendance.view_all'),
            ],
        ]);
    }

    /**
     * Show calendar view.
     */
    public function calendar(Request $request): Response
    {
        $month = $request->month ? Carbon::parse($request->month . '-01') : Carbon::today()->startOfMonth();
        $employeeId = $request->employee;

        $employees = Employee::active()->orderBy('full_name')->get(['id', 'full_name', 'employee_number']);

        $calendarData = [];

        if ($employeeId) {
            $records = AttendanceRecord::where('employee_id', $employeeId)
                ->whereBetween('work_date', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
                ->get()
                ->keyBy(fn($r) => $r->work_date->format('Y-m-d'));

            $employee = Employee::with('schedule')->find($employeeId);

            $currentDate = $month->copy()->startOfMonth();
            while ($currentDate->lte($month->copy()->endOfMonth())) {
                $dateStr = $currentDate->toDateString();
                $record = $records->get($dateStr);

                $calendarData[] = [
                    'date' => $dateStr,
                    'day' => $currentDate->day,
                    'dayName' => $currentDate->locale('es')->dayName,
                    'isWeekend' => $currentDate->isWeekend(),
                    'record' => $record ? [
                        'id' => $record->id,
                        'check_in' => $record->check_in,
                        'check_out' => $record->check_out,
                        'worked_hours' => $record->worked_hours,
                        'status' => $record->status,
                        'late_minutes' => $record->late_minutes,
                    ] : null,
                ];

                $currentDate->addDay();
            }
        }

        return Inertia::render('Attendance/Calendar', [
            'employees' => $employees,
            'selectedEmployee' => $employeeId,
            'month' => $month->format('Y-m'),
            'calendarData' => $calendarData,
        ]);
    }

    /**
     * Show a specific attendance record.
     */
    public function show(AttendanceRecord $attendance): Response
    {
        $attendance->load(['employee.department', 'employee.position', 'employee.schedule']);

        return Inertia::render('Attendance/Show', [
            'record' => $attendance,
        ]);
    }

    /**
     * Edit an attendance record.
     */
    public function edit(AttendanceRecord $attendance): Response
    {
        $this->authorize('update', $attendance);

        $attendance->load(['employee.schedule']);

        return Inertia::render('Attendance/Edit', [
            'record' => $attendance,
        ]);
    }

    /**
     * Update an attendance record.
     */
    public function update(Request $request, AttendanceRecord $attendance): RedirectResponse
    {
        $this->authorize('update', $attendance);

        $validated = $request->validate([
            'check_in' => ['nullable', 'date_format:H:i'],
            'check_out' => ['nullable', 'date_format:H:i'],
            'status' => ['required', 'in:present,late,absent,partial,holiday,vacation,sick_leave,permission'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        // Recalculate hours if times changed
        if (isset($validated['check_in']) && isset($validated['check_out'])) {
            $schedule = $attendance->employee->schedule;
            $checkIn = Carbon::parse($validated['check_in']);
            $checkOut = Carbon::parse($validated['check_out']);

            $workedMinutes = $checkOut->diffInMinutes($checkIn);
            if ($schedule) {
                $workedMinutes -= $schedule->break_minutes;
            }

            $workedHours = max(0, $workedMinutes / 60);
            $dailyHours = $schedule ? $schedule->daily_work_hours : 8;

            $validated['worked_hours'] = min($workedHours, $dailyHours);
            $validated['overtime_hours'] = max(0, $workedHours - $dailyHours);
        }

        $validated['requires_review'] = false;

        $attendance->update($validated);

        return redirect()->route('attendance.index', ['date' => $attendance->work_date->toDateString()])
            ->with('success', 'Registro actualizado.');
    }

    /**
     * Trigger manual sync.
     *
     * Checks for existing sync in progress and dispatches a background job
     * to avoid PHP timeout issues.
     */
    public function sync(Request $request): RedirectResponse
    {
        $user = Auth::user();

        if (! $user->hasPermissionTo('attendance.sync')) {
            abort(403, 'No tienes permiso para sincronizar asistencia.');
        }

        // Check if there's already a sync in progress (started within last 30 minutes)
        $runningSync = SyncLog::where('status', 'running')
            ->where('started_at', '>', now()->subMinutes(30))
            ->exists();

        if ($runningSync) {
            return redirect()->back()->with('warning', 'Ya hay una sincronizacion en progreso. Por favor espera a que termine.');
        }

        // Clean up stuck syncs (running for more than 30 minutes)
        SyncLog::where('status', 'running')
            ->where('started_at', '<', now()->subMinutes(30))
            ->update([
                'status' => 'failed',
                'completed_at' => now(),
                'errors' => json_encode(['message' => 'Timeout - proceso excedio 30 minutos']),
            ]);

        $fromDate = $request->from_date ? Carbon::parse($request->from_date) : null;

        // Dispatch background job
        SyncZktecoJob::dispatch($fromDate, auth()->id());

        return redirect()->back()->with('success', 'Sincronizacion iniciada. Los datos se actualizaran en unos minutos.');
    }

    /**
     * Get sync logs.
     */
    public function syncLogs(): Response
    {
        $user = Auth::user();

        if (! $user->hasPermissionTo('attendance.sync')) {
            abort(403, 'No tienes permiso para ver logs de sincronizacion.');
        }

        $logs = SyncLog::with('triggeredBy')
            ->orderBy('started_at', 'desc')
            ->limit(50)
            ->get();

        return Inertia::render('Attendance/SyncLogs', [
            'logs' => $logs,
        ]);
    }
}
