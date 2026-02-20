<?php

namespace App\Http\Controllers;

use App\Http\Traits\VerifiesTwoFactor;
use App\Models\AttendanceAnomaly;
use App\Models\AttendanceRecord;
use App\Models\Authorization;
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
        $query = Authorization::with(['employee.department', 'requestedBy', 'approvedBy']);

        // Apply permission-based filtering
        if (! $user->hasPermissionTo('authorizations.view_all')) {
            if ($user->hasPermissionTo('authorizations.view_team')) {
                $userEmployee = $user->employee;
                if ($userEmployee) {
                    // Supervisors only see employees they directly supervise
                    $query->whereHas('employee', function ($q) use ($userEmployee) {
                        $q->where('supervisor_id', $userEmployee->id);
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
                    // Supervisors only see employees they directly supervise
                    $pendingQuery->whereHas('employee', function ($q) use ($userEmployee) {
                        $q->where('supervisor_id', $userEmployee->id);
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
                    // Supervisors only see employees they directly supervise
                    $employeesQuery->where('supervisor_id', $userEmployee->id);
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
                    $employeesQuery->where(function ($q) use ($userEmployee) {
                        $q->where('supervisor_id', $userEmployee->id)
                            ->orWhere('id', $userEmployee->id);
                    });
                }
            } else {
                // Can only create for themselves
                $employeesQuery->where('id', $user->employee?->id);
            }
        }

        return Inertia::render('Authorizations/Create', [
            'employees' => $employeesQuery->get(['id', 'full_name', 'employee_number']),
            'selectedEmployee' => $request->employee ?? $user->employee?->id,
            'types' => $this->getAuthorizationTypes(),
        ]);
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
                Authorization::TYPE_EXIT_PERMISSION,
                Authorization::TYPE_ENTRY_PERMISSION,
                Authorization::TYPE_SCHEDULE_CHANGE,
                Authorization::TYPE_HOLIDAY_WORKED,
                Authorization::TYPE_SPECIAL,
            ])],
            'date' => ['required', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'reason' => ['required', 'string', 'max:1000'],
            'evidence' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

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

        Authorization::create($validated);

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
                    $employeesQuery->where(function ($q) use ($userEmployee) {
                        $q->where('supervisor_id', $userEmployee->id)
                            ->orWhere('id', $userEmployee->id);
                    });
                }
            } else {
                $employeesQuery->where('id', $user->employee?->id);
            }
        }

        return Inertia::render('Authorizations/CreateBulk', [
            'employees' => $employeesQuery->get(['id', 'full_name', 'employee_number', 'department_id']),
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
                Authorization::TYPE_EXIT_PERMISSION,
                Authorization::TYPE_ENTRY_PERMISSION,
                Authorization::TYPE_SCHEDULE_CHANGE,
                Authorization::TYPE_HOLIDAY_WORKED,
                Authorization::TYPE_SPECIAL,
            ])],
            'date' => ['required', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'reason' => ['required', 'string', 'max:1000'],
            'department_head_id' => ['nullable', 'exists:employees,id'],
        ]);

        // Calculate hours if start and end time provided
        $hours = $validated['hours'] ?? null;
        if (! empty($validated['start_time']) && ! empty($validated['end_time']) && empty($hours)) {
            $start = Carbon::parse($validated['start_time']);
            $end = Carbon::parse($validated['end_time']);
            $hours = $end->diffInMinutes($start) / 60;
        }

        $isPreAuthorization = Carbon::parse($validated['date'])->isFuture()
            || Carbon::parse($validated['date'])->isToday();

        $bulkGroupId = 'bulk_' . now()->format('YmdHis') . '_' . Auth::id();

        $count = 0;
        foreach ($validated['employee_ids'] as $employeeId) {
            Authorization::create([
                'employee_id' => $employeeId,
                'requested_by' => Auth::id(),
                'type' => $validated['type'],
                'date' => $validated['date'],
                'start_time' => $validated['start_time'] ?? null,
                'end_time' => $validated['end_time'] ?? null,
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

        return Inertia::render('Authorizations/Edit', [
            'authorization' => $authorization,
            'employees' => Employee::active()->orderBy('full_name')->get(['id', 'full_name', 'employee_number']),
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
                Authorization::TYPE_EXIT_PERMISSION,
                Authorization::TYPE_ENTRY_PERMISSION,
                Authorization::TYPE_SCHEDULE_CHANGE,
                Authorization::TYPE_HOLIDAY_WORKED,
                Authorization::TYPE_SPECIAL,
            ])],
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
            Authorization::TYPE_EXIT_PERMISSION => ['early_departure'],
            Authorization::TYPE_ENTRY_PERMISSION => ['late_arrival'],
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
     * Get authorization types for dropdown.
     *
     * All types require evidence according to the specification (2.4):
     * - Horas extra: Sí
     * - Veladas: Sí
     * - Permisos de salida: Sí
     * - Permisos de entrada: Sí
     * - Cambio de horario: Sí
     * - Permisos especiales: Sí
     */
    private function getAuthorizationTypes(): array
    {
        return [
            ['value' => Authorization::TYPE_OVERTIME, 'label' => 'Horas Extra', 'requires_evidence' => true],
            ['value' => Authorization::TYPE_NIGHT_SHIFT, 'label' => 'Velada', 'requires_evidence' => true],
            ['value' => Authorization::TYPE_EXIT_PERMISSION, 'label' => 'Permiso de Salida', 'requires_evidence' => true],
            ['value' => Authorization::TYPE_ENTRY_PERMISSION, 'label' => 'Permiso de Entrada', 'requires_evidence' => true],
            ['value' => Authorization::TYPE_SCHEDULE_CHANGE, 'label' => 'Cambio de Horario', 'requires_evidence' => true],
            ['value' => Authorization::TYPE_HOLIDAY_WORKED, 'label' => 'Día Festivo Trabajado', 'requires_evidence' => true],
            ['value' => Authorization::TYPE_SPECIAL, 'label' => 'Especial', 'requires_evidence' => true],
        ];
    }
}
