<?php

namespace App\Http\Controllers;

use App\Http\Traits\VerifiesTwoFactor;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Incident;
use App\Models\IncidentType;
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
                    // Supervisors only see employees they directly supervise
                    $query->whereHas('employee', function ($q) use ($userEmployee) {
                        $q->where('supervisor_id', $userEmployee->id);
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
                    // Supervisors only see employees they directly supervise
                    $pendingQuery->whereHas('employee', function ($q) use ($userEmployee) {
                        $q->where('supervisor_id', $userEmployee->id);
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
                    // Supervisors only see employees they directly supervise
                    $employeesQuery->where('supervisor_id', $userEmployee->id);
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
            'reason' => ['nullable', 'string', 'max:500'],
            // FASE 2.3: Require document for incapacity incidents (code: INC)
            'document' => [
                Rule::requiredIf(fn () => $incidentType && $incidentType->code === 'INC'),
                'nullable',
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'max:5120',
            ],
        ]);

        // Get employee and their schedule for working days calculation
        $employee = Employee::with('schedule')->find($validated['employee_id']);

        // FASE 2.2: Calculate WORKING days (not calendar days)
        $startDate = Carbon::parse($validated['start_date']);
        $endDate = Carbon::parse($validated['end_date']);
        $validated['days_count'] = $this->calculateWorkingDays(
            $startDate,
            $endDate,
            $employee->schedule?->working_days ?? ['monday', 'tuesday', 'wednesday', 'thursday', 'friday']
        );

        // Check if incident type requires approval
        if (! $incidentType->requires_approval) {
            $validated['status'] = 'approved';
            $validated['approved_by'] = auth()->id();
            $validated['approved_at'] = now();
        }

        // Handle file upload
        if ($request->hasFile('document')) {
            $validated['document_path'] = $request->file('document')->store('incidents', 'public');
        }
        unset($validated['document']);

        Incident::create($validated);

        return redirect()->route('incidents.index')
            ->with('success', 'Incidencia creada exitosamente.');
    }

    /**
     * Calculate working days between two dates based on employee schedule.
     * Excludes weekends (based on schedule) and holidays.
     *
     * @param Carbon $startDate Start date
     * @param Carbon $endDate End date
     * @param array $workingDays Array of working day names (e.g., ['monday', 'tuesday', ...])
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

            if ($isWorkingDay && !$isHoliday) {
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
                    $employeesQuery->where(function ($q) use ($userEmployee) {
                        $q->where('supervisor_id', $userEmployee->id)
                            ->orWhere('id', $userEmployee->id);
                    });
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
        foreach ($validated['employee_ids'] as $employeeId) {
            $employee = Employee::with('schedule')->find($employeeId);

            $startDate = Carbon::parse($validated['start_date']);
            $endDate = Carbon::parse($validated['end_date']);
            $daysCount = $this->calculateWorkingDays(
                $startDate,
                $endDate,
                $employee->schedule?->working_days ?? ['monday', 'tuesday', 'wednesday', 'thursday', 'friday']
            );

            Incident::create([
                'employee_id' => $employeeId,
                'incident_type_id' => $validated['incident_type_id'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'days_count' => $daysCount,
                'reason' => $validated['reason'],
                'status' => $status,
                'approved_by' => $status === 'approved' ? auth()->id() : null,
                'approved_at' => $status === 'approved' ? now() : null,
            ]);
            $count++;
        }

        return redirect()->route('incidents.index')
            ->with('success', "Se crearon {$count} incidencias exitosamente.");
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
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $startDate = Carbon::parse($validated['start_date']);
        $endDate = Carbon::parse($validated['end_date']);
        $validated['days_count'] = $startDate->diffInDays($endDate) + 1;

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

        $incident->delete();

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

        $incident->approve(auth()->user());

        // If it deducts vacation, update employee vacation days
        if ($incidentType->deducts_vacation) {
            $employee->increment('vacation_days_used', $incident->days_count);
        }

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
}
