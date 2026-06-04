<?php

namespace App\Http\Controllers;

use App\Http\Traits\VerifiesTwoFactor;
use App\Models\AttendanceAnomaly;
use App\Models\Authorization;
use App\Models\Employee;
use App\Models\Incident;
use App\Services\AnomalyDetectorService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing attendance anomaly resolution.
 */
class AnomalyResolutionController extends Controller
{
    use VerifiesTwoFactor;

    /** Authorization types that can resolve a given anomaly type. */
    private const ANOMALY_AUTH_TYPES = [
        AttendanceAnomaly::TYPE_UNAUTHORIZED_OVERTIME => [Authorization::TYPE_OVERTIME],
        AttendanceAnomaly::TYPE_UNAUTHORIZED_VELADA => [Authorization::TYPE_NIGHT_SHIFT],
        AttendanceAnomaly::TYPE_VELADA_MISSING_CONFIRMATION => [Authorization::TYPE_NIGHT_SHIFT],
    ];

    /** Incident codes (permisos) that can resolve a given anomaly type. */
    private const ANOMALY_INCIDENT_CODES = [
        AttendanceAnomaly::TYPE_EARLY_DEPARTURE => ['PSA'], // Permiso de Salida
        AttendanceAnomaly::TYPE_LATE_ARRIVAL => ['PEN'],    // Permiso de Entrada
    ];

    /**
     * Display anomalies dashboard.
     */
    public function index(Request $request): Response
    {
        $user = Auth::user();

        if (!$user->hasPermissionTo('anomalies.view_all') && !$user->hasPermissionTo('anomalies.view_team')) {
            abort(403);
        }

        $query = AttendanceAnomaly::with(['employee.department', 'attendanceRecord', 'resolvedByUser']);

        // Permission-based filtering
        if (!$user->hasPermissionTo('anomalies.view_all')) {
            $userEmployee = $user->employee;
            if ($userEmployee) {
                $allowedIds = $userEmployee->allSubordinateIds();
                $query->whereHas('employee', function ($q) use ($allowedIds) {
                    $q->whereIn('id', $allowedIds);
                });
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        // Apply filters
        $query->when($request->status, fn($q, $status) => $q->where('status', $status))
            ->when($request->severity, fn($q, $severity) => $q->where('severity', $severity))
            ->when($request->anomaly_type, fn($q, $type) => $q->where('anomaly_type', $type))
            ->when($request->employee, fn($q, $emp) => $q->where('employee_id', $emp))
            ->when($request->department, function ($q, $dept) {
                $q->whereHas('employee', fn($e) => $e->where('department_id', $dept));
            })
            ->when($request->from_date, fn($q, $d) => $q->where('work_date', '>=', $d))
            ->when($request->to_date, fn($q, $d) => $q->where('work_date', '<=', $d))
            ->when($request->search, function ($q, $search) {
                $q->whereHas('employee', fn($e) => $e->where('full_name', 'like', "%{$search}%"));
            });

        // Default to open if no status filter
        if (!$request->has('status') || $request->status === '') {
            $query->where('status', 'open');
        }

        $anomalies = $query->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 WHEN 'info' THEN 3 ELSE 4 END")
            ->orderBy('work_date', 'desc')
            ->paginate(20)
            ->withQueryString();

        // Stats
        $stats = [
            'open' => AttendanceAnomaly::open()->count(),
            'critical' => AttendanceAnomaly::open()->ofSeverity('critical')->count(),
            'warning' => AttendanceAnomaly::open()->ofSeverity('warning')->count(),
            'info' => AttendanceAnomaly::open()->ofSeverity('info')->count(),
        ];

        return Inertia::render('Anomalies/Index', [
            'anomalies' => $anomalies,
            'stats' => $stats,
            'filters' => $request->only(['status', 'severity', 'anomaly_type', 'employee', 'department', 'from_date', 'to_date', 'search']),
            'employees' => Employee::active()->orderBy('full_name')->get(['id', 'full_name']),
            'departments' => \App\Models\Department::active()->get(['id', 'name']),
            'anomalyTypes' => $this->getAnomalyTypes(),
            'can' => [
                'resolve' => $user->hasPermissionTo('anomalies.resolve'),
                'dismiss' => $user->hasPermissionTo('anomalies.dismiss'),
                'createAuthorization' => $user->hasPermissionTo('authorizations.create'),
                'editAttendance' => $user->hasPermissionTo('attendance.edit'),
            ],
        ]);
    }

    /**
     * Display a specific anomaly with full details.
     */
    public function show(AttendanceAnomaly $anomaly): Response
    {
        $user = Auth::user();

        if (!$user->hasPermissionTo('anomalies.view_all') && !$user->hasPermissionTo('anomalies.view_team')) {
            abort(403);
        }

        $anomaly->load([
            'employee.department',
            'employee.schedule',
            'attendanceRecord',
            'resolvedByUser',
            'linkedAuthorization.approvedBy',
            'linkedIncident.incidentType',
        ]);

        // Get related anomalies for the same employee/date
        $relatedAnomalies = AttendanceAnomaly::where('employee_id', $anomaly->employee_id)
            ->where('work_date', $anomaly->work_date)
            ->where('id', '!=', $anomaly->id)
            ->get();

        // Get related authorizations for the same employee/date
        $relatedAuthorizations = Authorization::where('employee_id', $anomaly->employee_id)
            ->where('date', $anomaly->work_date)
            ->get();

        $linkables = $this->buildLinkables($anomaly);

        return Inertia::render('Anomalies/Show', [
            'anomaly' => $anomaly,
            'relatedAnomalies' => $relatedAnomalies,
            'relatedAuthorizations' => $relatedAuthorizations,
            'linkableAuthorizations' => $linkables['authorizations'],
            'linkableIncidents' => $linkables['incidents'],
            'can' => [
                'resolve' => $user->hasPermissionTo('anomalies.resolve'),
                'dismiss' => $user->hasPermissionTo('anomalies.dismiss'),
                'createAuthorization' => $user->hasPermissionTo('authorizations.create'),
                'editAttendance' => $user->hasPermissionTo('attendance.edit'),
            ],
        ]);
    }

    /**
     * Resolve an anomaly.
     */
    public function resolve(Request $request, AttendanceAnomaly $anomaly): RedirectResponse
    {
        $user = Auth::user();
        if (!$user->hasPermissionTo('anomalies.resolve')) {
            abort(403);
        }

        $this->verifyTwoFactorCode($request);

        $validated = $request->validate([
            'resolution_method' => ['required', Rule::in([
                AttendanceAnomaly::METHOD_JUSTIFIED,
                AttendanceAnomaly::METHOD_FALSE_POSITIVE,
            ])],
            'resolution_notes' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $anomaly->resolve($user, $validated['resolution_notes'], $validated['resolution_method']);

        // Update attendance record anomaly count
        $this->updateRecordAnomalyCount($anomaly->attendance_record_id);

        return redirect()->back()->with('success', 'Anomalia resuelta.');
    }

    /**
     * Dismiss an anomaly.
     */
    public function dismiss(Request $request, AttendanceAnomaly $anomaly): RedirectResponse
    {
        $user = Auth::user();
        if (!$user->hasPermissionTo('anomalies.dismiss')) {
            abort(403);
        }

        $this->verifyTwoFactorCode($request);

        $validated = $request->validate([
            'resolution_notes' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $anomaly->dismiss($user, $validated['resolution_notes'], AttendanceAnomaly::METHOD_FALSE_POSITIVE);

        $this->updateRecordAnomalyCount($anomaly->attendance_record_id);

        return redirect()->back()->with('success', 'Anomalia descartada.');
    }

    /**
     * Link anomaly to an existing authorization.
     */
    public function linkAuthorization(Request $request, AttendanceAnomaly $anomaly): RedirectResponse
    {
        $user = Auth::user();
        if (!$user->hasPermissionTo('anomalies.resolve')) {
            abort(403);
        }

        $validated = $request->validate([
            'authorization_id' => ['required', 'exists:authorizations,id'],
        ]);

        $authorization = Authorization::findOrFail($validated['authorization_id']);

        // Verify authorization belongs to the same employee as the anomaly
        if ($authorization->employee_id !== $anomaly->employee_id) {
            return redirect()->back()->with('error', 'La autorizacion pertenece a otro empleado.');
        }
        // Only an approved/paid authorization actually justifies the anomaly;
        // a pending/rejected one would resolve it prematurely.
        if (! in_array($authorization->status, [Authorization::STATUS_APPROVED, Authorization::STATUS_PAID], true)) {
            return redirect()->back()->with('error', 'Solo se puede vincular una autorizacion aprobada.');
        }

        $anomaly->linkToAuthorization($authorization);

        $this->updateRecordAnomalyCount($anomaly->attendance_record_id);

        return redirect()->back()->with('success', 'Anomalia vinculada a autorizacion.');
    }

    /**
     * Link anomaly to an approved incident/permission (e.g. PSA/PEN).
     *
     * No 2FA — consistent with linkAuthorization (a linking action that
     * references an already-approved document, not a destructive resolve).
     */
    public function linkIncident(Request $request, AttendanceAnomaly $anomaly): RedirectResponse
    {
        $user = Auth::user();
        if (!$user->hasPermissionTo('anomalies.resolve')) {
            abort(403);
        }

        // Only anomaly types with a permiso mapping can be justified by an
        // incident (late_arrival -> PEN, early_departure -> PSA). Any other
        // type (e.g. schedule_deviation) has no semantically valid permit.
        $allowedCodes = self::ANOMALY_INCIDENT_CODES[$anomaly->anomaly_type] ?? null;
        if (! $allowedCodes) {
            return redirect()->back()->with('error', 'Este tipo de anomalia no admite vinculacion con permisos.');
        }

        $validated = $request->validate([
            'incident_id' => ['required', 'exists:incidents,id'],
        ]);

        $incident = Incident::with('incidentType')->findOrFail($validated['incident_id']);

        if ($incident->employee_id !== $anomaly->employee_id) {
            return redirect()->back()->with('error', 'El permiso pertenece a otro empleado.');
        }
        if ($incident->status !== 'approved') {
            return redirect()->back()->with('error', 'El permiso no esta aprobado.');
        }
        if (! in_array($incident->incidentType?->code, $allowedCodes, true)) {
            return redirect()->back()->with('error', 'El permiso no corresponde a este tipo de anomalia.');
        }

        $workDate = Carbon::parse($anomaly->work_date)->toDateString();
        if (Carbon::parse($incident->start_date)->toDateString() > $workDate
            || Carbon::parse($incident->end_date)->toDateString() < $workDate) {
            return redirect()->back()->with('error', 'El permiso no cubre la fecha de la anomalia.');
        }

        $anomaly->linkToIncident($incident, $user);

        $this->updateRecordAnomalyCount($anomaly->attendance_record_id);

        return redirect()->back()->with('success', 'Anomalia vinculada a permiso.');
    }

    /**
     * Linkable authorizations and incidents for an anomaly (JSON).
     *
     * Used by the resolution modal on the index page, which only has the
     * paginated row data — fetching on open avoids inflating every row with
     * per-anomaly queries.
     */
    public function linkables(AttendanceAnomaly $anomaly): JsonResponse
    {
        $user = Auth::user();
        if (!$user->hasPermissionTo('anomalies.resolve')) {
            abort(403);
        }

        return response()->json($this->buildLinkables($anomaly));
    }

    /**
     * Build the linkable approved authorizations and incidents for an anomaly.
     *
     * Authorizations: same employee + date, approved/paid, type matching the
     * anomaly (per ANOMALY_AUTH_TYPES). Incidents: same employee, approved,
     * covering the work_date; filtered to matching permiso codes when the
     * anomaly type maps to one (per ANOMALY_INCIDENT_CODES).
     *
     * @return array{authorizations: \Illuminate\Support\Collection, incidents: \Illuminate\Support\Collection}
     */
    private function buildLinkables(AttendanceAnomaly $anomaly): array
    {
        $typeLabels = [
            Authorization::TYPE_OVERTIME => 'Horas Extra',
            Authorization::TYPE_NIGHT_SHIFT => 'Velada',
            Authorization::TYPE_HOLIDAY_WORKED => 'Festivo Trabajado',
            Authorization::TYPE_SPECIAL => 'Especial',
        ];

        $authTypes = self::ANOMALY_AUTH_TYPES[$anomaly->anomaly_type] ?? [];
        $authorizations = empty($authTypes)
            ? collect()
            : Authorization::where('employee_id', $anomaly->employee_id)
                ->where('date', $anomaly->work_date)
                ->whereIn('type', $authTypes)
                ->whereIn('status', [Authorization::STATUS_APPROVED, Authorization::STATUS_PAID])
                ->get()
                ->map(fn (Authorization $a) => [
                    'id' => $a->id,
                    'label' => ($typeLabels[$a->type] ?? $a->type) . ' #' . $a->id,
                    'detail' => trim(($a->hours ? "{$a->hours}h · " : '') . Carbon::parse($a->date)->format('d/m/Y')),
                ])
                ->values();

        // Only types with a permiso mapping can link an incident; for any other
        // type there is no semantically valid permit, so offer none.
        $incidentCodes = self::ANOMALY_INCIDENT_CODES[$anomaly->anomaly_type] ?? null;
        $incidents = ! $incidentCodes
            ? collect()
            : Incident::where('employee_id', $anomaly->employee_id)
                ->where('status', 'approved')
                ->whereDate('start_date', '<=', $anomaly->work_date)
                ->whereDate('end_date', '>=', $anomaly->work_date)
                ->with('incidentType')
                ->whereHas('incidentType', fn ($q) => $q->whereIn('code', $incidentCodes))
                ->get()
                ->map(fn (Incident $i) => [
                    'id' => $i->id,
                    'label' => ($i->incidentType?->name ?? 'Incidencia') . ' #' . $i->id,
                    'detail' => Carbon::parse($i->start_date)->format('d/m/Y') . ' — ' . Carbon::parse($i->end_date)->format('d/m/Y'),
                ])
                ->values();

        return [
            'authorizations' => $authorizations,
            'incidents' => $incidents,
        ];
    }

    /**
     * Bulk resolve anomalies.
     */
    public function bulkResolve(Request $request): RedirectResponse
    {
        $user = Auth::user();
        if (!$user->hasPermissionTo('anomalies.resolve')) {
            abort(403);
        }

        $this->verifyTwoFactorCode($request);

        $validated = $request->validate([
            'anomaly_ids' => ['required', 'array', 'min:1'],
            'anomaly_ids.*' => ['exists:attendance_anomalies,id'],
            'resolution_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        // Scope anomalies to user's team if not view_all
        $allowedEmployeeIds = null;
        if (!$user->hasPermissionTo('anomalies.view_all')) {
            $userEmployee = $user->employee;
            $allowedEmployeeIds = $userEmployee
                ? $userEmployee->allSubordinateIds()
                : [];
        }

        $recordIds = [];
        $resolved = 0;
        foreach ($validated['anomaly_ids'] as $id) {
            $anomaly = AttendanceAnomaly::find($id);
            if (!$anomaly || $anomaly->status !== 'open') {
                continue;
            }
            if ($allowedEmployeeIds !== null && !in_array($anomaly->employee_id, $allowedEmployeeIds)) {
                continue;
            }
            $anomaly->resolve($user, $validated['resolution_notes'] ?? null, AttendanceAnomaly::METHOD_JUSTIFIED);
            $recordIds[] = $anomaly->attendance_record_id;
            $resolved++;
        }

        foreach (array_unique(array_filter($recordIds)) as $recordId) {
            $this->updateRecordAnomalyCount($recordId);
        }

        return redirect()->back()->with('success', "{$resolved} anomalias resueltas.");
    }

    /**
     * Bulk dismiss anomalies.
     */
    public function bulkDismiss(Request $request): RedirectResponse
    {
        $user = Auth::user();
        if (!$user->hasPermissionTo('anomalies.dismiss')) {
            abort(403);
        }

        $this->verifyTwoFactorCode($request);

        $validated = $request->validate([
            'anomaly_ids' => ['required', 'array', 'min:1'],
            'anomaly_ids.*' => ['exists:attendance_anomalies,id'],
            'resolution_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        // Scope anomalies to user's team if not view_all
        $allowedEmployeeIds = null;
        if (!$user->hasPermissionTo('anomalies.view_all')) {
            $userEmployee = $user->employee;
            $allowedEmployeeIds = $userEmployee
                ? $userEmployee->allSubordinateIds()
                : [];
        }

        $recordIds = [];
        $dismissed = 0;
        foreach ($validated['anomaly_ids'] as $id) {
            $anomaly = AttendanceAnomaly::find($id);
            if (!$anomaly || $anomaly->status !== 'open') {
                continue;
            }
            if ($allowedEmployeeIds !== null && !in_array($anomaly->employee_id, $allowedEmployeeIds)) {
                continue;
            }
            $anomaly->dismiss($user, $validated['resolution_notes'] ?? null, AttendanceAnomaly::METHOD_FALSE_POSITIVE);
            $recordIds[] = $anomaly->attendance_record_id;
            $dismissed++;
        }

        foreach (array_unique(array_filter($recordIds)) as $recordId) {
            $this->updateRecordAnomalyCount($recordId);
        }

        return redirect()->back()->with('success', "{$dismissed} anomalias descartadas.");
    }

    /**
     * Update the anomaly count on an attendance record.
     */
    private function updateRecordAnomalyCount(?int $recordId): void
    {
        if (!$recordId) return;

        $count = AttendanceAnomaly::where('attendance_record_id', $recordId)
            ->where('status', 'open')
            ->count();

        \App\Models\AttendanceRecord::where('id', $recordId)->update([
            'has_anomalies' => $count > 0,
            'anomaly_count' => $count,
        ]);
    }

    /**
     * Get anomaly type options for filters.
     */
    private function getAnomalyTypes(): array
    {
        return [
            ['value' => 'missing_checkout', 'label' => 'Salida no registrada'],
            ['value' => 'missing_checkin', 'label' => 'Entrada no registrada'],
            ['value' => 'unauthorized_overtime', 'label' => 'Horas extra sin autorizar'],
            ['value' => 'unauthorized_velada', 'label' => 'Velada sin autorizar'],
            ['value' => 'velada_missing_confirmation', 'label' => 'Velada sin confirmacion post-medianoche'],
            ['value' => 'excessive_break', 'label' => 'Comida excesiva'],
            ['value' => 'missing_lunch', 'label' => 'Sin checada de comida'],
            ['value' => 'late_arrival', 'label' => 'Retardo significativo'],
            ['value' => 'early_departure', 'label' => 'Salida anticipada'],
            ['value' => 'schedule_deviation', 'label' => 'Desviacion de horario'],
            ['value' => 'duplicate_punches', 'label' => 'Checadas duplicadas'],
        ];
    }
}
