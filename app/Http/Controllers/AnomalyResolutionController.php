<?php

namespace App\Http\Controllers;

use App\Http\Traits\VerifiesTwoFactor;
use App\Models\AttendanceAnomaly;
use App\Models\Authorization;
use App\Models\Employee;
use App\Services\AnomalyDetectorService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing attendance anomaly resolution.
 */
class AnomalyResolutionController extends Controller
{
    use VerifiesTwoFactor;

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
                $query->whereHas('employee', function ($q) use ($userEmployee) {
                    $q->where('supervisor_id', $userEmployee->id);
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

        $anomalies = $query->orderByRaw("FIELD(severity, 'critical', 'warning', 'info')")
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
            'linkedIncident',
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

        return Inertia::render('Anomalies/Show', [
            'anomaly' => $anomaly,
            'relatedAnomalies' => $relatedAnomalies,
            'relatedAuthorizations' => $relatedAuthorizations,
            'can' => [
                'resolve' => $user->hasPermissionTo('anomalies.resolve'),
                'dismiss' => $user->hasPermissionTo('anomalies.dismiss'),
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
            'resolution_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $anomaly->resolve($user, $validated['resolution_notes'] ?? null);

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
            'resolution_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $anomaly->dismiss($user, $validated['resolution_notes'] ?? null);

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

        $anomaly->linkToAuthorization($authorization);

        $this->updateRecordAnomalyCount($anomaly->attendance_record_id);

        return redirect()->back()->with('success', 'Anomalia vinculada a autorizacion.');
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
                ? Employee::where('supervisor_id', $userEmployee->id)->pluck('id')->toArray()
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
            $anomaly->resolve($user, $validated['resolution_notes'] ?? null);
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
                ? Employee::where('supervisor_id', $userEmployee->id)->pluck('id')->toArray()
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
            $anomaly->dismiss($user, $validated['resolution_notes'] ?? null);
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
