<?php

namespace App\Http\Controllers;

use App\Http\Traits\VerifiesTwoFactor;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Services\PayrollInvalidationService;
use App\Services\ZktecoSyncService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class IncidentController extends Controller
{
    use VerifiesTwoFactor;

    /**
     * Display a listing of incidents.
     *
     * Filters data based on user permissions:
     * - incidents.view_all: All incidents
     * - incidents.view_team: Only team incidents
     * - incidents.view_own: Only the user's own incidents
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Incident::class);

        $user = Auth::user();
        $query = Incident::with(['employee.department', 'incidentType', 'approvedBy']);

        // Apply permission-based filtering
        if (! $user->hasPermissionTo('incidents.view_all')) {
            if ($user->hasPermissionTo('incidents.view_team')) {
                $userEmployee = $user->employee;
                if ($userEmployee) {
                    $allowedIds = $userEmployee->allSubordinateIds();
                    $query->whereHas('employee', function ($q) use ($allowedIds) {
                        $q->whereIn('id', $allowedIds);
                    });
                } else {
                    $query->whereRaw('1 = 0');
                }
            } elseif ($user->hasPermissionTo('incidents.view_own')) {
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
                $q->where('incident_type_id', $type);
            })
            ->when($request->employee, function ($q, $employee) {
                $q->where('employee_id', $employee);
            })
            ->when($request->search, function ($q, $search) {
                $q->whereHas('employee', function ($e) use ($search) {
                    $e->where('full_name', 'like', "%{$search}%");
                });
            });

        $incidents = $query->orderBy('created_at', 'desc')->paginate(15)->withQueryString();

        // Pending count (scoped to user's view permissions)
        $pendingQuery = Incident::where('status', 'pending');
        if (! $user->hasPermissionTo('incidents.view_all')) {
            if ($user->hasPermissionTo('incidents.view_team')) {
                $userEmployee = $user->employee;
                if ($userEmployee) {
                    $allowedIds = $userEmployee->allSubordinateIds();
                    $pendingQuery->whereHas('employee', function ($q) use ($allowedIds) {
                        $q->whereIn('id', $allowedIds);
                    });
                }
            } elseif ($user->hasPermissionTo('incidents.view_own')) {
                $pendingQuery->where('employee_id', $user->employee?->id);
            }
        }
        $pendingCount = $pendingQuery->count();

        // Get employees for filter (scoped)
        $employeesQuery = Employee::active()->orderBy('full_name');
        if (! $user->hasPermissionTo('incidents.view_all')) {
            if ($user->hasPermissionTo('incidents.view_team')) {
                $userEmployee = $user->employee;
                if ($userEmployee) {
                    $employeesQuery->whereIn('id', $userEmployee->allSubordinateIds());
                }
            } elseif ($user->hasPermissionTo('incidents.view_own')) {
                $employeesQuery->where('id', $user->employee?->id);
            }
        }

        return Inertia::render('Incidents/Index', [
            'incidents' => $incidents,
            'incidentTypes' => IncidentType::active()->get(),
            'employees' => $employeesQuery->get(['id', 'full_name']),
            'pendingCount' => $pendingCount,
            'filters' => $request->only(['status', 'type', 'employee', 'search']),
            'can' => [
                'create' => $user->can('create', Incident::class),
                'approve' => $user->hasPermissionTo('incidents.approve'),
                'reject' => $user->hasPermissionTo('incidents.reject'),
            ],
        ]);
    }

    /**
     * Show the form for creating a new incident.
     */
    public function create(Request $request): Response
    {
        $this->authorize('create', Incident::class);

        $user = Auth::user();

        // Scope employees based on permissions
        $employeesQuery = Employee::active()->orderBy('full_name');
        if (! $user->hasPermissionTo('incidents.view_all')) {
            if ($user->hasPermissionTo('incidents.view_team')) {
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

        return Inertia::render('Incidents/Create', [
            'incidentTypes' => IncidentType::active()->get(),
            'employees' => $employeesQuery->get(['id', 'full_name', 'employee_number', 'vacation_days_entitled', 'vacation_days_used']),
            'selectedEmployee' => $request->employee ?? $user->employee?->id,
        ]);
    }

    /**
     * Store a newly created incident.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Incident::class);

        // Get the incident type first to validate document requirement
        $incidentType = $request->incident_type_id
            ? IncidentType::find($request->incident_type_id)
            : null;

        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'incident_type_id' => ['required', 'exists:incident_types,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'reason' => ['nullable', 'string', 'max:500'],
            'document' => [
                Rule::requiredIf(fn () => $incidentType && $incidentType->requires_document),
                'nullable',
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'max:5120',
            ],
        ]);

        // Auto-calculate hours from start/end time if not provided
        if (! empty($validated['start_time']) && ! empty($validated['end_time']) && empty($validated['hours'])) {
            $start = Carbon::parse($validated['start_time']);
            $end = Carbon::parse($validated['end_time']);
            $validated['hours'] = $start->diffInMinutes($end) / 60;
        }

        // Get employee and their schedule for working days calculation
        $employee = Employee::with('schedule')->find($validated['employee_id']);

        // Días contados según el count_mode del tipo (DECISIONES §6):
        // hábiles para vacaciones/permisos, calendario para incapacidades.
        $startDate = Carbon::parse($validated['start_date']);
        $endDate = Carbon::parse($validated['end_date']);
        $validated['days_count'] = $this->calculateDaysCount($incidentType, $startDate, $endDate, $employee);

        // Reject overlapping incidents for the same employee (any non-rejected status).
        $overlapExists = Incident::where('employee_id', $validated['employee_id'])
            ->whereIn('status', ['pending', 'approved'])
            ->where('start_date', '<=', $validated['end_date'])
            ->where('end_date', '>=', $validated['start_date'])
            ->exists();
        if ($overlapExists) {
            return redirect()->back()->withErrors([
                'dates' => 'Ya existe una incidencia activa para este empleado en el rango de fechas seleccionado.',
            ])->withInput();
        }

        // Check if incident type requires approval
        $autoApproved = false;
        if (! $incidentType->requires_approval) {
            // Validate vacation balance before auto-approving a deducts_vacation type.
            if ($incidentType->deducts_vacation) {
                $available = $employee->vacation_days_entitled - $employee->vacation_days_used;
                if ($validated['days_count'] > $available) {
                    return redirect()->back()->withErrors([
                        'saldo' => "Saldo insuficiente de vacaciones. Disponibles: {$available} dias, solicitados: {$validated['days_count']} dias.",
                    ])->withInput();
                }
            }
            // Horas a cuenta de vacaciones: valida el saldo EN HORAS.
            if ($incidentType->uses_vacation_hours) {
                $requested = (float) ($validated['hours'] ?? 0);
                $availableHours = $employee->vacation_hours_remaining;
                if ($requested > $availableHours) {
                    return redirect()->back()->withErrors([
                        'saldo' => "Saldo insuficiente de horas de vacaciones. Disponibles: {$availableHours} h, solicitadas: {$requested} h.",
                    ])->withInput();
                }
            }
            $validated['status'] = 'approved';
            $validated['approved_by'] = auth()->id();
            $validated['approved_at'] = now();
            $autoApproved = true;
        }

        // Handle file upload
        if ($request->hasFile('document')) {
            $validated['document_path'] = $request->file('document')->store('incidents', 'public');
        }
        unset($validated['document']);

        $incident = Incident::create($validated);

        // Auto-approved + deducts_vacation must charge the balance immediately
        // (the explicit approve() flow handles this when approval is required).
        if ($autoApproved && $incidentType->deducts_vacation) {
            $employee->increment('vacation_days_used', $incident->days_count);
        }
        if ($autoApproved && $incidentType->uses_vacation_hours) {
            $employee->increment('vacation_hours_used', (float) $incident->hours);
        }

        // Una incidencia auto-aprobada surte efecto de inmediato sobre la
        // asistencia (PSA/PEN cambian status y permission_hours).
        if ($autoApproved) {
            $this->recalculateAttendanceForIncident($incident);
        }

        return redirect()->route('incidents.index')
            ->with('success', 'Incidencia creada exitosamente.');
    }

    /**
     * Días de la incidencia según el count_mode del tipo (DECISIONES §6).
     * El MISMO conteo aplica en captura, saldo de vacaciones y nómina.
     */
    private function calculateDaysCount(IncidentType $incidentType, Carbon $startDate, Carbon $endDate, Employee $employee): int
    {
        if (($incidentType->count_mode ?? IncidentType::COUNT_WORKING_DAYS) === IncidentType::COUNT_CALENDAR_DAYS) {
            return max(1, (int) $startDate->diffInDays($endDate) + 1);
        }

        $days = $this->calculateWorkingDays(
            $startDate,
            $endDate,
            $employee->getEffectiveSchedule()?->working_days ?? ['monday', 'tuesday', 'wednesday', 'thursday', 'friday']
        );

        // Regla de vacaciones (Dani, 2026-06-24): en una semana con 3+ días de
        // vacaciones, el sábado de esa semana también cuenta. Mismo cálculo que
        // la nómina (Incident::saturdayVacationBonusDays) para que captura, saldo
        // y nómina coincidan.
        if ($incidentType->deducts_vacation) {
            $holidayDates = Holiday::whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
                ->pluck('date')
                ->map(fn ($date) => $date->toDateString())
                ->all();

            $days += Incident::saturdayVacationBonusDays($startDate, $endDate, $employee, $holidayDates);
        }

        return $days;
    }

    /**
     * Calculate working days between two dates based on employee schedule.
     * Excludes weekends (based on schedule) and holidays.
     *
     * @param  Carbon  $startDate  Start date
     * @param  Carbon  $endDate  End date
     * @param  array  $workingDays  Array of working day names (e.g., ['monday', 'tuesday', ...])
     * @return int Number of working days
     */
    private function calculateWorkingDays(Carbon $startDate, Carbon $endDate, array $workingDays): int
    {
        // Convert day names to day of week ISO numbers (1=Monday, 7=Sunday)
        $dayNameToNumber = [
            'monday' => 1,
            'tuesday' => 2,
            'wednesday' => 3,
            'thursday' => 4,
            'friday' => 5,
            'saturday' => 6,
            'sunday' => 7,
        ];

        $workingDayNumbers = array_map(
            fn ($day) => $dayNameToNumber[strtolower($day)] ?? null,
            $workingDays
        );
        $workingDayNumbers = array_filter($workingDayNumbers);

        // Get holidays in the date range
        $holidays = Holiday::whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->pluck('date')
            ->map(fn ($date) => $date->toDateString())
            ->toArray();

        $daysCount = 0;
        $current = $startDate->copy();

        while ($current->lte($endDate)) {
            $isWorkingDay = in_array($current->dayOfWeekIso, $workingDayNumbers);
            $isHoliday = in_array($current->toDateString(), $holidays);

            if ($isWorkingDay && ! $isHoliday) {
                $daysCount++;
            }
            $current->addDay();
        }

        return max(1, $daysCount); // At least 1 day
    }

    /**
     * Show the form for creating bulk incidents.
     */
    public function createBulk(): Response
    {
        $this->authorize('create', Incident::class);

        $user = Auth::user();

        // Scope employees based on permissions
        $employeesQuery = Employee::active()->orderBy('full_name');
        if (! $user->hasPermissionTo('incidents.view_all')) {
            if ($user->hasPermissionTo('incidents.view_team')) {
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

        return Inertia::render('Incidents/CreateBulk', [
            'employees' => $employeesQuery->with('schedule')->get(['id', 'full_name', 'employee_number', 'department_id', 'schedule_id']),
            'incidentTypes' => IncidentType::active()->get(['id', 'name', 'code', 'requires_approval']),
        ]);
    }

    /**
     * Store bulk incidents for multiple employees.
     */
    public function storeBulk(Request $request): RedirectResponse
    {
        $this->authorize('create', Incident::class);

        $validated = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['required', 'exists:employees,id'],
            'incident_type_id' => ['required', 'exists:incident_types,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $incidentType = IncidentType::find($validated['incident_type_id']);
        $status = $incidentType->requires_approval ? 'pending' : 'approved';

        $count = 0;
        $skipped = [];

        foreach ($validated['employee_ids'] as $employeeId) {
            $employee = Employee::with('schedule')->find($employeeId);
            if (! $employee || $employee->status !== 'active') {
                $skipped[] = "{$employeeId} (no activo)";

                continue;
            }

            $startDate = Carbon::parse($validated['start_date']);
            $endDate = Carbon::parse($validated['end_date']);
            $daysCount = $this->calculateDaysCount($incidentType, $startDate, $endDate, $employee);

            // Skip overlaps: don't double-book the same employee for these dates.
            $overlap = Incident::where('employee_id', $employeeId)
                ->whereIn('status', ['pending', 'approved'])
                ->where('start_date', '<=', $validated['end_date'])
                ->where('end_date', '>=', $validated['start_date'])
                ->exists();
            if ($overlap) {
                $skipped[] = "{$employee->full_name} (solapamiento)";

                continue;
            }

            // Skip if auto-approve + deducts_vacation would overdraft the balance.
            if ($status === 'approved' && $incidentType->deducts_vacation) {
                $available = $employee->vacation_days_entitled - $employee->vacation_days_used;
                if ($daysCount > $available) {
                    $skipped[] = "{$employee->full_name} (saldo {$available}/{$daysCount})";

                    continue;
                }
            }

            $incident = Incident::create([
                'employee_id' => $employeeId,
                'incident_type_id' => $validated['incident_type_id'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'days_count' => $daysCount,
                'reason' => $validated['reason'] ?? null,
                'status' => $status,
                'approved_by' => $status === 'approved' ? auth()->id() : null,
                'approved_at' => $status === 'approved' ? now() : null,
            ]);

            if ($status === 'approved' && $incidentType->deducts_vacation) {
                $employee->increment('vacation_days_used', $daysCount);
            }

            if ($status === 'approved') {
                $this->recalculateAttendanceForIncident($incident);
            }
            $count++;
        }

        $msg = "Se crearon {$count} incidencias exitosamente.";
        if (! empty($skipped)) {
            $msg .= ' Omitidos: '.implode(', ', $skipped);
        }

        return redirect()->route('incidents.index')->with('success', $msg);
    }

    /**
     * Display the specified incident.
     */
    public function show(Incident $incident): Response
    {
        $this->authorize('view', $incident);

        $user = Auth::user();
        $incident->load(['employee.department', 'incidentType', 'approvedBy']);

        return Inertia::render('Incidents/Show', [
            'incident' => $incident,
            'can' => [
                'edit' => $user->can('update', $incident),
                'delete' => $user->can('delete', $incident),
                'approve' => $user->can('approve', $incident),
                'reject' => $user->can('reject', $incident),
            ],
        ]);
    }

    /**
     * Show the form for editing the incident.
     */
    public function edit(Incident $incident): Response
    {
        $this->authorize('update', $incident);

        $incident->load(['employee', 'incidentType']);

        return Inertia::render('Incidents/Edit', [
            'incident' => $incident,
            'incidentTypes' => IncidentType::active()->get(),
            'employees' => Employee::active()->orderBy('full_name')->get(['id', 'full_name', 'employee_number']),
        ]);
    }

    /**
     * Update the specified incident.
     */
    public function update(Request $request, Incident $incident): RedirectResponse
    {
        $this->authorize('update', $incident);

        if ($incident->status !== 'pending') {
            return redirect()->back()->with('error', 'Solo se pueden editar incidencias pendientes.');
        }

        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'incident_type_id' => ['required', 'exists:incident_types,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        // Auto-calculate hours from start/end time if not provided
        if (! empty($validated['start_time']) && ! empty($validated['end_time']) && empty($validated['hours'])) {
            $start = Carbon::parse($validated['start_time']);
            $end = Carbon::parse($validated['end_time']);
            $validated['hours'] = $start->diffInMinutes($end) / 60;
        }

        $employee = Employee::with('schedule')->find($validated['employee_id']);
        $updateType = IncidentType::find($validated['incident_type_id']);
        $startDate = Carbon::parse($validated['start_date']);
        $endDate = Carbon::parse($validated['end_date']);
        $validated['days_count'] = $this->calculateDaysCount($updateType, $startDate, $endDate, $employee);

        $incident->update($validated);

        return redirect()->route('incidents.index')
            ->with('success', 'Incidencia actualizada.');
    }

    /**
     * Remove the specified incident.
     */
    public function destroy(Incident $incident): RedirectResponse
    {
        $this->authorize('delete', $incident);

        // Refund vacation balance if we're deleting an already-approved deducts_vacation incident.
        // Otherwise the days stay consumed and the employee loses balance silently.
        $incidentType = $incident->incidentType;
        $wasApproved = $incident->status === 'approved';
        if ($wasApproved && $incidentType?->deducts_vacation) {
            $incident->employee?->decrement('vacation_days_used', $incident->days_count);
        }
        if ($wasApproved && $incidentType?->uses_vacation_hours) {
            $incident->employee?->decrement('vacation_hours_used', (float) $incident->hours);
        }

        $incident->delete();

        // Si estaba aprobada, su efecto sobre la asistencia debe revertirse
        // (el recálculo ya no la encontrará porque está soft-deleted).
        if ($wasApproved) {
            $this->recalculateAttendanceForIncident($incident);
        }

        return redirect()->route('incidents.index')
            ->with('success', 'Incidencia eliminada.');
    }

    /**
     * Approve an incident.
     */
    public function approve(Request $request, Incident $incident): RedirectResponse
    {
        $this->authorize('approve', $incident);
        $this->verifyTwoFactorCode($request);

        if ($incident->status !== 'pending') {
            return redirect()->back()->with('error', 'Esta incidencia ya fue procesada.');
        }

        $incidentType = $incident->incidentType;
        $employee = $incident->employee;

        // FASE 2.1: Validate vacation balance before approving
        if ($incidentType->deducts_vacation) {
            $availableVacationDays = $employee->vacation_days_entitled - $employee->vacation_days_used;

            if ($incident->days_count > $availableVacationDays) {
                return redirect()->back()->withErrors([
                    'saldo' => "Saldo insuficiente de vacaciones. Disponibles: {$availableVacationDays} dias, solicitados: {$incident->days_count} dias.",
                ]);
            }
        }
        if ($incidentType->uses_vacation_hours) {
            $availableHours = $employee->vacation_hours_remaining;
            if ((float) $incident->hours > $availableHours) {
                return redirect()->back()->withErrors([
                    'saldo' => "Saldo insuficiente de horas de vacaciones. Disponibles: {$availableHours} h, solicitadas: {$incident->hours} h.",
                ]);
            }
        }

        $incident->approve(auth()->user());

        // If it deducts vacation, update employee vacation days
        if ($incidentType->deducts_vacation) {
            $employee->increment('vacation_days_used', $incident->days_count);
        }
        if ($incidentType->uses_vacation_hours) {
            $employee->increment('vacation_hours_used', (float) $incident->hours);
        }

        // La aprobación surte efecto de inmediato sobre la asistencia, igual
        // que AuthorizationController::approve (auditoría C2 / DECISIONES §8):
        // un permiso aprobado tarde revierte la falta/retardo ya marcada.
        $this->recalculateAttendanceForIncident($incident);

        return redirect()->back()->with('success', 'Incidencia aprobada.');
    }

    /**
     * Reject an incident.
     */
    public function reject(Request $request, Incident $incident): RedirectResponse
    {
        $this->authorize('reject', $incident);
        $this->verifyTwoFactorCode($request);

        if ($incident->status !== 'pending') {
            return redirect()->back()->with('error', 'Esta incidencia ya fue procesada.');
        }

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:500'],
        ]);

        $incident->reject(auth()->user(), $validated['rejection_reason']);

        return redirect()->back()->with('success', 'Incidencia rechazada.');
    }

    /**
     * Recalcula los attendance_records cubiertos por la incidencia para que
     * su efecto (o la ausencia de él) se refleje de inmediato — espejo de lo
     * que AuthorizationController::approve ya hace para autorizaciones.
     *
     * Seguro para registros sin checada: calculateAttendanceMetrics los deja
     * en 'absent' (guarda al inicio del método).
     */
    private function recalculateAttendanceForIncident(Incident $incident): void
    {
        $records = AttendanceRecord::where('employee_id', $incident->employee_id)
            ->whereBetween('work_date', [
                Carbon::parse($incident->start_date)->toDateString(),
                Carbon::parse($incident->end_date)->toDateString(),
            ])
            ->get();

        if ($records->isNotEmpty()) {
            $sync = app(ZktecoSyncService::class);

            foreach ($records as $record) {
                $sync->recalculateAttendanceRecord($record);
            }
        }

        // Fase E (DECISIONES §7): la nómina de los periodos que solapan la
        // incidencia queda al día (draft: recálculo automático) o marcada
        // "requiere recálculo" (review/approved). Pagados son inmutables.
        app(PayrollInvalidationService::class)->invalidate(
            $incident->employee_id,
            Carbon::parse($incident->start_date)->toDateString(),
            Carbon::parse($incident->end_date)->toDateString(),
        );
    }
}
