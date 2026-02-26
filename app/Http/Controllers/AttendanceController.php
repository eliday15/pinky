<?php

namespace App\Http\Controllers;

use App\Exports\AttendanceRangeExport;
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
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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

        $startDate = $request->start_date ? Carbon::parse($request->start_date) : Carbon::today();
        $endDate = $request->end_date ? Carbon::parse($request->end_date) : $startDate->copy();

        // Ensure end_date is not before start_date
        if ($endDate->lt($startDate)) {
            $endDate = $startDate->copy();
        }

        // Build list of dates in the range
        $dates = [];
        $current = $startDate->copy();
        while ($current->lte($endDate)) {
            $dates[] = $current->toDateString();
            $current->addDay();
        }

        // Build employee query with attendance for the range
        $employeeQuery = Employee::active()
            ->with(['department', 'schedule'])
            ->with(['attendanceRecords' => function ($q) use ($startDate, $endDate) {
                $q->whereBetween('work_date', [$startDate->toDateString(), $endDate->toDateString()]);
            }]);

        // Apply permission-based filtering
        if (! $user->hasPermissionTo('attendance.view_all')) {
            if ($user->hasPermissionTo('attendance.view_team')) {
                $userEmployee = $user->employee;
                if ($userEmployee) {
                    $employeeQuery->where(function ($q) use ($userEmployee) {
                        $q->where('department_id', $userEmployee->department_id)
                            ->orWhere('supervisor_id', $userEmployee->id);
                    });
                } else {
                    $employeeQuery->whereRaw('1 = 0');
                }
            } elseif ($user->hasPermissionTo('attendance.view_own')) {
                $employeeQuery->where('id', $user->employee?->id);
            } else {
                $employeeQuery->whereRaw('1 = 0');
            }
        }

        // Apply filters
        $employeeQuery->when($request->department, function ($q, $department) {
            $q->where('department_id', $department);
        })
            ->when($request->search, function ($q, $search) {
                $q->where(function ($q) use ($search) {
                    $q->where('full_name', 'like', "%{$search}%")
                        ->orWhere('employee_number', 'like', "%{$search}%");
                });
            });

        $employees = $employeeQuery->orderBy('full_name')->paginate(20)->withQueryString();

        // Transform: key attendance by date for each employee
        $employees->getCollection()->transform(function ($employee) {
            $employee->attendance_by_date = $employee->attendanceRecords
                ->keyBy(fn ($r) => $r->work_date->format('Y-m-d'))
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'check_in' => $r->check_in ? substr($r->check_in, 0, 5) : null,
                    'check_out' => $r->check_out ? substr($r->check_out, 0, 5) : null,
                    'worked_hours' => $r->worked_hours,
                    'overtime_hours' => $r->overtime_hours,
                    'status' => $r->status,
                    'late_minutes' => $r->late_minutes,
                ]);
            unset($employee->attendanceRecords);

            return $employee;
        });

        // Get summary for the range
        $allRecords = AttendanceRecord::whereBetween('work_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->whereIn('employee_id', $employees->getCollection()->pluck('id')->merge(
                Employee::active()->pluck('id')
            ));

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
                    $allRecords->whereIn('employee_id', $teamEmployeeIds);
                }
            } elseif ($user->hasPermissionTo('attendance.view_own')) {
                $allRecords->where('employee_id', $user->employee?->id);
            }
        }

        $summaryCounts = $allRecords
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
            'employees' => $employees,
            'dates' => $dates,
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
            'summary' => $summary,
            'lastSync' => $lastSync ? $lastSync->completed_at->diffForHumans() : 'Nunca',
            'departments' => Department::active()->get(['id', 'name']),
            'filters' => $request->only(['department', 'status', 'search']),
            'can' => [
                'sync' => $user->hasPermissionTo('attendance.sync'),
                'edit' => $user->hasPermissionTo('attendance.edit'),
                'export' => $user->hasPermissionTo('attendance.view_all')
                    || $user->hasPermissionTo('attendance.view_team'),
                'viewOvertimeDetails' => $user->hasPermissionTo('attendance.view_all'),
            ],
        ]);
    }

    /**
     * Export attendance records for a date range to Excel.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $this->authorize('viewAny', AttendanceRecord::class);

        $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $user = Auth::user();
        $startDate = $request->start_date;
        $endDate = $request->end_date;
        $departmentId = $request->department ? (int) $request->department : null;

        // Determine scoped employee IDs based on permissions
        $scopedEmployeeIds = null;
        if (! $user->hasPermissionTo('attendance.view_all')) {
            if ($user->hasPermissionTo('attendance.view_team')) {
                $userEmployee = $user->employee;
                if ($userEmployee) {
                    $scopedEmployeeIds = Employee::active()
                        ->where(function ($q) use ($userEmployee) {
                            $q->where('department_id', $userEmployee->department_id)
                                ->orWhere('supervisor_id', $userEmployee->id);
                        })
                        ->pluck('id');
                } else {
                    $scopedEmployeeIds = collect();
                }
            } elseif ($user->hasPermissionTo('attendance.view_own')) {
                $scopedEmployeeIds = collect([$user->employee?->id])->filter();
            } else {
                $scopedEmployeeIds = collect();
            }
        } else {
            $scopedEmployeeIds = Employee::active()->pluck('id');
        }

        $filename = "asistencia_{$startDate}_{$endDate}.xlsx";

        return Excel::download(
            new AttendanceRangeExport($startDate, $endDate, $departmentId, $scopedEmployeeIds),
            $filename
        );
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

        $attendance->load(['employee.schedule', 'manuallyEditedBy']);

        return Inertia::render('Attendance/Edit', [
            'record' => $attendance,
        ]);
    }

    /**
     * Update an attendance record.
     *
     * Requires a manual_edit_reason and records who edited the record and when.
     */
    public function update(Request $request, AttendanceRecord $attendance): RedirectResponse
    {
        $this->authorize('update', $attendance);

        $validated = $request->validate([
            'check_in' => ['nullable', 'date_format:H:i'],
            'check_out' => ['nullable', 'date_format:H:i'],
            'status' => ['required', 'in:present,late,absent,partial,holiday,vacation,sick_leave,permission'],
            'notes' => ['nullable', 'string', 'max:500'],
            'manual_edit_reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        // Recalculate hours if times changed
        if (isset($validated['check_in']) && isset($validated['check_out'])) {
            $schedule = $attendance->employee->schedule;
            $dateStr = $attendance->work_date->toDateString();
            $checkIn = Carbon::parse($dateStr . ' ' . $validated['check_in']);
            $checkOut = Carbon::parse($dateStr . ' ' . $validated['check_out']);

            // Handle midnight crossing
            if ($checkOut->lt($checkIn)) {
                $checkOut->addDay();
            }

            $workedMinutes = abs($checkIn->diffInMinutes($checkOut));

            if ($schedule) {
                $dayName = strtolower($attendance->work_date->format('l'));
                $daySchedule = $schedule->getScheduleForDay($dayName);
                $departmentBreak = $attendance->employee->department?->default_break_minutes;
                $breakMinutes = $daySchedule->break_minutes ?? $departmentBreak ?? 60;

                if ($workedMinutes > 300) {
                    $workedMinutes -= $breakMinutes;
                }
            }

            $workedMinutes = max(0, $workedMinutes);
            $workedHours = $workedMinutes / 60;
            $dailyHours = $schedule ? ($daySchedule->daily_work_hours ?? 8) : 8;

            $validated['worked_hours'] = min($workedHours, $dailyHours);
            $validated['overtime_hours'] = max(0, $workedHours - $dailyHours);
        }

        $validated['requires_review'] = false;
        $validated['manually_edited_by'] = Auth::id();
        $validated['manually_edited_at'] = now();

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

        // Check if there's already a sync in progress or requested (started within last 30 minutes)
        $runningSync = SyncLog::whereIn('status', ['running', 'requested'])
            ->where('started_at', '>', now()->subMinutes(30))
            ->exists();

        if ($runningSync) {
            return redirect()->back()->with('warning', 'Ya hay una sincronizacion en progreso. Por favor espera a que termine.');
        }

        // Clean up stuck syncs (running or requested for more than 30 minutes)
        SyncLog::whereIn('status', ['running', 'requested'])
            ->where('started_at', '<', now()->subMinutes(30))
            ->update([
                'status' => 'failed',
                'completed_at' => now(),
                'errors' => json_encode(['message' => 'Timeout - proceso excedio 30 minutos']),
            ]);

        // Remote mode: create a request for the local Python agent to pick up
        if (config('zkteco.sync.remote_python')) {
            SyncLog::create([
                'type' => 'zkteco',
                'status' => 'requested',
                'triggered_by' => auth()->id(),
                'started_at' => now(),
            ]);

            return redirect()->back()->with('success', 'Sincronización solicitada. Los dispositivos se sincronizarán en breve.');
        }

        $fromDate = $request->from_date ? Carbon::parse($request->from_date) : null;

        // Local mode: dispatch job directly
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
