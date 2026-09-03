<?php

namespace App\Http\Controllers;

use App\Http\Traits\VerifiesTwoFactor;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Incident;
use App\Models\AuditLog;
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
                    // El equipo incluye al propio jefe (Dani 2026-08-19): ve su
                    // propia hoja de vacaciones además de las de su gente.
                    $allowedIds = array_merge([$userEmployee->id], $userEmployee->allSubordinateIds());
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

        // Per-row: las hojas de vacaciones solo las edita/elimina Admin/RRHH
        // (Dani 2026-08-12), y una aprobada solo la corrige el admin — la
        // policy ya decide todo eso; el frontend solo muestra u oculta.
        $incidents->through(function ($incident) use ($user) {
            $incident->can_update = $user->can('update', $incident);
            $incident->can_delete = $user->can('delete', $incident);

            return $incident;
        });

        // Pending count (scoped to user's view permissions)
        $pendingQuery = Incident::where('status', 'pending');
        if (! $user->hasPermissionTo('incidents.view_all')) {
            if ($user->hasPermissionTo('incidents.view_team')) {
                $userEmployee = $user->employee;
                if ($userEmployee) {
                    $allowedIds = array_merge([$userEmployee->id], $userEmployee->allSubordinateIds());
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
                    $employeesQuery->whereIn('id', array_merge([$userEmployee->id], $userEmployee->allSubordinateIds()));
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
            'employees' => $employeesQuery
                ->get(['id', 'full_name', 'employee_number', 'vacation_days_entitled', 'vacation_days_used', 'vacation_days_reserved', 'vacation_days_advanced', 'vacation_hours_used', 'vacation_hours_credited'])
                ->map(fn (Employee $e) => [
                    'id' => $e->id,
                    'full_name' => $e->full_name,
                    'employee_number' => $e->employee_number,
                    'vacation_days_entitled' => $e->vacation_days_entitled,
                    'vacation_days_used' => $e->vacation_days_used,
                    // Cierre obligatorio de diciembre (Dani 2026-07-17): los
                    // apartados no se pueden solicitar; los adelantados son deuda.
                    'vacation_days_reserved' => (int) $e->vacation_days_reserved,
                    'vacation_days_advanced' => (int) $e->vacation_days_advanced,
                    'vacation_days_for_enjoyment' => $e->vacation_days_for_enjoyment,
                    // Bolsa de horas a cuenta de vacaciones (Dani 2026-07-09).
                    'vacation_hours_bank_remaining' => round($e->vacation_hours_bank_remaining, 2),
                    'uses_vacation_hours_bank' => $e->usesVacationHoursBank(),
                ]),
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
            'converts_to_vacation_hours' => ['nullable', 'boolean'],
            'document' => [
                Rule::requiredIf(fn () => $incidentType && $incidentType->requires_document),
                'nullable',
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'max:5120',
            ],
        ]);

        // Permiso dentro de jornada (PDJ, Dani 2026-08-24): exige salida Y
        // regreso el mismo día, con el regreso después de la salida — la
        // ventana define las horas de permiso que la asistencia descuenta.
        if ($incidentType?->code === 'PDJ') {
            if (empty($validated['start_time']) || empty($validated['end_time'])) {
                return back()->withErrors(['end_time' => 'El permiso dentro de jornada requiere hora de salida y hora de regreso.'])->withInput();
            }
            if ($validated['end_time'] <= $validated['start_time']) {
                return back()->withErrors(['end_time' => 'La hora de regreso debe ser posterior a la hora de salida.'])->withInput();
            }
        }

        // Auto-calculate hours from start/end time if not provided
        if (! empty($validated['start_time']) && ! empty($validated['end_time']) && empty($validated['hours'])) {
            $start = Carbon::parse($validated['start_time']);
            $end = Carbon::parse($validated['end_time']);
            $validated['hours'] = $start->diffInMinutes($end) / 60;
        }

        // Get employee and their schedule for working days calculation
        $employee = Employee::with('schedule')->find($validated['employee_id']);

        // "A cuenta de horas" (HxV): solo aplica a Vacaciones; en otros tipos se
        // ignora. Un vale de conversión NO toma los días (no aplica la regla del
        // sábado) y es invisible para la nómina/asistencia y para el traslape.
        $convertsToHours = $incidentType->deducts_vacation && $request->boolean('converts_to_vacation_hours');
        $validated['converts_to_vacation_hours'] = $convertsToHours;

        // Días contados según el count_mode del tipo (DECISIONES §6):
        // hábiles para vacaciones/permisos, calendario para incapacidades.
        $startDate = Carbon::parse($validated['start_date']);
        $endDate = Carbon::parse($validated['end_date']);
        $validated['days_count'] = $this->calculateDaysCount($incidentType, $startDate, $endDate, $employee, $convertsToHours);

        // Evita duplicar el mismo concepto activo para el empleado y rango.
        // Conceptos distintos sí pueden coexistir (p. ej. FRT contable + PEN
        // real del día). Los vales HxV no ocupan fechas.
        if (! $convertsToHours) {
            $overlapExists = Incident::activeConceptOverlap(
                (int) $validated['employee_id'],
                (int) $validated['incident_type_id'],
                $validated['start_date'],
                $validated['end_date'],
            )
                ->exists();
            if ($overlapExists) {
                return redirect()->back()->withErrors([
                    'dates' => 'Ya existe una incidencia activa del mismo concepto para este empleado en el rango de fechas seleccionado.',
                ])->withInput();
            }
        }

        // Check if incident type requires approval
        $autoApproved = false;
        if (! $incidentType->requires_approval) {
            // Validate vacation balance before auto-approving a deducts_vacation type.
            // Descuenta de forma proporcional las horas ya gastadas de la bolsa.
            if ($incidentType->deducts_vacation) {
                $available = $convertsToHours
                    ? $this->convertibleDays($employee)
                    : $employee->vacation_days_available_for_request;
                if ($validated['days_count'] > $available) {
                    $availableLabel = rtrim(rtrim(number_format($available, 2), '0'), '.');

                    return redirect()->back()->withErrors([
                        'saldo' => "Saldo insuficiente de vacaciones. Disponibles: {$availableLabel} dias, solicitados: {$validated['days_count']} dias.",
                    ])->withInput();
                }
            }
            // Horas a cuenta de vacaciones: valida contra la BOLSA (horas
            // convertidas por RRHH menos las ya gastadas).
            if ($incidentType->uses_vacation_hours) {
                $requested = (float) ($validated['hours'] ?? 0);
                $availableHours = $employee->vacation_hours_bank_remaining;
                if ($requested <= 0 || $requested > $availableHours) {
                    return redirect()->back()->withErrors([
                        'saldo' => "Saldo insuficiente en la bolsa de horas de vacaciones. Disponibles: {$availableHours} h, solicitadas: {$requested} h.",
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
            if ($convertsToHours) {
                $this->creditVacationHours($employee, $incident);
            } else {
                $employee->increment('vacation_days_used', $incident->days_count);
            }
        }
        if ($autoApproved && $incidentType->uses_vacation_hours) {
            $employee->increment('vacation_hours_used', (float) $incident->hours);
        }

        // Una incidencia auto-aprobada surte efecto de inmediato sobre la
        // asistencia (PSA/PEN cambian status y permission_hours). Un vale de
        // conversión HxV no toca la asistencia.
        if ($autoApproved && ! $convertsToHours) {
            $this->recalculateAttendanceForIncident($incident);
        }

        return redirect()->route('incidents.index')
            ->with('success', 'Incidencia creada exitosamente.');
    }

    /**
     * Días de la incidencia según el count_mode del tipo (DECISIONES §6).
     * El MISMO conteo aplica en captura, saldo de vacaciones y nómina.
     */
    private function calculateDaysCount(IncidentType $incidentType, Carbon $startDate, Carbon $endDate, Employee $employee, bool $convertsToHours = false): int
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
        // y nómina coincidan. NO aplica a los vales de conversión a horas (HxV):
        // no se está tomando la semana, solo se banca cada día como 8 h.
        if ($incidentType->deducts_vacation && ! $convertsToHours) {
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

            // Solo se omite un traslape del mismo concepto. Conceptos distintos
            // pueden coexistir en las mismas fechas.
            $overlap = Incident::activeConceptOverlap(
                (int) $employeeId,
                (int) $validated['incident_type_id'],
                $validated['start_date'],
                $validated['end_date'],
            )
                ->exists();
            if ($overlap) {
                $skipped[] = "{$employee->full_name} (solapamiento)";

                continue;
            }

            // Skip if auto-approve + deducts_vacation would overdraft the balance
            // (proporcional: descuenta también las horas ya gastadas de la bolsa).
            if ($status === 'approved' && $incidentType->deducts_vacation) {
                $available = $employee->vacation_days_available_for_request;
                if ($daysCount > $available) {
                    $availableLabel = rtrim(rtrim(number_format($available, 2), '0'), '.');
                    $skipped[] = "{$employee->full_name} (saldo {$availableLabel}/{$daysCount})";

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

        AuditLog::record(
            module: AuditLog::MODULE_INCIDENTS,
            action: AuditLog::ACTION_CREATE,
            model: null,
            description: 'Creo ' . $count . ' incidencias de tipo ' . ($incidentType->name ?? 'incidencia') . ' del ' . Carbon::parse($validated['start_date'])->format('d/m/Y') . ' al ' . Carbon::parse($validated['end_date'])->format('d/m/Y'),
            metadata: ['total' => $count, 'omitidos' => count($skipped), 'tipo' => $incidentType->name ?? null],
        );

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

        $wasApproved = $incident->status === 'approved';

        if ($incident->status !== 'pending' && ! $wasApproved) {
            return redirect()->back()->with('error', 'Solo se pueden editar incidencias pendientes.');
        }

        // Corrección de una APROBADA (Dani 2026-08-12: hoja de vacaciones de
        // 1 día capturada como 4): solo Admin/RRHH, solo fechas/horas/motivo,
        // y el saldo de vacaciones se ajusta por la diferencia de días.
        if ($wasApproved) {
            if (! auth()->user()->hasPermissionTo('incidents.view_all')) {
                return redirect()->back()->with('error', 'Solo el administrador puede corregir una incidencia aprobada.');
            }
            if ($incident->converts_to_vacation_hours || $incident->incidentType?->uses_vacation_hours) {
                return redirect()->back()->with('error', 'Este tipo se corrige eliminándolo y capturándolo de nuevo (el borrado devuelve el saldo a la bolsa).');
            }
            if ((int) $incident->reserved_days_taken > 0) {
                return redirect()->back()->with('error', 'Esta incidencia tomó días apartados de diciembre; corrígela eliminándola y capturándola de nuevo.');
            }
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

        if ($wasApproved && ((int) $validated['employee_id'] !== (int) $incident->employee_id
            || (int) $validated['incident_type_id'] !== (int) $incident->incident_type_id)) {
            return redirect()->back()->with('error', 'En una incidencia aprobada solo se corrigen fechas, horas y motivo. Para cambiar de empleado o de tipo, elimínala (devuelve el saldo) y captúrala de nuevo.');
        }

        // Permiso dentro de jornada (PDJ): mismas reglas que al capturar —
        // salida Y regreso, con el regreso posterior.
        $updateTypeForWindow = IncidentType::find($validated['incident_type_id']);
        if ($updateTypeForWindow?->code === 'PDJ') {
            if (empty($validated['start_time']) || empty($validated['end_time'])) {
                return back()->withErrors(['end_time' => 'El permiso dentro de jornada requiere hora de salida y hora de regreso.'])->withInput();
            }
            if ($validated['end_time'] <= $validated['start_time']) {
                return back()->withErrors(['end_time' => 'La hora de regreso debe ser posterior a la hora de salida.'])->withInput();
            }
            // El rango de horas cambió: las horas se rederivan de la ventana.
            $validated['hours'] = null;
        }

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

        // Mantiene el mismo invariante de captura al editar. Excluye la propia
        // incidencia para que conservar o ajustar su rango no choque consigo.
        if (! $incident->converts_to_vacation_hours && Incident::activeConceptOverlap(
            (int) $validated['employee_id'],
            (int) $validated['incident_type_id'],
            $validated['start_date'],
            $validated['end_date'],
            $incident->id,
        )->exists()) {
            return redirect()->back()->withErrors([
                'dates' => 'Ya existe una incidencia activa del mismo concepto para este empleado en el rango de fechas seleccionado.',
            ])->withInput();
        }

        if (! $wasApproved) {
            $incident->update($validated);

            return redirect()->route('incidents.index')
                ->with('success', 'Incidencia actualizada.');
        }

        // ─── Corrección de aprobada: ajustar saldo + reprocesar asistencia ───
        $oldDays = (float) $incident->days_count;
        $newDays = (float) $validated['days_count'];
        $oldStart = Carbon::parse($incident->start_date)->toDateString();
        $oldEnd = Carbon::parse($incident->end_date)->toDateString();
        $delta = round($newDays - $oldDays, 2);

        if ($updateType->deducts_vacation) {
            if ($newDays <= 0) {
                return redirect()->back()->withErrors([
                    'saldo' => 'La corrección deja 0 días; si la hoja no procede, elimínala (el borrado devuelve los días al saldo).',
                ]);
            }
            if ($delta > 0 && $delta > (float) $employee->vacation_days_available_for_request) {
                $label = rtrim(rtrim(number_format((float) $employee->vacation_days_available_for_request, 2), '0'), '.');

                return redirect()->back()->withErrors([
                    'saldo' => "Saldo insuficiente para ampliar la hoja: la corrección agrega {$delta} día(s) y solo hay {$label} disponibles.",
                ]);
            }
        }

        $incident->update($validated);

        if ($updateType->deducts_vacation && abs($delta) > 0.001) {
            $delta > 0
                ? $employee->increment('vacation_days_used', $delta)
                : $employee->decrement('vacation_days_used', abs($delta));
        }

        // Los días que SALEN de la hoja deben volver a su estado real (falta,
        // retardo, día normal) y los que ENTRAN deben marcarse: recalcular la
        // unión del rango viejo y el nuevo, e invalidar la nómina que lo cubre.
        $this->recalculateAttendanceRange(
            $incident->employee_id,
            min($oldStart, $startDate->toDateString()),
            max($oldEnd, $endDate->toDateString()),
        );

        $incident->recordAuditEvent(
            action: AuditLog::ACTION_UPDATE,
            description: 'Corrigio incidencia aprobada de '.($employee?->full_name ?? 'empleado')
                .': '.rtrim(rtrim(number_format($oldDays, 2), '0'), '.').' → '.rtrim(rtrim(number_format($newDays, 2), '0'), '.').' dias'
                .' ('.$oldStart.' a '.$oldEnd.' → '.$startDate->toDateString().' a '.$endDate->toDateString().')',
            metadata: ['dias_antes' => $oldDays, 'dias_despues' => $newDays, 'ajuste_saldo' => -$delta],
            oldValues: ['start_date' => $oldStart, 'end_date' => $oldEnd, 'days_count' => $oldDays],
            newValues: ['start_date' => $startDate->toDateString(), 'end_date' => $endDate->toDateString(), 'days_count' => $newDays],
        );

        $deltaLabel = $delta < 0
            ? 'se devolvieron '.rtrim(rtrim(number_format(abs($delta), 2), '0'), '.').' día(s) al saldo'
            : ($delta > 0 ? 'se descontaron '.rtrim(rtrim(number_format($delta, 2), '0'), '.').' día(s) más del saldo' : 'sin cambio de saldo');

        return redirect()->route('incidents.index')
            ->with('success', "Incidencia corregida ({$deltaLabel}). Asistencia y nómina del rango quedaron al día.");
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
            if ($incident->converts_to_vacation_hours) {
                // Vale de conversión: devolver las horas acreditadas a la bolsa,
                // nunca por debajo de lo ya gastado (defensivo).
                $employee = $incident->employee;
                if ($employee) {
                    $refund = min(
                        (float) $incident->days_count * Employee::VACATION_HOURS_PER_DAY,
                        max(0.0, (float) $employee->vacation_hours_credited - (float) $employee->vacation_hours_used),
                    );
                    if ($refund > 0) {
                        $employee->decrement('vacation_hours_credited', $refund);
                    }
                }
            } else {
                $incident->employee?->decrement('vacation_days_used', $incident->days_count);

                // Restaurar los días apartados de diciembre que la emergencia jaló,
                // si no se devuelven, la reserva se pierde en silencio.
                if ((int) $incident->reserved_days_taken > 0) {
                    $incident->employee?->increment('vacation_days_reserved', (int) $incident->reserved_days_taken);
                }
            }
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

        // El trait auto-registra el deleted; aquí capturamos el contexto de negocio
        // adicional (devolución de saldo) que no queda en el evento del modelo.
        $desc = 'Elimino ' . ($incidentType?->name ?? 'incidencia') . ' de ' . ($incident->employee?->full_name ?? 'empleado') . ' del ' . Carbon::parse($incident->start_date)->format('d/m/Y') . ' al ' . Carbon::parse($incident->end_date)->format('d/m/Y');
        if ($wasApproved && $incidentType?->deducts_vacation) {
            $desc .= ' (devolvio ' . $incident->days_count . ' dias de vacaciones)';
        }
        $incident->recordAuditEvent(
            action: AuditLog::ACTION_DELETE,
            description: $desc,
            metadata: ['era_aprobada' => $wasApproved, 'dias' => $incident->days_count],
        );

        return redirect()->route('incidents.index')
            ->with('success', 'Incidencia eliminada.');
    }

    /**
     * Cargar los días de vacaciones al saldo del empleado.
     *
     * En modo normal sólo incrementa los usados. En emergencia (override del
     * Administrador) lo que el saldo normal no alcanza se toma de los días
     * apartados de diciembre: se reduce la reserva y se guarda en la incidencia
     * cuántos se jalaron, para poder devolverlos exactos si se elimina.
     */
    private function chargeVacationDays(Employee $employee, Incident $incident, bool $useReserved): void
    {
        if ($useReserved) {
            $normalAvailable = $employee->vacation_days_available_for_request;
            $overflow = max(0.0, $incident->days_count - $normalAvailable);
            $pulled = min((int) ($employee->vacation_days_reserved ?? 0), (int) ceil($overflow));

            if ($pulled > 0) {
                $employee->decrement('vacation_days_reserved', $pulled);
                $incident->forceFill(['reserved_days_taken' => $pulled])->save();
            }
        }

        $employee->increment('vacation_days_used', $incident->days_count);
    }

    /**
     * Días de vacaciones que el saldo respalda para convertir a la bolsa de
     * horas, descontando lo ya comprometido en la bolsa sin gastar (mismo
     * criterio que VacationHoursBankController::convert, para no doble-gastar).
     */
    private function convertibleDays(Employee $employee): float
    {
        $committedUnspentDays = max(
            0.0,
            ((float) $employee->vacation_hours_credited - (float) $employee->vacation_hours_used) / Employee::VACATION_HOURS_PER_DAY,
        );

        return floor($employee->vacation_days_available_for_request - $committedUnspentDays);
    }

    /**
     * Acreditar a la bolsa de horas los días de un vale de conversión HxV
     * (1 día = 8 h). No consume el día como vacación: el saldo se cobra de forma
     * proporcional conforme se gastan las horas en permisos.
     */
    private function creditVacationHours(Employee $employee, Incident $incident): void
    {
        $employee->increment(
            'vacation_hours_credited',
            (float) $incident->days_count * Employee::VACATION_HOURS_PER_DAY,
        );
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

        // Emergencia: sólo el Administrador puede "jalar" días de los apartados
        // de diciembre cuando el saldo normal no alcanza (Dani 2026-07-22).
        $useReserved = $request->boolean('use_reserved_days');

        // FASE 2.1: Validate vacation balance before approving
        if ($incidentType->deducts_vacation) {
            if ($incident->converts_to_vacation_hours) {
                // Vale de conversión a horas: solo puede convertir días que el
                // saldo respalde (descontando lo ya comprometido en la bolsa).
                $convertible = $this->convertibleDays($employee);
                if ($incident->days_count > $convertible) {
                    $label = rtrim(rtrim(number_format($convertible, 2), '0'), '.');

                    return redirect()->back()->withErrors([
                        'saldo' => "Saldo insuficiente de vacaciones para convertir a horas. Disponibles: {$label} dias, solicitados: {$incident->days_count} dias.",
                    ]);
                }
            } else {
                if ($useReserved && ! auth()->user()->hasAnyRole(['superadmin', 'admin'])) {
                    return redirect()->back()->withErrors([
                        'saldo' => 'Solo el Administrador puede tomar de los dias obligatorios de diciembre.',
                    ]);
                }

                $availableVacationDays = $useReserved
                    ? $employee->vacation_days_available_with_reserve
                    : $employee->vacation_days_available_for_request;

                if ($incident->days_count > $availableVacationDays) {
                    $availableLabel = rtrim(rtrim(number_format($availableVacationDays, 2), '0'), '.');
                    $hint = ($useReserved || (int) ($employee->vacation_days_reserved ?? 0) === 0)
                        ? ''
                        : ' Tiene '.(int) $employee->vacation_days_reserved.' dias apartados de diciembre; solo el Administrador puede tomarlos en emergencia.';

                    return redirect()->back()->withErrors([
                        'saldo' => "Saldo insuficiente de vacaciones. Disponibles: {$availableLabel} dias, solicitados: {$incident->days_count} dias.{$hint}",
                    ]);
                }
            }
        }
        if ($incidentType->uses_vacation_hours) {
            $availableHours = $employee->vacation_hours_bank_remaining;
            if ((float) $incident->hours <= 0 || (float) $incident->hours > $availableHours) {
                return redirect()->back()->withErrors([
                    'saldo' => "Saldo insuficiente en la bolsa de horas de vacaciones. Disponibles: {$availableHours} h, solicitadas: {$incident->hours} h.",
                ]);
            }
        }

        $incident->approve(auth()->user());

        // If it deducts vacation, update employee vacation days — salvo que sea
        // un vale de conversión HxV: entonces el día NO se consume como vacación,
        // se acredita a la bolsa de horas (días × 8) para gastarse por horas.
        if ($incidentType->deducts_vacation) {
            if ($incident->converts_to_vacation_hours) {
                $this->creditVacationHours($employee, $incident);
            } else {
                $this->chargeVacationDays($employee, $incident, $useReserved);
            }
        }
        if ($incidentType->uses_vacation_hours) {
            $employee->increment('vacation_hours_used', (float) $incident->hours);
        }

        // La aprobación surte efecto de inmediato sobre la asistencia, igual
        // que AuthorizationController::approve (auditoría C2 / DECISIONES §8):
        // un permiso aprobado tarde revierte la falta/retardo ya marcada. Un
        // vale de conversión HxV no toca la asistencia (no es tiempo tomado).
        if (! $incident->converts_to_vacation_hours) {
            $this->recalculateAttendanceForIncident($incident);
        }

        $incident->recordAuditEvent(
            action: AuditLog::ACTION_APPROVE,
            description: 'Aprobo ' . ($incidentType->name ?? 'incidencia') . ' de ' . ($employee->full_name ?? 'empleado') . ': ' . $incident->days_count . ' dias del ' . Carbon::parse($incident->start_date)->format('d/m/Y') . ' al ' . Carbon::parse($incident->end_date)->format('d/m/Y'),
            metadata: ['dias' => $incident->days_count, 'tipo' => $incidentType->name ?? null],
        );

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

        $incident->load(['employee', 'incidentType']);
        $incident->recordAuditEvent(
            action: AuditLog::ACTION_REJECT,
            description: 'Rechazo ' . ($incident->incidentType?->name ?? 'incidencia') . ' de ' . ($incident->employee?->full_name ?? 'empleado') . ' del ' . Carbon::parse($incident->start_date)->format('d/m/Y') . ' al ' . Carbon::parse($incident->end_date)->format('d/m/Y') . '. Motivo: ' . $validated['rejection_reason'],
            metadata: ['motivo' => $validated['rejection_reason']],
        );

        return redirect()->back()->with('success', 'Incidencia rechazada.');
    }

    /**
     * Formato individual de vacaciones en MEDIA CARTA (Dani 2026-08-12): el
     * encargado lo imprime al capturar la hoja, con las fechas tomadas, el
     * estado del saldo (corresponden / tomados / toma / pendientes) y las
     * líneas de firma — réplica del formato en papel de la fábrica. Mismo
     * scoping de visibilidad que el resto del módulo (view_all / view_team /
     * view_own).
     */
    public function vacationForm(Incident $incident): \Symfony\Component\HttpFoundation\Response
    {
        $user = Auth::user();
        if (! $user->hasPermissionTo('incidents.view_all')) {
            if ($user->hasPermissionTo('incidents.view_team')) {
                // El equipo incluye al propio jefe (Dani 2026-08-19): también
                // imprime SU propia hoja de vacaciones.
                $userEmployee = $user->employee;
                $allowedIds = $userEmployee
                    ? array_merge([$userEmployee->id], $userEmployee->allSubordinateIds())
                    : [];
                abort_unless(collect($allowedIds)->contains($incident->employee_id), 403);
            } elseif ($user->hasPermissionTo('incidents.view_own')) {
                abort_unless($incident->employee_id === $user->employee?->id, 403);
            } else {
                abort(403);
            }
        }

        abort_unless(($incident->incidentType?->category) === 'vacation', 404, 'El formato solo aplica a Vacaciones.');

        $employee = $incident->employee()->withTrashed()->with('department')->first();
        abort_unless($employee !== null, 404);

        // Fechas listadas: el rango capturado, saltando domingos (nunca son
        // día de vacación).
        $dates = [];
        $cursor = Carbon::parse($incident->start_date);
        $end = Carbon::parse($incident->end_date);
        while ($cursor->lte($end)) {
            if ($cursor->dayOfWeek !== Carbon::SUNDAY) {
                $dates[] = $cursor->copy();
            }
            $cursor->addDay();
        }

        $toma = (int) ($incident->days_count ?? count($dates));
        $used = (float) ($employee->vacation_days_used ?? 0);
        // "Tomados anteriores" excluye ESTA hoja: si ya se aprobó, sus días ya
        // están sumados en vacation_days_used y se restan para el desglose.
        $anteriores = $incident->status === 'approved' ? max(0, $used - $toma) : $used;
        $corresponden = (float) ($employee->vacation_days_entitled ?? 0);
        $pendientes = max(0, $corresponden - $anteriores - $toma);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.vacation-form', [
            'employee' => $employee,
            'solicitud' => Carbon::parse($incident->created_at)->format('d/m/Y'),
            'ingreso' => $employee->hire_date ? Carbon::parse($employee->hire_date)->format('d/m/Y') : '—',
            'dates' => $dates,
            'inicio' => Carbon::parse($incident->start_date)->format('d/m/Y'),
            'fin' => Carbon::parse($incident->end_date)->format('d/m/Y'),
            'corresponden' => rtrim(rtrim(number_format($corresponden, 1), '0'), '.'),
            'toma' => $toma,
            'anteriores' => rtrim(rtrim(number_format($anteriores, 1), '0'), '.'),
            'pendientes' => rtrim(rtrim(number_format($pendientes, 1), '0'), '.'),
            // Media carta: 8.5 × 5.5 in = 612 × 396 pt.
        ])->setPaper([0, 0, 612, 396]);

        return $pdf->download("vacaciones_{$employee->employee_number}_".Carbon::parse($incident->start_date)->format('Y-m-d').'.pdf');
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
        $this->recalculateAttendanceRange(
            $incident->employee_id,
            Carbon::parse($incident->start_date)->toDateString(),
            Carbon::parse($incident->end_date)->toDateString(),
        );
    }

    /**
     * Recalcula la asistencia de un rango de fechas del empleado e invalida la
     * nómina de los periodos que lo solapan. La corrección de una incidencia
     * aprobada lo usa con la UNIÓN del rango viejo y el nuevo, para que los
     * días que salieron de la hoja vuelvan a su estado real.
     */
    private function recalculateAttendanceRange(int $employeeId, string $startDate, string $endDate): void
    {
        $records = AttendanceRecord::where('employee_id', $employeeId)
            ->whereBetween('work_date', [$startDate, $endDate])
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
        app(PayrollInvalidationService::class)->invalidate($employeeId, $startDate, $endDate);
    }
}
