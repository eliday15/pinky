<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\CheckOmission;
use App\Models\Department;
use App\Models\Employee;
use App\Services\PayrollInvalidationService;
use App\Services\ZktecoSyncService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Autorización de omisión de checada (Dani 2026-07-09).
 *
 * Flujo de 2 pasos: el jefe/supervisor del departamento AUTORIZA la omisión con
 * un motivo (al capturarla) y el administrador la APRUEBA. El motivo decide el
 * efecto sobre la asistencia una vez aprobada:
 *   - "Entrega de mercancía" → el día se paga completo (present), sin falta.
 *   - "Otro (especificar)"   → el día se convierte en retardo (late), que cuenta
 *     para el acumulado mensual de retardos → falta.
 */
class CheckOmissionController extends Controller
{
    /**
     * Listado de omisiones (también sirve como reporte de auditoría).
     */
    public function index(Request $request): Response
    {
        $user = Auth::user();

        if (! $user->hasPermissionTo('check_omissions.view_all') && ! $user->hasPermissionTo('check_omissions.view_team')) {
            abort(403);
        }

        $query = CheckOmission::with([
            'employee.department',
            'authorizedBy',
            'approvedBy',
            'rejectedBy',
        ]);

        $this->scopeToViewableEmployees($query, $user);

        $query->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->reason, fn ($q, $reason) => $q->where('reason', $reason))
            ->when($request->employee, fn ($q, $emp) => $q->where('employee_id', $emp))
            ->when($request->department, function ($q, $dept) {
                $q->whereHas('employee', fn ($e) => $e->where('department_id', $dept));
            })
            ->when($request->from_date, fn ($q, $d) => $q->whereDate('work_date', '>=', $d))
            ->when($request->to_date, fn ($q, $d) => $q->whereDate('work_date', '<=', $d))
            ->when($request->search, function ($q, $search) {
                $q->whereHas('employee', fn ($e) => $e->where('full_name', 'like', "%{$search}%"));
            });

        $omissions = $query->orderByDesc('work_date')
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $statsQuery = fn () => $this->scopeToViewableEmployees(CheckOmission::query(), $user);

        return Inertia::render('CheckOmissions/Index', [
            'omissions' => $omissions,
            'stats' => [
                'authorized' => (clone $statsQuery())->where('status', CheckOmission::STATUS_AUTHORIZED)->count(),
                'approved' => (clone $statsQuery())->where('status', CheckOmission::STATUS_APPROVED)->count(),
                'rejected' => (clone $statsQuery())->where('status', CheckOmission::STATUS_REJECTED)->count(),
            ],
            'filters' => $request->only(['status', 'reason', 'employee', 'department', 'from_date', 'to_date', 'search']),
            'employees' => $this->viewableEmployees($user)->get(['id', 'full_name']),
            'departments' => Department::active()->orderBy('name')->get(['id', 'name']),
            'reasonOptions' => CheckOmission::reasonOptions(),
            'can' => [
                'create' => $user->hasPermissionTo('check_omissions.create'),
                'approve' => $user->hasPermissionTo('check_omissions.approve'),
            ],
        ]);
    }

    /**
     * Formulario para capturar (autorizar) una omisión.
     */
    public function create(Request $request): Response
    {
        $user = Auth::user();

        if (! $user->hasPermissionTo('check_omissions.create')) {
            abort(403);
        }

        return Inertia::render('CheckOmissions/Create', [
            'employees' => $this->viewableEmployees($user)
                ->with('department:id,name')
                ->get(['id', 'full_name', 'department_id']),
            'reasonOptions' => CheckOmission::reasonOptions(),
            'prefill' => [
                'employee_id' => $request->integer('employee_id') ?: null,
                'work_date' => $request->date('work_date')?->toDateString(),
            ],
        ]);
    }

    /**
     * Capturar la omisión: el jefe/supervisor la AUTORIZA (paso 1).
     */
    public function store(Request $request): RedirectResponse
    {
        $user = Auth::user();

        if (! $user->hasPermissionTo('check_omissions.create')) {
            abort(403);
        }

        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'work_date' => ['required', 'date'],
            'reason' => ['required', Rule::in(array_keys(CheckOmission::reasonOptions()))],
            'comments' => ['nullable', 'string', 'max:1000'],
        ]);

        // "Otro (especificar)" exige detalle.
        if ($validated['reason'] === CheckOmission::REASON_OTHER && blank($validated['comments'] ?? null)) {
            return back()->withErrors(['comments' => 'Especifica el motivo de la omisión.'])->withInput();
        }

        $employee = Employee::findOrFail($validated['employee_id']);
        $this->authorizeEmployeeAccess($employee, $user);

        $workDate = Carbon::parse($validated['work_date'])->toDateString();

        // Evita duplicados vivos para el mismo empleado/día.
        $exists = CheckOmission::where('employee_id', $employee->id)
            ->whereDate('work_date', $workDate)
            ->whereIn('status', [CheckOmission::STATUS_AUTHORIZED, CheckOmission::STATUS_APPROVED])
            ->exists();

        if ($exists) {
            return back()->withErrors(['work_date' => 'Ya existe una omisión activa para ese colaborador en esa fecha.'])->withInput();
        }

        $attendanceRecord = AttendanceRecord::where('employee_id', $employee->id)
            ->whereDate('work_date', $workDate)
            ->first();

        CheckOmission::create([
            'employee_id' => $employee->id,
            'attendance_record_id' => $attendanceRecord?->id,
            'work_date' => $workDate,
            'reason' => $validated['reason'],
            'comments' => $validated['comments'] ?? null,
            'status' => CheckOmission::STATUS_AUTHORIZED,
            'authorized_by' => $user->id,
            'authorized_at' => now(),
            'created_by' => $user->id,
        ]);

        return redirect()->route('check-omissions.index')
            ->with('success', 'Omisión autorizada. Pendiente de aprobación por el administrador.');
    }

    /**
     * Paso 2: el administrador APRUEBA. Aplica el efecto sobre la asistencia.
     */
    public function approve(CheckOmission $checkOmission): RedirectResponse
    {
        $user = Auth::user();

        if (! $user->hasPermissionTo('check_omissions.approve')) {
            abort(403);
        }

        if ($checkOmission->status !== CheckOmission::STATUS_AUTHORIZED) {
            return back()->withErrors(['status' => 'Solo se pueden aprobar omisiones autorizadas y pendientes.']);
        }

        $checkOmission->approve($user);
        $this->applyEffect($checkOmission);

        return back()->with('success', 'Omisión aprobada. El día se ajustó en la asistencia y la nómina.');
    }

    /**
     * Rechazo (admin). Si ya estaba aprobada, revierte el efecto en asistencia.
     */
    public function reject(Request $request, CheckOmission $checkOmission): RedirectResponse
    {
        $user = Auth::user();

        if (! $user->hasPermissionTo('check_omissions.approve')) {
            abort(403);
        }

        if ($checkOmission->status === CheckOmission::STATUS_REJECTED) {
            return back()->withErrors(['status' => 'La omisión ya está rechazada.']);
        }

        $validated = $request->validate([
            'rejection_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $wasApproved = $checkOmission->status === CheckOmission::STATUS_APPROVED;
        $checkOmission->reject($user, $validated['rejection_reason'] ?? null);

        // Al revertir una omisión aprobada, recalcular restablece la falta/retardo
        // real del día (el sync ya no encuentra una omisión aprobada).
        if ($wasApproved) {
            $this->applyEffect($checkOmission);
        }

        return back()->with('success', 'Omisión rechazada.');
    }

    /**
     * Recalcula la asistencia del día y marca la nómina para recálculo. El sync
     * consulta las omisiones aprobadas, así que sirve tanto para aplicar como
     * para revertir el efecto.
     */
    private function applyEffect(CheckOmission $checkOmission): void
    {
        $syncService = app(ZktecoSyncService::class);

        $attendanceRecord = $checkOmission->attendance_record_id
            ? $checkOmission->attendanceRecord
            : AttendanceRecord::where('employee_id', $checkOmission->employee_id)
                ->whereDate('work_date', Carbon::parse($checkOmission->work_date)->toDateString())
                ->first();

        if ($attendanceRecord) {
            // El status del día lo decide el sync; recalcular respeta la omisión
            // aprobada aunque el registro haya sido editado a mano.
            $syncService->recalculateAttendanceRecord($attendanceRecord, false);
        }

        app(PayrollInvalidationService::class)->invalidate(
            $checkOmission->employee_id,
            Carbon::parse($checkOmission->work_date)->toDateString(),
        );
    }

    /**
     * Exporta el reporte de omisiones (auditoría): quién autorizó, quién aprobó,
     * fecha/hora, motivo y comentarios. (Dani 2026-07-09)
     */
    public function export(Request $request): StreamedResponse
    {
        $user = Auth::user();

        if (! $user->hasPermissionTo('check_omissions.view_all') && ! $user->hasPermissionTo('check_omissions.view_team')) {
            abort(403);
        }

        $query = CheckOmission::with(['employee.department', 'authorizedBy', 'approvedBy', 'rejectedBy']);
        $this->scopeToViewableEmployees($query, $user);

        $query->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->reason, fn ($q, $reason) => $q->where('reason', $reason))
            ->when($request->employee, fn ($q, $emp) => $q->where('employee_id', $emp))
            ->when($request->department, function ($q, $dept) {
                $q->whereHas('employee', fn ($e) => $e->where('department_id', $dept));
            })
            ->when($request->from_date, fn ($q, $d) => $q->whereDate('work_date', '>=', $d))
            ->when($request->to_date, fn ($q, $d) => $q->whereDate('work_date', '<=', $d));

        $omissions = $query->orderByDesc('work_date')->orderByDesc('created_at')->get();

        $statusLabels = [
            CheckOmission::STATUS_AUTHORIZED => 'Autorizada (pendiente de aprobar)',
            CheckOmission::STATUS_APPROVED => 'Aprobada',
            CheckOmission::STATUS_REJECTED => 'Rechazada',
        ];

        $filename = 'omisiones_checada_'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($omissions, $statusLabels) {
            $out = fopen('php://output', 'w');
            // BOM para que Excel abra los acentos correctamente.
            fprintf($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Colaborador', 'Departamento', 'Fecha', 'Motivo', 'Estado',
                'Autorizó', 'Fecha autorización', 'Aprobó', 'Fecha aprobación', 'Comentarios',
            ]);

            foreach ($omissions as $o) {
                fputcsv($out, [
                    $o->employee?->full_name ?? '',
                    $o->employee?->department?->name ?? '',
                    Carbon::parse($o->work_date)->format('d/m/Y'),
                    $o->reasonLabel(),
                    $statusLabels[$o->status] ?? $o->status,
                    $o->authorizedBy?->name ?? '',
                    $o->authorized_at ? $o->authorized_at->format('d/m/Y H:i') : '',
                    $o->approvedBy?->name ?? '',
                    $o->approved_at ? $o->approved_at->format('d/m/Y H:i') : '',
                    $o->comments ?? '',
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ---- Team scoping helpers --------------------------------------------

    /**
     * Aplica el filtro de equipo (subordinados) cuando el usuario no tiene
     * view_all.
     */
    private function scopeToViewableEmployees($query, $user)
    {
        if (! $user->hasPermissionTo('check_omissions.view_all')) {
            $userEmployee = $user->employee;
            if ($userEmployee) {
                $allowedIds = $userEmployee->allSubordinateIds();
                $query->whereIn('employee_id', $allowedIds);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        return $query;
    }

    /**
     * Empleados visibles para el usuario (todos o su equipo).
     */
    private function viewableEmployees($user)
    {
        $query = Employee::active()->orderBy('full_name');

        if (! $user->hasPermissionTo('check_omissions.view_all')) {
            $userEmployee = $user->employee;
            $allowedIds = $userEmployee ? $userEmployee->allSubordinateIds() : [];
            $query->whereIn('id', $allowedIds ?: [0]);
        }

        return $query;
    }

    /**
     * Verifica que el usuario pueda capturar omisiones de ese empleado.
     */
    private function authorizeEmployeeAccess(Employee $employee, $user): void
    {
        if ($user->hasPermissionTo('check_omissions.view_all')) {
            return;
        }

        $userEmployee = $user->employee;
        $allowedIds = $userEmployee ? $userEmployee->allSubordinateIds() : [];

        if (! in_array($employee->id, $allowedIds, true)) {
            abort(403);
        }
    }
}
