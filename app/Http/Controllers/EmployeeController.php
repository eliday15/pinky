<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Schedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    /**
     * Display a listing of employees.
     *
     * Filters data based on user permissions:
     * - employees.view_all: All employees
     * - employees.view_team: Only employees in user's department or supervised by user
     * - employees.view_own: Only the user's own employee record
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Employee::class);

        $user = Auth::user();
        $query = Employee::with(['department', 'position', 'schedule']);

        // Apply permission-based filtering
        if (! $user->hasPermissionTo('employees.view_all')) {
            if ($user->hasPermissionTo('employees.view_team')) {
                // Supervisor: view department employees or direct reports
                $userEmployee = $user->employee;
                if ($userEmployee) {
                    $query->where(function ($q) use ($userEmployee) {
                        $q->where('department_id', $userEmployee->department_id)
                            ->orWhere('supervisor_id', $userEmployee->id);
                    });
                } else {
                    // No employee linked, show nothing
                    $query->whereRaw('1 = 0');
                }
            } elseif ($user->hasPermissionTo('employees.view_own')) {
                // Employee: view only own record
                $query->where('id', $user->employee?->id);
            } else {
                // No view permissions
                $query->whereRaw('1 = 0');
            }
        }

        // Apply search filters
        $query->when($request->search, function ($q, $search) {
            $q->where(function ($query) use ($search) {
                $query->where('full_name', 'like', "%{$search}%")
                    ->orWhere('employee_number', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        })
            ->when($request->department, function ($q, $department) {
                $q->where('department_id', $department);
            })
            // FASE 5.1: Additional filters
            ->when($request->position, function ($q, $position) {
                $q->where('position_id', $position);
            })
            ->when($request->schedule, function ($q, $schedule) {
                $q->where('schedule_id', $schedule);
            })
            ->when($request->supervisor, function ($q, $supervisor) {
                $q->where('supervisor_id', $supervisor);
            })
            ->when($request->has('status'), function ($q) use ($request) {
                // Si se especifica status, filtrar por ese valor (incluyendo 'all' para ver todos)
                if ($request->status !== 'all') {
                    $q->where('status', $request->status);
                }
            }, function ($q) {
                // Por defecto, solo mostrar empleados activos
                $q->where('status', 'active');
            });

        $employees = $query->orderBy('full_name')->paginate(15)->withQueryString();

        return Inertia::render('Employees/Index', [
            'employees' => $employees,
            'departments' => Department::active()->get(['id', 'name']),
            // FASE 5.1: Additional data for filters
            'positions' => Position::active()->get(['id', 'name']),
            'schedules' => Schedule::active()->get(['id', 'name']),
            'supervisors' => Employee::active()->whereNotNull('supervisor_id')->orWhere(function ($q) {
                $q->whereIn('id', Employee::whereNotNull('supervisor_id')->pluck('supervisor_id'));
            })->get(['id', 'full_name']),
            'filters' => $request->only(['search', 'department', 'position', 'schedule', 'supervisor', 'status']),
            'can' => [
                'create' => $user->can('create', Employee::class),
                'bulkEdit' => $user->hasPermissionTo('employees.bulk_edit'),
            ],
        ]);
    }

    /**
     * Show the form for creating a new employee.
     */
    public function create(): Response
    {
        $this->authorize('create', Employee::class);

        return Inertia::render('Employees/Create', [
            'departments' => Department::active()->get(['id', 'name', 'code']),
            'positions' => Position::active()->get(['id', 'name', 'code', 'position_type', 'base_hourly_rate']),
            'schedules' => Schedule::active()->get(['id', 'name', 'code', 'entry_time', 'exit_time', 'is_flexible']),
            'employees' => Employee::active()->get(['id', 'full_name']), // For supervisor selection
        ]);
    }

    /**
     * Store a newly created employee.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Employee::class);

        $validated = $request->validate([
            'employee_number' => ['required', 'string', 'max:50', 'unique:employees'],
            'contpaqi_code' => ['nullable', 'string', 'max:50', 'unique:employees'],
            'zkteco_user_id' => ['required', 'integer', 'unique:employees'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'hire_date' => ['required', 'date'],
            'termination_date' => ['nullable', 'date', 'after:hire_date'],
            'department_id' => ['required', 'exists:departments,id'],
            'position_id' => ['required', 'exists:positions,id'],
            'schedule_id' => ['required', 'exists:schedules,id'],
            'supervisor_id' => ['nullable', 'exists:employees,id'],
            'hourly_rate' => ['required', 'numeric', 'min:0'],
            'overtime_rate' => ['nullable', 'numeric', 'min:1'],
            'holiday_rate' => ['nullable', 'numeric', 'min:1'],
            'vacation_days_entitled' => ['nullable', 'integer', 'min:0'],
            'vacation_days_used' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'terminated'])],
        ]);

        $validated['full_name'] = $validated['first_name'] . ' ' . $validated['last_name'];
        $validated['overtime_rate'] = $validated['overtime_rate'] ?? 1.5;
        $validated['holiday_rate'] = $validated['holiday_rate'] ?? 2.0;
        $validated['vacation_days_entitled'] = $validated['vacation_days_entitled'] ?? 6;
        $validated['vacation_days_used'] = $validated['vacation_days_used'] ?? 0;
        $validated['status'] = $validated['status'] ?? 'active';

        Employee::create($validated);

        return redirect()->route('employees.index')
            ->with('success', 'Empleado creado exitosamente.');
    }

    /**
     * Display the specified employee.
     */
    public function show(Employee $employee): Response
    {
        $this->authorize('view', $employee);

        $user = Auth::user();
        $employee->load(['department', 'position', 'schedule', 'supervisor', 'attendanceRecords' => function ($q) {
            $q->orderBy('work_date', 'desc')->limit(30);
        }, 'incidents' => function ($q) {
            $q->with('incidentType')->orderBy('start_date', 'desc')->limit(10);
        }]);

        // FASE 5.2: Get audit history for this employee
        $auditHistory = AuditLog::where('auditable_type', Employee::class)
            ->where('auditable_id', $employee->id)
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
            ->map(function ($log) {
                return [
                    'id' => $log->id,
                    'action' => $log->action,
                    'user_name' => $log->user?->name ?? 'Sistema',
                    'old_values' => $log->old_values,
                    'new_values' => $log->new_values,
                    'description' => $log->description,
                    'created_at' => $log->created_at->format('Y-m-d H:i'),
                ];
            });

        return Inertia::render('Employees/Show', [
            'employee' => $employee,
            'auditHistory' => $auditHistory,
            'can' => [
                'edit' => $user->can('update', $employee),
                'delete' => $user->can('delete', $employee),
                'viewSalary' => $user->can('viewSalary', $employee),
            ],
        ]);
    }

    /**
     * Show the form for editing the specified employee.
     */
    public function edit(Employee $employee): Response
    {
        $this->authorize('update', $employee);

        return Inertia::render('Employees/Edit', [
            'employee' => $employee->load('supervisor'),
            'departments' => Department::active()->get(['id', 'name', 'code']),
            'positions' => Position::active()->get(['id', 'name', 'code', 'position_type', 'base_hourly_rate']),
            'schedules' => Schedule::active()->get(['id', 'name', 'code', 'entry_time', 'exit_time', 'is_flexible']),
            'employees' => Employee::active()->where('id', '!=', $employee->id)->get(['id', 'full_name']), // For supervisor selection
        ]);
    }

    /**
     * Update the specified employee.
     */
    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorize('update', $employee);

        // Check if schedule is being changed
        $scheduleChanging = $request->schedule_id != $employee->schedule_id;

        $rules = [
            'employee_number' => ['required', 'string', 'max:50', Rule::unique('employees')->ignore($employee->id)],
            'contpaqi_code' => ['nullable', 'string', 'max:50', Rule::unique('employees')->ignore($employee->id)],
            'zkteco_user_id' => ['required', 'integer', Rule::unique('employees')->ignore($employee->id)],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'hire_date' => ['required', 'date'],
            'termination_date' => ['nullable', 'date', 'after:hire_date'],
            'department_id' => ['required', 'exists:departments,id'],
            'position_id' => ['required', 'exists:positions,id'],
            'schedule_id' => ['required', 'exists:schedules,id'],
            'supervisor_id' => ['nullable', 'exists:employees,id', Rule::notIn([$employee->id])],
            'hourly_rate' => ['required', 'numeric', 'min:0'],
            'overtime_rate' => ['required', 'numeric', 'min:1'],
            'holiday_rate' => ['required', 'numeric', 'min:1'],
            'vacation_days_entitled' => ['required', 'integer', 'min:0'],
            'vacation_days_used' => ['required', 'integer', 'min:0'],
            'status' => ['required', Rule::in(['active', 'inactive', 'terminated'])],
        ];

        // Require evidence when changing schedule
        if ($scheduleChanging) {
            $rules['schedule_change_evidence'] = ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'];
        }

        $validated = $request->validate($rules, [
            'schedule_change_evidence.required' => 'Se requiere evidencia al cambiar el horario del empleado.',
        ]);

        // Handle evidence file upload
        if ($scheduleChanging && $request->hasFile('schedule_change_evidence')) {
            $path = $request->file('schedule_change_evidence')->store('schedule-changes', 'public');
            // Log the schedule change with evidence
            AuditLog::create([
                'user_id' => Auth::id(),
                'auditable_type' => Employee::class,
                'auditable_id' => $employee->id,
                'action' => 'schedule_change',
                'module' => 'employees',
                'old_values' => ['schedule_id' => $employee->schedule_id],
                'new_values' => ['schedule_id' => $validated['schedule_id'], 'evidence_path' => $path],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        $validated['full_name'] = $validated['first_name'] . ' ' . $validated['last_name'];
        unset($validated['schedule_change_evidence']); // Don't save this to employee

        $employee->update($validated);

        return redirect()->route('employees.index')
            ->with('success', 'Empleado actualizado exitosamente.');
    }

    /**
     * Remove the specified employee.
     */
    public function destroy(Employee $employee): RedirectResponse
    {
        $this->authorize('delete', $employee);

        $employee->delete();

        return redirect()->route('employees.index')
            ->with('success', 'Empleado eliminado exitosamente.');
    }

    /**
     * Bulk update employees.
     */
    public function bulkUpdate(Request $request): RedirectResponse
    {
        $user = Auth::user();

        if (! $user->hasPermissionTo('employees.bulk_edit')) {
            abort(403, 'No tienes permiso para edición masiva.');
        }

        $validated = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['exists:employees,id'],
            'field' => ['required', 'string', Rule::in(['department_id', 'position_id', 'schedule_id', 'supervisor_id', 'status'])],
            'value' => ['required'],
        ]);

        Employee::whereIn('id', $validated['employee_ids'])
            ->update([$validated['field'] => $validated['value']]);

        return redirect()->route('employees.index')
            ->with('success', count($validated['employee_ids']) . ' empleados actualizados exitosamente.');
    }
}
