<?php

namespace App\Http\Controllers;

use App\Http\Traits\VerifiesTwoFactor;
use App\Models\AttendanceAnomaly;
use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\SystemSetting;
use App\Services\OvertimeRoundingService;
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
            ->when($request->department, function ($q, $department) {
                $q->whereHas('employee', function ($e) use ($department) {
                    $e->where('department_id', $department);
                });
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

        // Per-row approve capability drives "Aprobar parcial" (pending) and
        // "Modificar aprobación" (already approved) in the list. The policy
        // already accounts for pending-vs-admin, ownership, team scope and the
        // paid lock, so the frontend doesn't have to duplicate that logic.
        $authorizations->through(function ($authorization) use ($user) {
            $authorization->can_approve = $user->can('approve', $authorization);

            return $authorization;
        });

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

        // Get employees for filter (scoped) — only those who have at least one
        // authorization, so the dropdown stays short and relevant.
        $employeesQuery = Employee::active()
            ->orderBy('full_name')
            ->whereExists(function ($q) {
                $q->select(\Illuminate\Support\Facades\DB::raw(1))
                    ->from('authorizations')
                    ->whereColumn('authorizations.employee_id', 'employees.id');
            });
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
            'employees' => $employeesQuery->get(['id', 'full_name', 'employee_number']),
            'departments' => Department::active()->orderBy('name')->get(['id', 'name']),
            'pendingCount' => $pendingCount,
            'filters' => $request->only(['status', 'type', 'employee', 'department', 'search', 'from_date', 'to_date']),
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

        $employees = $employeesQuery->get(['id', 'full_name', 'employee_number', 'department_id', 'schedule_id', 'schedule_overrides']);
        $this->appendActiveCompensationTypeIds($employees);
        $this->appendScheduleByDay($employees);
        $employees->each(fn($e) => $e->makeHidden(['schedule_id', 'schedule_overrides']));

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
            'departments' => \App\Models\Department::active()->orderBy('name')->get(['id', 'name']),
            'holidays' => \App\Models\Holiday::pluck('date')->map(fn($d) => $d->toDateString())->values()->all(),
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
            'hours' => $this->hoursRules($request->input('compensation_type_id'), $request->input('type')),
            'reason' => ['required', 'string', 'max:1000'],
            'evidence' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'anomaly_id' => ['nullable', 'exists:attendance_anomalies,id'],
        ]);

        $anomalyId = $validated['anomaly_id'] ?? null;
        unset($validated['anomaly_id']);

        // Block per-hour authorizations that are 0h (the company rule rounds <30
        // min to 0 — nothing to authorize) or whose range falls inside the
        // employee's regular schedule on a non-holiday.
        if (in_array($validated['type'], [Authorization::TYPE_OVERTIME, Authorization::TYPE_NIGHT_SHIFT], true)) {
            // Pre-calc hours when not given, so the 0-hour check sees the real value.
            $hoursPreview = $validated['hours'] ?? null;
            if (! empty($validated['start_time']) && ! empty($validated['end_time']) && empty($hoursPreview)) {
                $minutes = abs((int) Carbon::parse($validated['start_time'])->diffInMinutes(Carbon::parse($validated['end_time'])));
                $hoursPreview = (new OvertimeRoundingService())->roundMinutes($minutes);
            }
            if ((float) $hoursPreview <= 0) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'hours' => 'No se pueden autorizar 0 horas. El rango es muy corto (menos de 30 min se redondea a 0). Ajusta los tiempos.',
                ]);
            }
            $emp = Employee::with('schedule')->find($validated['employee_id']);
            if ($emp && $this->overlapsWorkSchedule($emp, $validated['date'], $validated['start_time'] ?? null, $validated['end_time'] ?? null)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'start_time' => 'Las horas seleccionadas chocan con el horario de trabajo del empleado. No se autoriza tiempo dentro de su jornada (salvo días festivos).',
                ]);
            }
        }

        $validated['requested_by'] = Auth::id();
        $validated['is_pre_authorization'] = Carbon::parse($validated['date'])->isFuture()
            || Carbon::parse($validated['date'])->isToday();

        // Calculate hours if start and end time provided
        if (! empty($validated['start_time']) && ! empty($validated['end_time']) && empty($validated['hours'])) {
            $start = Carbon::parse($validated['start_time']);
            $end = Carbon::parse($validated['end_time']);
            $validated['hours'] = abs($start->diffInMinutes($end)) / 60;
        }

        // Handle file upload
        if ($request->hasFile('evidence')) {
            $validated['evidence_path'] = $request->file('evidence')->store('authorizations', 'public');
        }
        unset($validated['evidence']);

        $authorization = Authorization::create($validated);
        $this->autoApproveIfDetected($authorization);

        if ($anomalyId) {
            $anomaly = AttendanceAnomaly::find($anomalyId);
            if ($anomaly && $anomaly->employee_id === $authorization->employee_id && $anomaly->status === AttendanceAnomaly::STATUS_OPEN) {
                $anomaly->linkToAuthorization($authorization);
            }
        }

        $msg = $authorization->fresh()->status === Authorization::STATUS_APPROVED
            ? 'Autorización creada y auto-aprobada (coincide con las checadas).'
            : 'Autorización creada exitosamente.';

        return redirect()->route('authorizations.index')->with('success', $msg);
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

        $employees = $employeesQuery->get(['id', 'full_name', 'employee_number', 'department_id', 'schedule_id', 'schedule_overrides']);
        $this->appendActiveCompensationTypeIds($employees);
        $this->appendScheduleByDay($employees);
        $employees->each(fn($e) => $e->makeHidden(['schedule_id', 'schedule_overrides']));

        return Inertia::render('Authorizations/CreateBulk', [
            'employees' => $employees,
            'types' => $this->getAuthorizationTypes(),
            'departments' => \App\Models\Department::active()->get(['id', 'name']),
            'departmentHeads' => Employee::active()
                ->whereHas('position', fn($q) => $q->whereNotNull('supervisor_position_id'))
                ->orderBy('full_name')
                ->get(['id', 'full_name', 'department_id']),
            'holidays' => \App\Models\Holiday::pluck('date')->map(fn($d) => $d->toDateString())->values()->all(),
        ]);
    }

    /**
     * Store bulk authorizations for multiple employees.
     */
    public function storeBulk(Request $request): RedirectResponse
    {
        $this->authorize('create', Authorization::class);

        $hoursRules = $this->hoursRules($request->input('compensation_type_id'), $request->input('type'));

        $validated = $request->validate([
            'type' => ['required', Rule::in([
                Authorization::TYPE_OVERTIME,
                Authorization::TYPE_NIGHT_SHIFT,
                Authorization::TYPE_HOLIDAY_WORKED,
                Authorization::TYPE_SPECIAL,
            ])],
            'compensation_type_id' => ['nullable', 'exists:compensation_types,id'],
            'reason' => ['required', 'string', 'max:1000'],
            'department_head_id' => ['nullable', 'exists:employees,id'],

            // Per-hour types submit an explicit list of rows (one auth per row).
            'entries' => ['nullable', 'array'],
            'entries.*.employee_id' => ['required_with:entries', 'exists:employees,id'],
            'entries.*.date' => ['required_with:entries', 'date'],
            'entries.*.start_time' => ['nullable', 'date_format:H:i'],
            'entries.*.end_time' => ['nullable', 'date_format:H:i'],
            'entries.*.hours' => $hoursRules,

            // Per-day / one-time / fallback: single (employee_ids, date) shape.
            'employee_ids' => ['required_without:entries', 'array'],
            'employee_ids.*' => ['exists:employees,id'],
            'date' => ['required_without:entries', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'hours' => $hoursRules,
        ]);

        $bulkGroupId = 'bulk_' . now()->format('YmdHis') . '_' . Auth::id();
        $count = 0;
        $autoApprovedCount = 0;

        $rounder = new OvertimeRoundingService();

        // Per-hour types (overtime / velada) can't be authorized inside the
        // employee's scheduled work hours — those hours are their obligation,
        // not extras. Exception: official holidays, where any worked hour
        // qualifies. Collect conflicts up front so we reject the whole batch
        // with row-precise errors before creating anything.
        if (! empty($validated['entries'])
            && in_array($validated['type'], [Authorization::TYPE_OVERTIME, Authorization::TYPE_NIGHT_SHIFT], true)
        ) {
            $empCache = Employee::with('schedule')
                ->whereIn('id', array_unique(array_column($validated['entries'], 'employee_id')))
                ->get()
                ->keyBy('id');
            $dates = array_unique(array_column($validated['entries'], 'date'));
            $holidaySet = \App\Models\Holiday::whereIn('date', $dates)
                ->pluck('date')
                ->map(fn($d) => $d->toDateString())
                ->all();
            $holidaySet = array_flip($holidaySet);

            $conflicts = [];
            foreach ($validated['entries'] as $i => $entry) {
                $emp = $empCache->get($entry['employee_id']);
                if (! $emp) continue;
                // Trust an explicitly chosen hours value (the user can override the
                // escalonado ladder in the UI). Only derive from the time range when
                // no hours were given, so we still catch 0-hour rows from <30 min ranges.
                $entryHours = (isset($entry['hours']) && $entry['hours'] !== '' && $entry['hours'] !== null)
                    ? (float) $entry['hours']
                    : null;
                if ($entryHours === null && ! empty($entry['start_time']) && ! empty($entry['end_time'])) {
                    // abs() because Carbon 3 diffInMinutes is signed — direction
                    // matters and we always want the magnitude here.
                    $entryMins = abs((int) Carbon::parse($entry['start_time'])->diffInMinutes(Carbon::parse($entry['end_time'])));
                    $entryHours = $rounder->roundMinutes($entryMins);
                }
                if ((float) $entryHours <= 0) {
                    $conflicts["entries.{$i}"] = "Fila {$emp->full_name} ({$entry['date']}): el rango es menor a 30 min y se redondea a 0 horas. No se puede autorizar.";
                    continue;
                }
                if (isset($holidaySet[$entry['date']])) continue;
                if ($this->overlapsWorkSchedule($emp, $entry['date'], $entry['start_time'] ?? null, $entry['end_time'] ?? null)) {
                    $conflicts["entries.{$i}"] = "Las horas chocan con el horario de trabajo de {$emp->full_name} el {$entry['date']}. No se autoriza tiempo dentro de su jornada (salvo días festivos).";
                }
            }
            if (! empty($conflicts)) {
                throw \Illuminate\Validation\ValidationException::withMessages($conflicts);
            }
        }

        if (! empty($validated['entries'])) {
            foreach ($validated['entries'] as $entry) {
                $startTime = $entry['start_time'] ?? null;
                $endTime = $entry['end_time'] ?? null;

                // Trust an explicitly chosen hours value (the escalonado select lets
                // the user override the auto-calc). Fall back to deriving it from the
                // time range with the company rounding ladder only when none was sent.
                $hours = (isset($entry['hours']) && $entry['hours'] !== '' && $entry['hours'] !== null)
                    ? (float) $entry['hours']
                    : null;
                if ($hours === null && ! empty($startTime) && ! empty($endTime)) {
                    $minutes = abs((int) Carbon::parse($startTime)->diffInMinutes(Carbon::parse($endTime)));
                    $hours = $rounder->roundMinutes($minutes);
                }

                $isPreAuth = Carbon::parse($entry['date'])->isFuture()
                    || Carbon::parse($entry['date'])->isToday();

                $auth = Authorization::create([
                    'employee_id' => $entry['employee_id'],
                    'requested_by' => Auth::id(),
                    'type' => $validated['type'],
                    'compensation_type_id' => $validated['compensation_type_id'] ?? null,
                    'date' => $entry['date'],
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'hours' => $hours,
                    'reason' => $validated['reason'],
                    'is_pre_authorization' => $isPreAuth,
                    'department_head_id' => $validated['department_head_id'] ?? null,
                    'is_bulk_generated' => true,
                    'bulk_group_id' => $bulkGroupId,
                ]);
                $this->autoApproveIfDetected($auth);
                if ($auth->fresh()->status === Authorization::STATUS_APPROVED) {
                    $autoApprovedCount++;
                }
                $count++;
            }
        } else {
            $globalHours = $validated['hours'] ?? null;
            if (! empty($validated['start_time']) && ! empty($validated['end_time']) && empty($globalHours)) {
                $start = Carbon::parse($validated['start_time']);
                $end = Carbon::parse($validated['end_time']);
                $globalHours = abs($start->diffInMinutes($end)) / 60;
            }

            $isPreAuthorization = Carbon::parse($validated['date'])->isFuture()
                || Carbon::parse($validated['date'])->isToday();

            foreach ($validated['employee_ids'] as $employeeId) {
                $auth = Authorization::create([
                    'employee_id' => $employeeId,
                    'requested_by' => Auth::id(),
                    'type' => $validated['type'],
                    'compensation_type_id' => $validated['compensation_type_id'] ?? null,
                    'date' => $validated['date'],
                    'start_time' => $validated['start_time'] ?? null,
                    'end_time' => $validated['end_time'] ?? null,
                    'hours' => $globalHours,
                    'reason' => $validated['reason'],
                    'is_pre_authorization' => $isPreAuthorization,
                    'department_head_id' => $validated['department_head_id'] ?? null,
                    'is_bulk_generated' => true,
                    'bulk_group_id' => $bulkGroupId,
                ]);
                $this->autoApproveIfDetected($auth);
                if ($auth->fresh()->status === Authorization::STATUS_APPROVED) {
                    $autoApprovedCount++;
                }
                $count++;
            }
        }

        $msg = $autoApprovedCount > 0
            ? "Se crearon {$count} autorizaciones, {$autoApprovedCount} auto-aprobadas (coinciden con checadas)."
            : "Se crearon {$count} autorizaciones exitosamente.";

        return redirect()->route('authorizations.index')->with('success', $msg);
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
            'hours' => $this->hoursRules($request->input('compensation_type_id'), $request->input('type')),
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        // Calculate hours if start and end time provided
        if (! empty($validated['start_time']) && ! empty($validated['end_time']) && empty($validated['hours'])) {
            $start = Carbon::parse($validated['start_time']);
            $end = Carbon::parse($validated['end_time']);
            $validated['hours'] = abs($start->diffInMinutes($end)) / 60;
        }

        // Same per-hour guards as store(): hours must be > 0 and the range
        // can't fall inside the employee's regular schedule on a non-holiday.
        if (in_array($validated['type'], [Authorization::TYPE_OVERTIME, Authorization::TYPE_NIGHT_SHIFT], true)) {
            if ((float) ($validated['hours'] ?? 0) <= 0) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'hours' => 'No se pueden autorizar 0 horas. Ajusta el rango o cambia el tipo de autorización.',
                ]);
            }
            $emp = Employee::with('schedule')->find($validated['employee_id']);
            if ($emp && $this->overlapsWorkSchedule($emp, $validated['date'], $validated['start_time'] ?? null, $validated['end_time'] ?? null)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'start_time' => 'Las horas seleccionadas chocan con el horario de trabajo del empleado. No se autoriza tiempo dentro de su jornada (salvo días festivos).',
                ]);
            }
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

        $user = Auth::user();
        $isAdmin = $user->hasPermissionTo('authorizations.view_all');

        // A paid authorization is locked. Non-admins can only act on pending ones;
        // admins may modify an existing approval (partial / post-hoc adjustment).
        if ($authorization->isPaid()) {
            return redirect()->back()->with('error', 'No se puede modificar una autorización ya pagada.');
        }
        if (! $authorization->isPending() && ! $isAdmin) {
            return redirect()->back()->with('error', 'Esta autorizacion ya fue procesada.');
        }

        // Optional partial-approval hours override. Reuses the type-aware rules
        // (per_hour caps at 24; per_day/one_time allow larger quantities). An
        // empty value keeps the requested/detected hours untouched.
        $validated = $request->validate([
            'hours' => $this->hoursRules($authorization->compensation_type_id, $authorization->type),
        ]);
        $overrideHours = (isset($validated['hours']) && $validated['hours'] !== null && $validated['hours'] !== '')
            ? (float) $validated['hours']
            : null;

        $authorization->approve($user, $overrideHours);

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
     * Bulk approve multiple authorizations.
     *
     * Each authorization is checked individually against the policy and skipped
     * silently if the user lacks permission or it is no longer pending. The
     * 2FA code (when enabled) is verified once for the whole batch.
     */
    public function bulkApprove(Request $request, ZktecoSyncService $syncService): RedirectResponse
    {
        $this->verifyTwoFactorCode($request);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:authorizations,id'],
        ]);

        $approved = 0;
        $skipped = 0;
        $user = Auth::user();
        $authorizations = Authorization::whereIn('id', $validated['ids'])->get();

        foreach ($authorizations as $authorization) {
            if (! $user->can('approve', $authorization) || ! $authorization->isPending()) {
                $skipped++;
                continue;
            }

            $authorization->approve($user);

            if ($authorization->attendance_record_id) {
                $syncService->recalculateAttendanceRecord($authorization->attendanceRecord);
            } else {
                $record = AttendanceRecord::where('employee_id', $authorization->employee_id)
                    ->where('work_date', $authorization->date)
                    ->first();
                if ($record) {
                    $syncService->recalculateAttendanceRecord($record);
                }
            }

            $this->autoResolveAnomalies($authorization);
            $approved++;
        }

        $msg = $skipped > 0
            ? "Se aprobaron {$approved} autorizaciones ({$skipped} omitidas)."
            : "Se aprobaron {$approved} autorizaciones.";

        return redirect()->back()->with('success', $msg);
    }

    /**
     * Bulk reject multiple authorizations with a shared reason.
     */
    public function bulkReject(Request $request): RedirectResponse
    {
        $this->verifyTwoFactorCode($request);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:authorizations,id'],
            'rejection_reason' => ['required', 'string', 'max:500'],
        ]);

        $rejected = 0;
        $skipped = 0;
        $user = Auth::user();
        $authorizations = Authorization::whereIn('id', $validated['ids'])->get();

        foreach ($authorizations as $authorization) {
            if (! $user->can('reject', $authorization) || ! $authorization->isPending()) {
                $skipped++;
                continue;
            }
            $authorization->reject($user, $validated['rejection_reason']);
            $rejected++;
        }

        $msg = $skipped > 0
            ? "Se rechazaron {$rejected} autorizaciones ({$skipped} omitidas)."
            : "Se rechazaron {$rejected} autorizaciones.";

        return redirect()->back()->with('success', $msg);
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
     * Suggest authorization rows for multiple employees over a date range.
     *
     * Returns one row per (employee, date, segment) for any detected overtime
     * or velada within the inclusive date range. Same employee can appear
     * multiple times (one row per day with detected extra time, plus separate
     * rows for early-arrival vs late-exit overtime segments when both apply).
     */
    public function suggestBulk(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->authorize('create', Authorization::class);

        $validated = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['required', 'integer', 'exists:employees,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'type' => ['required', 'string'],
            'compensation_type_id' => ['nullable', 'exists:compensation_types,id'],
        ]);

        // Per-day pull rules (meal/weekend) pull from check-ins differently than
        // overtime/velada: one entry per qualifying day, no time segments. Any
        // other type only supports the per-hour overtime/velada detection.
        $compType = ! empty($validated['compensation_type_id'])
            ? CompensationType::find($validated['compensation_type_id'])
            : null;
        $isMeal = $compType && $compType->hasMealPullRule();
        $isWeekend = $compType && $compType->hasWeekendPullRule();
        $isPull = $isMeal || $isWeekend;

        if (! $isPull && ! in_array($validated['type'], [Authorization::TYPE_OVERTIME, Authorization::TYPE_NIGHT_SHIFT], true)) {
            return response()->json([
                'suggestions' => [],
                'message' => 'Este tipo no se puede jalar desde checadas.',
            ], 422);
        }

        // Hard cap to avoid runaway queries (e.g., user picks a full year).
        $startDate = Carbon::parse($validated['start_date']);
        $endDate = Carbon::parse($validated['end_date']);
        if ($startDate->diffInDays($endDate) > 31) {
            return response()->json([
                'suggestions' => [],
                'message' => 'El rango no puede exceder 31 días.',
            ], 422);
        }

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

        $employees = Employee::whereIn('id', $validated['employee_ids'])->get()->keyBy('id');
        $records = AttendanceRecord::whereIn('employee_id', $validated['employee_ids'])
            ->whereBetween('work_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->get()
            ->groupBy('employee_id');

        // Pull-rule prerequisites: configurable hours threshold (meal only) + a
        // set of days that already have a (non-rejected) entry for this concept,
        // so a second "Cargar desde checadas" doesn't duplicate entries.
        $mealThreshold = $isMeal ? (float) SystemSetting::get('cena_min_worked_hours', 12) : 0.0;
        $existingPull = [];
        if ($isPull) {
            $existingPull = Authorization::where('compensation_type_id', $compType->id)
                ->whereIn('employee_id', $validated['employee_ids'])
                ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
                ->where('status', '!=', Authorization::STATUS_REJECTED)
                ->get(['employee_id', 'date'])
                ->mapWithKeys(fn($a) => [$a->employee_id . '|' . Carbon::parse($a->date)->toDateString() => true])
                ->all();
        }

        $rows = [];
        foreach ($employees as $employee) {
            if ($allowed !== null && ! in_array($employee->id, $allowed, true)) {
                continue;
            }
            $empRecords = $records->get($employee->id, collect())->keyBy(fn($r) => Carbon::parse($r->work_date)->toDateString());

            $cursor = $startDate->copy();
            while ($cursor->lte($endDate)) {
                $dateStr = $cursor->toDateString();
                $record = $empRecords->get($dateStr);

                if ($isPull) {
                    if ($record && $record->check_in && ! isset($existingPull[$employee->id . '|' . $dateStr])) {
                        $seg = $isMeal
                            ? $this->buildMealSegment($record, $mealThreshold)
                            : $this->buildWeekendSegment($record);
                        if ($seg) {
                            $rows[] = [
                                'employee_id' => $employee->id,
                                'employee_name' => $employee->full_name,
                                'employee_number' => $employee->employee_number,
                                'date' => $dateStr,
                            ] + $seg;
                        }
                    }
                } elseif ($record && $record->check_in && $record->check_out) {
                    $segments = $this->buildSuggestionSegments($employee, $dateStr, $validated['type'], $record);
                    foreach ($segments as $seg) {
                        $rows[] = [
                            'employee_id' => $employee->id,
                            'employee_name' => $employee->full_name,
                            'employee_number' => $employee->employee_number,
                            'date' => $dateStr,
                        ] + $seg;
                    }
                }
                $cursor->addDay();
            }
        }

        $eligibleEmployeeCount = count(array_unique(array_column($rows, 'employee_id')));

        return response()->json([
            'suggestions' => $rows,
            'eligible_count' => count($rows),
            'eligible_employee_count' => $eligibleEmployeeCount,
            'skipped_count' => $employees->count() - $eligibleEmployeeCount,
        ]);
    }

    /**
     * Build a single-employee suggestion (legacy single-segment shape).
     *
     * Picks the most representative segment for the single-authorization form:
     * for overtime it prefers the late-exit segment over the early-arrival one
     * when both exist, since late OT is far more common.
     */
    private function buildSuggestion(Employee $employee, string $date, string $type, AttendanceRecord $record): array
    {
        $segments = $this->buildSuggestionSegments($employee, $date, $type, $record);
        if (empty($segments)) {
            $msg = $type === Authorization::TYPE_NIGHT_SHIFT
                ? 'Sin horas de velada detectadas.'
                : 'Sin tiempo extra detectado.';
            return ['found' => false, 'message' => $msg];
        }

        // Prefer late-exit segment if present, else first available.
        $primary = collect($segments)->firstWhere('kind', 'late') ?? $segments[0];
        return ['found' => true] + $primary;
    }

    /**
     * Build all suggestion segments for an (employee, date, type) tuple.
     *
     * Returns an array of segments. Each segment is one authorizable block:
     * - overtime: 'late' (after scheduled exit) and/or 'early' (before scheduled entry)
     * - night_shift: 'velada' (single block back-extended from check_out)
     */
    private function buildSuggestionSegments(Employee $employee, string $date, string $type, AttendanceRecord $record): array
    {
        if ($type === Authorization::TYPE_NIGHT_SHIFT) {
            return $this->buildVeladaSegments($record);
        }

        $dayName = Carbon::parse($date)->format('l');
        $schedule = $employee->getEffectiveScheduleForDay($dayName);
        return $this->buildOvertimeSegments($record, $schedule, $date);
    }

    /**
     * Overtime segments: detect early-arrival and late-exit minutes separately,
     * round each one using the company's stepped rule, and emit one segment per
     * qualifying chunk. A segment is omitted when it rounds to zero (< 30 min).
     *
     * check_in / check_out are stored as TIME (no date). We anchor them to the
     * record's work_date so comparisons against the schedule's entry/exit don't
     * mix "today" with the historical date.
     */
    private function buildOvertimeSegments(AttendanceRecord $record, ?object $schedule, string $date): array
    {
        $checkIn = Carbon::parse($date . ' ' . $record->check_in);
        $checkOut = Carbon::parse($date . ' ' . $record->check_out);
        $scheduledEntry = $schedule->entry_time ?? null;
        $scheduledExit = $schedule->exit_time ?? null;

        // No schedule reference — fall back to the precomputed overtime_hours
        // and emit a single late-exit-style segment.
        if (! $scheduledEntry && ! $scheduledExit) {
            $rawHours = (float) ($record->overtime_hours ?? 0);
            $minutes = (int) round($rawHours * 60);
            $rounded = $this->roundOvertimeMinutes($minutes);
            if ($rounded <= 0) {
                return [];
            }
            $start = $checkOut->copy()->subMinutes($minutes);
            return [[
                'kind' => 'late',
                'start_time' => $start->format('H:i'),
                'end_time' => $checkOut->format('H:i'),
                'hours' => number_format($rounded, 2, '.', ''),
                'summary' => "Sin horario: {$rounded}h extra (de " . $start->format('H:i') . ' a ' . $checkOut->format('H:i') . ').',
            ]];
        }

        $segments = [];

        if ($scheduledEntry) {
            $scheduledEntryDt = Carbon::parse($date . ' ' . $scheduledEntry);
            if ($checkIn->lt($scheduledEntryDt)) {
                // abs() defensively — Carbon 3 diffInMinutes is signed.
                $earlyMinutes = abs((int) $checkIn->diffInMinutes($scheduledEntryDt));
                $rounded = $this->roundOvertimeMinutes($earlyMinutes);
                if ($rounded > 0) {
                    $segments[] = [
                        'kind' => 'early',
                        'start_time' => $checkIn->format('H:i'),
                        'end_time' => $scheduledEntryDt->format('H:i'),
                        'hours' => number_format($rounded, 2, '.', ''),
                        'summary' => "Entrada {$checkIn->format('H:i')} antes de horario {$scheduledEntry} ({$earlyMinutes} min → {$rounded}h).",
                    ];
                }
            }
        }

        if ($scheduledExit) {
            $scheduledExitDt = Carbon::parse($date . ' ' . $scheduledExit);
            if ($checkOut->gt($scheduledExitDt)) {
                $lateMinutes = abs((int) $scheduledExitDt->diffInMinutes($checkOut));
                $rounded = $this->roundOvertimeMinutes($lateMinutes);
                if ($rounded > 0) {
                    $segments[] = [
                        'kind' => 'late',
                        'start_time' => $scheduledExitDt->format('H:i'),
                        'end_time' => $checkOut->format('H:i'),
                        'hours' => number_format($rounded, 2, '.', ''),
                        'summary' => "Salida {$checkOut->format('H:i')} después de horario {$scheduledExit} ({$lateMinutes} min → {$rounded}h).",
                    ];
                }
            }
        }

        return $segments;
    }

    /**
     * Velada segments.
     *
     * A real velada is a SEPARATE night block: the employee finishes their normal
     * shift (possibly after some overtime), clocks out, then clocks back IN for the
     * velada and OUT when it ends — so the day's punches read as
     * [entrada normal, salida normal/extras, entrada velada, salida velada].
     *
     * We surface that actual velada entry→exit block straight from the day's raw
     * punches. Only when there are no usable punches (legacy records synced before
     * raw_punches was stored) do we fall back to the old behaviour of back-extending
     * a synthetic range from the precomputed velada_hours.
     */
    private function buildVeladaSegments(AttendanceRecord $record): array
    {
        $fromPunches = $this->veladaSegmentFromPunches($record->raw_punches ?? []);
        if ($fromPunches !== null) {
            return [$fromPunches];
        }

        // Fallback: legacy record without raw punches. Back-extend from check_out
        // using the precomputed velada_hours and the same stepped rounding rule.
        $rawHours = (float) ($record->velada_hours ?? 0);
        $minutes = (int) round($rawHours * 60);
        $rounded = $this->roundOvertimeMinutes($minutes);
        if ($rounded <= 0) {
            return [];
        }

        $checkOut = Carbon::parse($record->check_out);
        $start = $checkOut->copy()->subMinutes($minutes);

        return [[
            'kind' => 'velada',
            'start_time' => $start->format('H:i'),
            'end_time' => $checkOut->format('H:i'),
            'hours' => number_format($rounded, 2, '.', ''),
            'summary' => "Velada: {$rounded}h (de " . $start->format('H:i') . " hasta {$checkOut->format('H:i')}).",
        ]];
    }

    /**
     * Extract the velada block (the night re-entry) from a day's raw punches.
     *
     * Punches are stored chronologically as [{time:'HH:MM:SS', type, ...}]. The
     * velada is the last work block of the day, so we take the last two
     * shift-boundary punches (ignoring the lunch break, which is interior to the
     * normal shift) as the velada entry and exit. A velada whose exit time reads
     * earlier than its entry (e.g. 22:00 → 02:00) crossed midnight; its real
     * duration spans into the next day.
     *
     * The block only counts as a velada when its entry lands inside the configured
     * velada window (velada_detection_start_hour..end_hour) or the span crossed
     * midnight — so an ordinary day or a continuous overtime block (no separate
     * re-entry) is not mistaken for one. Returns a suggestion row, or null when the
     * punches don't describe a velada (the caller then falls back to velada_hours).
     *
     * @param  array  $rawPunches  Stored punches for the day, in chronological order.
     */
    private function veladaSegmentFromPunches(array $rawPunches): ?array
    {
        // Keep only shift-boundary punches, in stored (chronological) order. The
        // lunch break sits inside the normal shift and never bounds the velada.
        $points = [];
        foreach ($rawPunches as $p) {
            $type = $p['type'] ?? 'punch';
            if ($type === 'lunch_out' || $type === 'lunch_in') {
                continue;
            }
            $time = $p['time'] ?? null;
            if (! $time) {
                continue;
            }
            $points[] = [
                'hm' => substr($time, 0, 5),
                'min' => ((int) substr($time, 0, 2)) * 60 + (int) substr($time, 3, 2),
                'hour' => (int) substr($time, 0, 2),
            ];
        }

        if (count($points) < 2) {
            return null;
        }

        // The velada is the last work block → its entry/exit are the last two
        // boundary punches (robust to a stray normal-shift punch in the middle).
        $entry = $points[count($points) - 2];
        $exit = $points[count($points) - 1];

        $veladaStart = (int) SystemSetting::get('velada_detection_start_hour', 22);
        $veladaEnd = (int) SystemSetting::get('velada_detection_end_hour', 5);

        $inWindow = function (int $hour) use ($veladaStart, $veladaEnd): bool {
            return $veladaStart <= $veladaEnd
                ? ($hour >= $veladaStart && $hour < $veladaEnd)
                : ($hour >= $veladaStart || $hour < $veladaEnd);
        };

        // exit at/before entry (in minutes-of-day) means the block ran past 00:00.
        $crossesMidnight = $exit['min'] <= $entry['min'];

        // Require a genuine night entry: inside the velada window, or a span that
        // crossed midnight. This rejects normal days and continuous OT (no re-entry).
        if (! $inWindow($entry['hour']) && ! $crossesMidnight) {
            return null;
        }

        $minutes = $crossesMidnight
            ? ($exit['min'] + 1440) - $entry['min']
            : $exit['min'] - $entry['min'];

        $rounded = $this->roundOvertimeMinutes($minutes);
        if ($rounded <= 0) {
            return null;
        }

        $crossNote = $crossesMidnight ? ' del día siguiente' : '';

        return [
            'kind' => 'velada',
            'start_time' => $entry['hm'],
            'end_time' => $exit['hm'],
            'hours' => number_format($rounded, 2, '.', ''),
            'summary' => "Velada: {$rounded}h (entrada {$entry['hm']}, salida {$exit['hm']}{$crossNote}).",
        ];
    }

    /** Delegate the rounding rule to the shared service. */
    private function roundOvertimeMinutes(int $minutes): float
    {
        return (new OvertimeRoundingService())->roundMinutes($minutes);
    }

    /**
     * Meal (Cena) segment: a single per-day entry when the day qualifies for a
     * dinner. A day qualifies if ANY of these hold (the reasons are surfaced so
     * the supervisor can sanity-check before approving):
     *
     *  - Long day: total worked (worked_hours + overtime_hours) >= threshold.
     *  - Velada: the shift crossed midnight (check_out earlier than check_in),
     *    i.e. they worked from the evening into the next day.
     *  - Weekend: worked a Sat/Sun that falls outside their schedule
     *    (is_weekend_work, set during the ZKTeco sync).
     *
     * Returns null when the day doesn't qualify. `hours` is 1 because a Cena is a
     * per_day concept and one qualifying day is one dinner. No start/end times —
     * a dinner isn't a time range. These never auto-approve (special type).
     */
    private function buildMealSegment(AttendanceRecord $record, float $threshold): ?array
    {
        $reasons = [];

        $totalWorked = (float) ($record->worked_hours ?? 0) + (float) ($record->overtime_hours ?? 0);
        if ($threshold > 0 && $totalWorked >= $threshold) {
            $reasons[] = number_format($totalWorked, 2, '.', '') . 'h trabajadas';
        }

        // Midnight crossing: check_in/check_out are TIME-only; anchoring both to
        // the same day, a check_out that lands "before" check_in means the shift
        // ran past 00:00.
        if ($record->check_in && $record->check_out) {
            $checkIn = Carbon::parse($record->check_in);
            $checkOut = Carbon::parse($record->check_out);
            if ($checkOut->lt($checkIn)) {
                $reasons[] = 'velada (cruzó medianoche)';
            }
        }

        if ($record->is_weekend_work) {
            $reasons[] = 'fin de semana';
        }

        if (empty($reasons)) {
            return null;
        }

        return [
            'kind' => 'meal',
            'start_time' => null,
            'end_time' => null,
            'hours' => '1',
            'summary' => 'Cena: ' . implode(', ', $reasons) . '.',
        ];
    }

    /**
     * Weekend segment: a single per-day entry when the employee worked a weekend
     * day outside their schedule (is_weekend_work, set during the ZKTeco sync).
     *
     * Returns null when the day isn't flagged as weekend work. `hours` is 1
     * because the weekend concept is per_day — one worked weekend day is one
     * entry. These never auto-approve (special type).
     */
    private function buildWeekendSegment(AttendanceRecord $record): ?array
    {
        if (! $record->is_weekend_work) {
            return null;
        }

        return [
            'kind' => 'weekend',
            'start_time' => null,
            'end_time' => null,
            'hours' => '1',
            'summary' => 'Fin de semana trabajado.',
        ];
    }

    /**
     * Auto-approve an authorization when its (start, end, hours) exactly match
     * a detected segment for that employee/date. The intent is: if the supervisor
     * loaded the row from real checadas and didn't touch it, the system already
     * agrees with itself — no second human review needed. Manual edits or hand-
     * typed entries that don't match a detected segment stay pending.
     */
    private function autoApproveIfDetected(Authorization $authorization): void
    {
        // Reload so DB column defaults (status='pending') and any cast
        // normalization land on the in-memory model. A just-created instance
        // has status='' (the default isn't backfilled until reload), which
        // would otherwise bail out at the status guard below.
        $authorization->refresh();

        if ($authorization->status !== Authorization::STATUS_PENDING) {
            return;
        }
        if (! in_array($authorization->type, [Authorization::TYPE_OVERTIME, Authorization::TYPE_NIGHT_SHIFT], true)) {
            return;
        }
        // Attendance-pull concepts (Cena, Fin de semana) always stay pending for
        // human review, even if misconfigured onto an overtime/velada type.
        if ($authorization->compensation_type_id
            && optional(CompensationType::find($authorization->compensation_type_id))->pullsFromAttendance()) {
            return;
        }
        if (! $authorization->start_time || ! $authorization->end_time || ! $authorization->hours) {
            return;
        }

        $dateString = $authorization->date instanceof Carbon
            ? $authorization->date->toDateString()
            : (string) $authorization->date;

        $record = AttendanceRecord::where('employee_id', $authorization->employee_id)
            ->where('work_date', $dateString)
            ->first();
        if (! $record || ! $record->check_in || ! $record->check_out) {
            return;
        }

        $employee = $authorization->employee ?? Employee::find($authorization->employee_id);
        if (! $employee) {
            return;
        }

        $segments = $this->buildSuggestionSegments($employee, $dateString, $authorization->type, $record);
        if (empty($segments)) {
            return;
        }

        $authStart = $authorization->start_time->format('H:i');
        $authEnd = $authorization->end_time->format('H:i');
        $authHours = round((float) $authorization->hours, 2);

        foreach ($segments as $seg) {
            if (
                $seg['start_time'] === $authStart
                && $seg['end_time'] === $authEnd
                && abs((float) $seg['hours'] - $authHours) < 0.01
            ) {
                $authorization->update([
                    'status' => Authorization::STATUS_APPROVED,
                    'approved_by' => Auth::id(),
                    'approved_at' => now(),
                ]);
                return;
            }
        }
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
     * Attach a `schedule_by_day` map to each employee so the frontend can detect
     * when a manually entered authorization range overlaps the employee's regular
     * working hours. Shape: { Monday: {entry:'08:00', exit:'17:30'}, ... }.
     *
     * Requires the employees collection to have already loaded `schedule_id` and
     * `schedule_overrides` columns (so the relation can be followed). Eager-loads
     * the `schedule` relation up front to avoid N+1 queries.
     */
    private function appendScheduleByDay(\Illuminate\Database\Eloquent\Collection $employees): void
    {
        if ($employees->isEmpty()) {
            return;
        }
        $employees->load('schedule');

        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $employees->each(function (Employee $emp) use ($days) {
            $map = [];
            foreach ($days as $day) {
                $sch = $emp->getEffectiveScheduleForDay($day);
                if ($sch && ! empty($sch->entry_time) && ! empty($sch->exit_time)) {
                    $map[$day] = [
                        'entry' => substr((string) $sch->entry_time, 0, 5),
                        'exit' => substr((string) $sch->exit_time, 0, 5),
                    ];
                }
            }
            $emp->setAttribute('schedule_by_day', $map);
            $emp->unsetRelation('schedule');
        });
    }

    /**
     * Check if a per-hour authorization range overlaps the employee's regular
     * working hours on that date.
     *
     *  - Returns false on official holidays (working then is not an obligation)
     *  - Returns false when there's no schedule for the day (rest day)
     *  - Otherwise returns true if [start, end] overlaps [entry, exit]
     */
    private function overlapsWorkSchedule(Employee $employee, string $date, ?string $startTime, ?string $endTime): bool
    {
        if (empty($startTime) || empty($endTime)) {
            return false;
        }
        if (\App\Models\Holiday::isHoliday($date)) {
            return false;
        }
        $dayName = Carbon::parse($date)->format('l');
        $schedule = $employee->getEffectiveScheduleForDay($dayName);
        if (! $schedule || empty($schedule->entry_time) || empty($schedule->exit_time)) {
            return false;
        }

        $toMin = fn(string $hhmm) => (int) substr($hhmm, 0, 2) * 60 + (int) substr($hhmm, 3, 2);
        $entry = $toMin(substr((string) $schedule->entry_time, 0, 5));
        $exit = $toMin(substr((string) $schedule->exit_time, 0, 5));
        $start = $toMin(substr($startTime, 0, 5));
        $end = $toMin(substr($endTime, 0, 5));

        // Standard half-open overlap test: [start, end) ∩ [entry, exit) ≠ ∅
        return $start < $exit && $end > $entry;
    }

    /**
     * Build the validation rules for the `hours` field.
     *
     * The `hours` column is overloaded by the compensation type's application
     * mode: per_hour stores real hours worked (capped at 24/day), while per_day
     * stores a day count and one_time stores a unit quantity (e.g. production
     * pieces) — neither of which is bounded by 24. We therefore only apply the
     * 24-hour ceiling when the selected concept is per_hour, falling back to the
     * authorization type when no compensation type is linked. The non-hour cap
     * (999999.99) matches the widened decimal(8,2) column.
     *
     * Args:
     *     compensationTypeId: The selected compensation type id, if any.
     *     type: The authorization type (overtime, night_shift, ...).
     *
     * Returns:
     *     The Laravel validation rule array for the `hours` field.
     */
    private function hoursRules($compensationTypeId, ?string $type): array
    {
        $applicationMode = $compensationTypeId
            ? CompensationType::whereKey($compensationTypeId)->value('application_mode')
            : null;

        $isPerHour = $applicationMode
            ? $applicationMode === CompensationType::APPLICATION_PER_HOUR
            : in_array($type, [Authorization::TYPE_OVERTIME, Authorization::TYPE_NIGHT_SHIFT], true);

        return ['nullable', 'numeric', 'min:0', 'max:' . ($isPerHour ? '24' : '999999.99')];
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
            ->get(['id', 'name', 'code', 'authorization_type', 'application_mode', 'attendance_pull_rule']);

        $types = $compTypes->map(fn(CompensationType $ct) => [
            'value' => $ct->authorization_type ?: 'special',
            'label' => $ct->name,
            'compensation_type_id' => $ct->id,
            'application_mode' => $ct->application_mode,
            'attendance_pull_rule' => $ct->attendance_pull_rule,
            'requires_evidence' => true,
            'group' => 'compensation',
        ])->toArray();

        return $types;
    }
}
