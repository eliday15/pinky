<?php

namespace App\Http\Controllers;

use App\Http\Traits\VerifiesTwoFactor;
use App\Models\AttendanceAnomaly;
use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Employee;
use App\Services\ZktecoSyncService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing authorizations (overtime, night shifts, permissions).
 */
class AuthorizationController extends Controller
{
    use VerifiesTwoFactor;

    /**
     * Display a listing of authorizations.
     *
     * Filters data based on user permissions:
     * - authorizations.view_all: All authorizations
     * - authorizations.view_team: Only team authorizations
     * - authorizations.view_own: Only the user's own authorizations
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Authorization::class);

        $user = Auth::user();
        $query = Authorization::with(['employee.department', 'requestedBy', 'approvedBy', 'compensationType']);

        // Apply permission-based filtering
        if (! $user->hasPermissionTo('authorizations.view_all')) {
            if ($user->hasPermissionTo('authorizations.view_team')) {
                $userEmployee = $user->employee;
                if ($userEmployee) {
                    $allowedIds = $userEmployee->allSubordinateIds();
                    $query->whereHas('employee', function ($q) use ($allowedIds) {
                        $q->whereIn('id', $allowedIds);
                    });
                } else {
                    $query->whereRaw('1 = 0');
                }
            } elseif ($user->hasPermissionTo('authorizations.view_own')) {
                $query->where('employee_id', $user->employee?->id);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        // Apply search filters
        $query->when($request->status, function ($q, $status) {
            $q->where('status', $status);
        })
            ->when($request->type, function ($q, $type) {
                $q->where('type', $type);
            })
            ->when($request->employee, function ($q, $employee) {
                $q->where('employee_id', $employee);
            })
            ->when($request->search, function ($q, $search) {
                $q->whereHas('employee', function ($e) use ($search) {
                    $e->where('full_name', 'like', "%{$search}%");
                });
            })
            ->when($request->from_date, function ($q, $fromDate) {
                $q->where('date', '>=', $fromDate);
            })
            ->when($request->to_date, function ($q, $toDate) {
                $q->where('date', '<=', $toDate);
            });

        $authorizations = $query->orderBy('created_at', 'desc')->paginate(15)->withQueryString();

        // Pending count (scoped to user's view permissions)
        $pendingQuery = Authorization::pending();
        if (! $user->hasPermissionTo('authorizations.view_all')) {
            if ($user->hasPermissionTo('authorizations.view_team')) {
                $userEmployee = $user->employee;
                if ($userEmployee) {
                    $allowedIds = $userEmployee->allSubordinateIds();
                    $pendingQuery->whereHas('employee', function ($q) use ($allowedIds) {
                        $q->whereIn('id', $allowedIds);
                    });
                }
            } elseif ($user->hasPermissionTo('authorizations.view_own')) {
                $pendingQuery->where('employee_id', $user->employee?->id);
            }
        }
        $pendingCount = $pendingQuery->count();

        // Get employees for filter (scoped)
        $employeesQuery = Employee::active()->orderBy('full_name');
        if (! $user->hasPermissionTo('authorizations.view_all')) {
            if ($user->hasPermissionTo('authorizations.view_team')) {
                $userEmployee = $user->employee;
                if ($userEmployee) {
                    $employeesQuery->whereIn('id', $userEmployee->allSubordinateIds());
                }
            } elseif ($user->hasPermissionTo('authorizations.view_own')) {
                $employeesQuery->where('id', $user->employee?->id);
            }
        }

        return Inertia::render('Authorizations/Index', [
            'authorizations' => $authorizations,
            'employees' => $employeesQuery->get(['id', 'full_name']),
            'pendingCount' => $pendingCount,
            'filters' => $request->only(['status', 'type', 'employee', 'search', 'from_date', 'to_date']),
            'types' => $this->getAuthorizationTypes(),
            'can' => [
                'create' => $user->can('create', Authorization::class),
                'approve' => $user->hasPermissionTo('authorizations.approve'),
                'reject' => $user->hasPermissionTo('authorizations.reject'),
            ],
        ]);
    }

    /**
     * Show the form for creating a new authorization.
     */
    public function create(Request $request): Response
    {
        $this->authorize('create', Authorization::class);

        $user = Auth::user();

        // Scope employees based on permissions
        $employeesQuery = Employee::active()->orderBy('full_name');
        if (! $user->hasPermissionTo('authorizations.view_all')) {
            if ($user->hasPermissionTo('authorizations.view_team')) {
                $userEmployee = $user->employee;
                if ($userEmployee) {
                    // Supervisors only see employees they directly supervise (plus themselves)
                    $allowedIds = array_merge([$userEmployee->id], $userEmployee->allSubordinateIds());
                    $employeesQuery->whereIn('id', $allowedIds);
                }
            } else {
                // Can only create for themselves
                $employeesQuery->where('id', $user->employee?->id);
            }
        }

        $employees = $employeesQuery->get(['id', 'full_name', 'employee_number']);
        $this->appendActiveCompensationTypeIds($employees);

        $types = $this->getAuthorizationTypes();
        $prefill = null;
        $selectedEmployee = $request->employee ?? $user->employee?->id;

        if ($request->filled('anomaly')) {
            $anomaly = AttendanceAnomaly::with('attendanceRecord')->find($request->anomaly);

            if ($anomaly && $employees->contains('id', $anomaly->employee_id)) {
                $prefill = $this->buildPrefillFromAnomaly($anomaly, $employees, $types);
                if ($prefill) {
                    $selectedEmployee = $anomaly->employee_id;
                }
            }
        }

        return Inertia::render('Authorizations/Create', [
            'employees' => $employees,
            'selectedEmployee' => $selectedEmployee,
            'types' => $types,
            'prefill' => $prefill,
        ]);
    }

    /**
     * Build a prefill payload from an attendance anomaly for the Create form.
     *
     * Maps unauthorized_overtime -> overtime, unauthorized_velada -> night_shift,
     * suggesting start/end times derived from the attendance record. Returns
     * null when the anomaly type is not eligible for retroactive authorization.
     */
    private function buildPrefillFromAnomaly(AttendanceAnomaly $anomaly, $employees, array $types): ?array
    {
        $typeMap = [
            'unauthorized_overtime' => Authorization::TYPE_OVERTIME,
            'unauthorized_velada' => Authorization::TYPE_NIGHT_SHIFT,
        ];

        if (!isset($typeMap[$anomaly->anomaly_type])) {
            return null;
        }

        $authType = $typeMap[$anomaly->anomaly_type];
        $employee = $employees->firstWhere('id', $anomaly->employee_id);
        $activeIds = $employee?->getAttribute('active_compensation_type_ids') ?? collect();

        $matchedType = collect($types)->first(function ($t) use ($authType, $activeIds) {
            return $t['value'] === $authType && $activeIds->contains($t['compensation_type_id']);
        });

        $record = $anomaly->attendanceRecord;
        $hours = $authType === Authorization::TYPE_OVERTIME
            ? (float) ($record?->overtime_hours ?? 0)
            : (float) ($record?->velada_hours ?? 0);

        [$startTime, $endTime] = $this->suggestTimeRange($record, $hours);

        $reasonPrefix = $authType === Authorization::TYPE_OVERTIME
            ? 'Autorizacion retroactiva por horas extra detectadas'
            : 'Autorizacion retroactiva por velada detectada';

        return [
            'anomaly_id' => $anomaly->id,
            'anomaly_summary' => $anomaly->description,
            'employee_id' => $anomaly->employee_id,
            'type' => $authType,
            'compensation_type_id' => $matchedType['compensation_type_id'] ?? null,
            'date' => $anomaly->work_date->format('Y-m-d'),
            'start_time' => $startTime,
            'end_time' => $endTime,
            'hours' => $hours > 0 ? number_format($hours, 2, '.', '') : '',
            'reason' => "{$reasonPrefix} el {$anomaly->work_date->format('Y-m-d')}.",
        ];
    }

    /**
     * Suggest a start/end time pair from an attendance record and overtime/velada hours.
     *
     * Uses the real check-out as the end and back-calculates start by subtracting
     * the hours. Returns ['HH:MM', 'HH:MM'] or [null, null] when data is missing.
     */
    private function suggestTimeRange(?\App\Models\AttendanceRecord $record, float $hours): array
    {
        if (!$record || !$record->check_out || $hours <= 0) {
            return [null, null];
        }

        try {
            $end = Carbon::parse($record->check_out);
            $start = $end->copy()->subMinutes((int) round($hours * 60));
            return [$start->format('H:i'), $end->format('H:i')];
        } catch (\Throwable $e) {
            return [null, null];
        }
    }

    /**
     * Store a newly created authorization.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Authorization::class);

        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'type' => ['required', Rule::in([
                Authorization::TYPE_OVERTIME,
                Authorization::TYPE_NIGHT_SHIFT,
                Authorization::TYPE_HOLIDAY_WORKED,
                Authorization::TYPE_SPECIAL,
            ])],
            'compensation_type_id' => ['nullable', 'exists:compensation_types,id'],
            'date' => ['required', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'reason' => ['required', 'string', 'max:1000'],
            'evidence' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'anomaly_id' => ['nullable', 'exists:attendance_anomalies,id'],
        ]);

        $anomalyId = $validated['anomaly_id'] ?? null;
        unset($validated['anomaly_id']);

        $validated['requested_by'] = Auth::id();
        $validated['is_pre_authorization'] = Carbon::parse($validated['date'])->isFuture()
            || Carbon::parse($validated['date'])->isToday();

        // Calculate hours if start and end time provided
        if (! empty($validated['start_time']) && ! empty($validated['end_time']) && empty($validated['hours'])) {
            $start = Carbon::parse($validated['start_time']);
            $end = Carbon::parse($validated['end_time']);
            $validated['hours'] = $end->diffInMinutes($start) / 60;
        }

        // Handle file upload
        if ($request->hasFile('evidence')) {
            $validated['evidence_path'] = $request->file('evidence')->store('authorizations', 'public');
        }
        unset($validated['evidence']);

        $authorization = Authorization::create($validated);

        if ($anomalyId) {
            $anomaly = AttendanceAnomaly::find($anomalyId);
            if ($anomaly && $anomaly->employee_id === $authorization->employee_id && $anomaly->status === AttendanceAnomaly::STATUS_OPEN) {
                $anomaly->linkToAuthorization($authorization);
            }
        }

        return redirect()->route('authorizations.index')
            ->with('success', 'Autorizacion creada exitosamente.');
    }

    /**
     * Show the form for creating bulk authorizations.
     */
    public function createBulk(Request $request): Response
    {
        $this->authorize('create', Authorization::class);

        $user = Auth::user();

        // Scope employees based on permissions
        $employeesQuery = Employee::active()->orderBy('full_name');
        if (! $user->hasPermissionTo('authorizations.view_all')) {
            if ($user->hasPermissionTo('authorizations.view_team')) {
                $userEmployee = $user->employee;
                if ($userEmployee) {
                    // Supervisors only see employees they directly supervise (plus themselves)
                    $allowedIds = array_merge([$userEmployee->id], $userEmployee->allSubordinateIds());
                    $employeesQuery->whereIn('id', $allowedIds);
                }
            } else {
                $employeesQuery->where('id', $user->employee?->id);
            }
        }

        $employees = $employeesQuery->get(['id', 'full_name', 'employee_number', 'department_id']);
        $this->appendActiveCompensationTypeIds($employees);

        return Inertia::render('Authorizations/CreateBulk', [
            'employees' => $employees,
            'types' => $this->getAuthorizationTypes(),
            'departments' => \App\Models\Department::active()->get(['id', 'name']),
            'departmentHeads' => Employee::active()
                ->whereHas('position', fn($q) => $q->whereNotNull('supervisor_position_id'))
                ->orderBy('full_name')
                ->get(['id', 'full_name', 'department_id']),
        ]);
    }

    /**
     * Store bulk authorizations for multiple employees.
     */
    public function storeBulk(Request $request): RedirectResponse
    {
        $this->authorize('create', Authorization::class);

        $validated = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['required', 'exists:employees,id'],
            'type' => ['required', Rule::in([
                Authorization::TYPE_OVERTIME,
                Authorization::TYPE_NIGHT_SHIFT,
                Authorization::TYPE_HOLIDAY_WORKED,
                Authorization::TYPE_SPECIAL,
            ])],
            'compensation_type_id' => ['nullable', 'exists:compensation_types,id'],
            'date' => ['required', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'reason' => ['required', 'string', 'max:1000'],
            'department_head_id' => ['nullable', 'exists:employees,id'],
            // Optional per-employee overrides (keyed by employee_id)
            'employee_times' => ['nullable', 'array'],
            'employee_times.*.start_time' => ['nullable', 'date_format:H:i'],
            'employee_times.*.end_time' => ['nullable', 'date_format:H:i'],
            'employee_times.*.hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
        ]);

        // Global hours fallback when no per-employee override is provided.
        $globalHours = $validated['hours'] ?? null;
        if (! empty($validated['start_time']) && ! empty($validated['end_time']) && empty($globalHours)) {
            $start = Carbon::parse($validated['start_time']);
            $end = Carbon::parse($validated['end_time']);
            $globalHours = $end->diffInMinutes($start) / 60;
        }

        $isPreAuthorization = Carbon::parse($validated['date'])->isFuture()
            || Carbon::parse($validated['date'])->isToday();

        $bulkGroupId = 'bulk_' . now()->format('YmdHis') . '_' . Auth::id();
        $employeeTimes = $validated['employee_times'] ?? [];

        $count = 0;
        foreach ($validated['employee_ids'] as $employeeId) {
            $override = $employeeTimes[$employeeId] ?? null;

            $startTime = $override['start_time'] ?? ($validated['start_time'] ?? null);
            $endTime = $override['end_time'] ?? ($validated['end_time'] ?? null);
            $hours = $override['hours'] ?? $globalHours;

            // Calculate per-employee hours from override times when not given explicitly.
            if (! empty($startTime) && ! empty($endTime) && empty($hours)) {
                $hours = Carbon::parse($endTime)->diffInMinutes(Carbon::parse($startTime)) / 60;
            }

            Authorization::create([
                'employee_id' => $employeeId,
                'requested_by' => Auth::id(),
                'type' => $validated['type'],
                'compensation_type_id' => $validated['compensation_type_id'] ?? null,
                'date' => $validated['date'],
                'start_time' => $startTime,
                'end_time' => $endTime,
                'hours' => $hours,
                'reason' => $validated['reason'],
                'is_pre_authorization' => $isPreAuthorization,
                'department_head_id' => $validated['department_head_id'] ?? null,
                'is_bulk_generated' => true,
                'bulk_group_id' => $bulkGroupId,
            ]);
            $count++;
        }

        return redirect()->route('authorizations.index')
            ->with('success', "Se crearon {$count} autorizaciones exitosamente.");
    }

    /**
     * Display the specified authorization.
     */
    public function show(Authorization $authorization): Response
    {
        $this->authorize('view', $authorization);

        $user = Auth::user();
        $authorization->load(['employee.department', 'requestedBy', 'approvedBy', 'attendanceRecord']);

        return Inertia::render('Authorizations/Show', [
            'authorization' => $authorization,
            'can' => [
                'edit' => $user->can('update', $authorization),
                'delete' => $user->can('delete', $authorization),
                'approve' => $user->can('approve', $authorization),
                'reject' => $user->can('reject', $authorization),
            ],
        ]);
    }

    /**
     * Show the form for editing the authorization.
     */
    public function edit(Authorization $authorization): Response
    {
        $this->authorize('update', $authorization);

        $authorization->load(['employee']);

        $employees = Employee::active()->orderBy('full_name')->get(['id', 'full_name', 'employee_number']);
        $this->appendActiveCompensationTypeIds($employees);

        return Inertia::render('Authorizations/Edit', [
            'authorization' => $authorization,
            'employees' => $employees,
            'types' => $this->getAuthorizationTypes(),
        ]);
    }

    /**
     * Update the specified authorization.
     */
    public function update(Request $request, Authorization $authorization): RedirectResponse
    {
        $this->authorize('update', $authorization);

        if (! $authorization->isPending()) {
            return redirect()->back()->with('error', 'Solo se pueden editar autorizaciones pendientes.');
        }

        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'type' => ['required', Rule::in([
                Authorization::TYPE_OVERTIME,
                Authorization::TYPE_NIGHT_SHIFT,
                Authorization::TYPE_HOLIDAY_WORKED,
                Authorization::TYPE_SPECIAL,
            ])],
            'compensation_type_id' => ['nullable', 'exists:compensation_types,id'],
            'date' => ['required', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        // Calculate hours if start and end time provided
        if (! empty($validated['start_time']) && ! empty($validated['end_time']) && empty($validated['hours'])) {
            $start = Carbon::parse($validated['start_time']);
            $end = Carbon::parse($validated['end_time']);
            $validated['hours'] = $end->diffInMinutes($start) / 60;
        }

        $authorization->update($validated);

        return redirect()->route('authorizations.index')
            ->with('success', 'Autorizacion actualizada.');
    }

    /**
     * Remove the specified authorization.
     */
    public function destroy(Authorization $authorization): RedirectResponse
    {
        $this->authorize('delete', $authorization);

        if (! $authorization->isPending()) {
            return redirect()->back()->with('error', 'Solo se pueden eliminar autorizaciones pendientes.');
        }

        $authorization->delete();

        return redirect()->route('authorizations.index')
            ->with('success', 'Autorizacion eliminada.');
    }

    /**
     * Approve an authorization.
     */
    public function approve(Request $request, Authorization $authorization, ZktecoSyncService $syncService): RedirectResponse
    {
        $this->authorize('approve', $authorization);
        $this->verifyTwoFactorCode($request);

        if (! $authorization->isPending()) {
            return redirect()->back()->with('error', 'Esta autorizacion ya fue procesada.');
        }

        $authorization->approve(Auth::user());

        // FASE 1.4: Recalculate attendance if this affects an existing record
        if ($authorization->attendance_record_id) {
            $syncService->recalculateAttendanceRecord($authorization->attendanceRecord);
        } else {
            // Find attendance record by employee and date if not directly linked
            $attendanceRecord = AttendanceRecord::where('employee_id', $authorization->employee_id)
                ->where('work_date', $authorization->date)
                ->first();

            if ($attendanceRecord) {
                $syncService->recalculateAttendanceRecord($attendanceRecord);
            }
        }

        // Auto-resolve related anomalies
        $this->autoResolveAnomalies($authorization);

        return redirect()->back()->with('success', 'Autorizacion aprobada.');
    }

    /**
     * Auto-resolve anomalies that match an approved authorization.
     *
     * For night shift authorizations, resolves both unauthorized_velada
     * and velada_missing_confirmation anomalies.
     */
    private function autoResolveAnomalies(Authorization $authorization): void
    {
        $typeMap = [
            Authorization::TYPE_OVERTIME => ['unauthorized_overtime'],
            Authorization::TYPE_NIGHT_SHIFT => ['unauthorized_velada', AttendanceAnomaly::TYPE_VELADA_MISSING_CONFIRMATION],
        ];

        $anomalyTypes = $typeMap[$authorization->type] ?? [];
        if (empty($anomalyTypes)) {
            return;
        }

        $anomalies = AttendanceAnomaly::where('employee_id', $authorization->employee_id)
            ->where('work_date', $authorization->date)
            ->whereIn('anomaly_type', $anomalyTypes)
            ->where('status', 'open')
            ->get();

        foreach ($anomalies as $anomaly) {
            $anomaly->linkToAuthorization($authorization);
        }
    }

    /**
     * Reject an authorization.
     */
    public function reject(Request $request, Authorization $authorization): RedirectResponse
    {
        $this->authorize('reject', $authorization);
        $this->verifyTwoFactorCode($request);

        if (! $authorization->isPending()) {
            return redirect()->back()->with('error', 'Esta autorizacion ya fue procesada.');
        }

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:500'],
        ]);

        $authorization->reject(Auth::user(), $validated['rejection_reason']);

        return redirect()->back()->with('success', 'Autorizacion rechazada.');
    }

    /**
     * Mark an authorization as paid.
     */
    public function markPaid(Request $request, Authorization $authorization): RedirectResponse
    {
        $this->authorize('approve', $authorization);
        $this->verifyTwoFactorCode($request);

        if (! $authorization->isApproved()) {
            return redirect()->back()->with('error', 'Solo se pueden marcar como pagadas las autorizaciones aprobadas.');
        }

        $authorization->markAsPaid();

        return redirect()->back()->with('success', 'Autorizacion marcada como pagada.');
    }

    /**
     * Suggest authorization times based on employee schedule vs actual punches.
     *
     * Given employee + date + type, compares the scheduled exit time against the
     * real check-out from attendance records. If the employee stayed longer than
     * scheduled, returns the excess as a suggested start/end/hours range that
     * the user can apply to the create form.
     *
     * Pure read-only: never creates anomalies, authorizations, or records.
     */
    public function suggest(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->authorize('create', Authorization::class);

        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'date' => ['required', 'date'],
            'type' => ['required', Rule::in([Authorization::TYPE_OVERTIME, Authorization::TYPE_NIGHT_SHIFT])],
        ]);

        $user = Auth::user();
        $employee = Employee::find($validated['employee_id']);

        // Permission scoping mirrors create().
        if (! $user->hasPermissionTo('authorizations.view_all')) {
            $allowed = [];
            if ($user->hasPermissionTo('authorizations.view_team') && $user->employee) {
                $allowed = array_merge([$user->employee->id], $user->employee->allSubordinateIds());
            } elseif ($user->employee) {
                $allowed = [$user->employee->id];
            }
            if (! in_array($employee->id, $allowed, true)) {
                return response()->json(['found' => false, 'message' => 'No autorizado.'], 403);
            }
        }

        $record = AttendanceRecord::where('employee_id', $employee->id)
            ->where('work_date', $validated['date'])
            ->first();

        if (! $record || ! $record->check_in || ! $record->check_out) {
            return response()->json([
                'found' => false,
                'message' => 'No hay checadas registradas para esa fecha.',
            ]);
        }

        return response()->json($this->buildSuggestion($employee, $validated['date'], $validated['type'], $record));
    }

    /**
     * Suggest authorization times for multiple employees on the same date+type.
     *
     * Returns one suggestion entry per requested employee_id so the bulk form
     * can pre-fill per-employee start/end/hours from each one's real punches.
     */
    public function suggestBulk(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->authorize('create', Authorization::class);

        $validated = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['required', 'integer', 'exists:employees,id'],
            'date' => ['required', 'date'],
            'type' => ['required', Rule::in([Authorization::TYPE_OVERTIME, Authorization::TYPE_NIGHT_SHIFT])],
        ]);

        $user = Auth::user();
        $allowed = null;
        if (! $user->hasPermissionTo('authorizations.view_all')) {
            if ($user->hasPermissionTo('authorizations.view_team') && $user->employee) {
                $allowed = array_merge([$user->employee->id], $user->employee->allSubordinateIds());
            } elseif ($user->employee) {
                $allowed = [$user->employee->id];
            } else {
                $allowed = [];
            }
        }

        $employees = Employee::whereIn('id', $validated['employee_ids'])->get();
        $records = AttendanceRecord::whereIn('employee_id', $validated['employee_ids'])
            ->where('work_date', $validated['date'])
            ->get()
            ->keyBy('employee_id');

        // Only return employees with actual overtime/velada to authorize. Empty rows
        // (no punches, no extra time) clutter the UI, so we drop them here.
        $suggestions = $employees->map(function (Employee $employee) use ($allowed, $validated, $records) {
            if ($allowed !== null && ! in_array($employee->id, $allowed, true)) {
                return null;
            }

            $record = $records->get($employee->id);
            if (! $record || ! $record->check_in || ! $record->check_out) {
                return null;
            }

            $built = $this->buildSuggestion($employee, $validated['date'], $validated['type'], $record);
            if (empty($built['found'])) {
                return null;
            }

            return [
                'employee_id' => $employee->id,
                'employee_name' => $employee->full_name,
                'employee_number' => $employee->employee_number,
            ] + $built;
        })->filter()->values();

        return response()->json([
            'suggestions' => $suggestions,
            'eligible_count' => $suggestions->count(),
            'skipped_count' => $employees->count() - $suggestions->count(),
        ]);
    }

    /**
     * Build a single-employee suggestion as an array (no JsonResponse wrapper).
     *
     * Pure function shared by suggest() and suggestBulk(). Returns the same
     * shape the frontend expects: { found, start_time?, end_time?, hours?, summary?, message? }.
     */
    private function buildSuggestion(Employee $employee, string $date, string $type, AttendanceRecord $record): array
    {
        $dayName = Carbon::parse($date)->format('l');
        $schedule = $employee->getEffectiveScheduleForDay($dayName);

        if ($type === Authorization::TYPE_OVERTIME) {
            return $this->buildOvertimeSuggestion($record, $schedule, $date);
        }

        return $this->buildVeladaSuggestion($record);
    }

    /**
     * Overtime suggestion: compare schedule.exit_time vs actual check_out.
     *
     * Hours uses the calculated overtime_hours (which already discounts the
     * lunch break and daily_work_hours threshold) so the value matches what
     * payroll will see, while the displayed range spans scheduled exit to
     * real check_out.
     */
    private function buildOvertimeSuggestion(AttendanceRecord $record, ?object $schedule, string $date): array
    {
        $scheduledExit = $schedule->exit_time ?? null;
        $checkOut = Carbon::parse($record->check_out);

        if (! $scheduledExit) {
            $hours = (float) ($record->overtime_hours ?? 0);
            if ($hours <= 0) {
                return ['found' => false, 'message' => 'Sin horario asignado y sin horas extra calculadas.'];
            }
            $start = $checkOut->copy()->subMinutes((int) round($hours * 60));
            return [
                'found' => true,
                'start_time' => $start->format('H:i'),
                'end_time' => $checkOut->format('H:i'),
                'hours' => number_format($hours, 2, '.', ''),
                'summary' => "Salida real {$checkOut->format('H:i')}. Horas extra calculadas: {$hours}h.",
            ];
        }

        $scheduledExitDt = Carbon::parse($date . ' ' . $scheduledExit);

        if ($checkOut->lte($scheduledExitDt)) {
            return [
                'found' => false,
                'message' => "Salio {$checkOut->format('H:i')} (horario {$scheduledExit}). Sin tiempo extra.",
            ];
        }

        $calculatedOt = (float) ($record->overtime_hours ?? 0);
        $hours = $calculatedOt > 0
            ? round($calculatedOt, 2)
            : round($scheduledExitDt->diffInMinutes($checkOut) / 60, 2);

        if ($hours <= 0) {
            return [
                'found' => false,
                'message' => "Salida {$checkOut->format('H:i')} excede {$scheduledExit}, pero descontando comida no hay tiempo extra.",
            ];
        }

        return [
            'found' => true,
            'start_time' => $scheduledExitDt->format('H:i'),
            'end_time' => $checkOut->format('H:i'),
            'hours' => number_format($hours, 2, '.', ''),
            'summary' => "Horario {$scheduledExit} -> salida {$checkOut->format('H:i')}. Tiempo extra neto: {$hours}h.",
        ];
    }

    /**
     * Velada suggestion: back-extend velada_hours from the real check_out.
     */
    private function buildVeladaSuggestion(AttendanceRecord $record): array
    {
        $hours = (float) ($record->velada_hours ?? 0);
        if ($hours <= 0) {
            return ['found' => false, 'message' => 'Sin horas de velada detectadas.'];
        }

        $checkOut = Carbon::parse($record->check_out);
        $start = $checkOut->copy()->subMinutes((int) round($hours * 60));

        return [
            'found' => true,
            'start_time' => $start->format('H:i'),
            'end_time' => $checkOut->format('H:i'),
            'hours' => number_format($hours, 2, '.', ''),
            'summary' => "Velada: {$hours}h hasta salida {$checkOut->format('H:i')}.",
        ];
    }

    /**
     * Append active compensation type IDs to each employee in the collection.
     *
     * Eager-loads the pivot and sets a `active_compensation_type_ids` attribute
     * so the frontend can filter authorization types per employee.
     */
    private function appendActiveCompensationTypeIds(\Illuminate\Database\Eloquent\Collection $employees): void
    {
        $employees->load(['compensationTypes' => fn($q) => $q->wherePivot('is_active', true)->select('compensation_types.id')]);

        $employees->each(function (Employee $emp) {
            $emp->setAttribute('active_compensation_type_ids', $emp->compensationTypes->pluck('id')->values());
            $emp->unsetRelation('compensationTypes');
        });
    }

    /**
     * Get authorization types for dropdown.
     *
     * Returns compensation-linked types from the CompensationType catalog.
     */
    private function getAuthorizationTypes(): array
    {
        // All active compensation types in the catalog. Those without an explicit
        // authorization_type fall back to 'special' so they remain valid against
        // the type validation rule and still appear in the dropdown.
        $compTypes = CompensationType::active()
            ->orderBy('priority')
            ->get(['id', 'name', 'code', 'authorization_type', 'application_mode']);

        $types = $compTypes->map(fn(CompensationType $ct) => [
            'value' => $ct->authorization_type ?: 'special',
            'label' => $ct->name,
            'compensation_type_id' => $ct->id,
            'application_mode' => $ct->application_mode,
            'requires_evidence' => true,
            'group' => 'compensation',
        ])->toArray();

        return $types;
    }
}
