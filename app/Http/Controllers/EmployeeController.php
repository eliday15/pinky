<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CompensationType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Schedule;
use App\Models\VacationTable;
use App\Services\SupervisorResolutionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing employees.
 */
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
                $userEmployee = $user->employee;
                if ($userEmployee) {
                    $query->where(function ($q) use ($userEmployee) {
                        $q->where('department_id', $userEmployee->department_id)
                            ->orWhere('supervisor_id', $userEmployee->id);
                    });
                } else {
                    $query->whereRaw('1 = 0');
                }
            } elseif ($user->hasPermissionTo('employees.view_own')) {
                $query->where('id', $user->employee?->id);
            } else {
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
            ->when($request->position, function ($q, $position) {
                $q->where('position_id', $position);
            })
            ->when($request->schedule, function ($q, $schedule) {
                $q->where('schedule_id', $schedule);
            })
            ->when($request->supervisor, function ($q, $supervisor) {
                $q->where('supervisor_id', $supervisor);
            })
            ->when($request->has('is_minimum_wage') && $request->is_minimum_wage !== '', function ($q) use ($request) {
                $q->where('is_minimum_wage', $request->is_minimum_wage === 'yes');
            })
            ->when($request->has('status'), function ($q) use ($request) {
                if ($request->status !== 'all') {
                    $q->where('status', $request->status);
                }
            }, function ($q) {
                $q->where('status', 'active');
            });

        $employees = $query->orderBy('full_name')->paginate(15)->withQueryString();

        return Inertia::render('Employees/Index', [
            'employees' => $employees,
            'departments' => Department::active()->get(['id', 'name']),
            'positions' => Position::active()->get(['id', 'name']),
            'schedules' => Schedule::active()->get(['id', 'name']),
            'compensationTypes' => CompensationType::active()->get(['id', 'name', 'code', 'calculation_type']),
            'supervisors' => Employee::active()->where(function ($q) {
                $q->whereNotNull('supervisor_id')
                    ->orWhereIn('id', Employee::whereNotNull('supervisor_id')->pluck('supervisor_id'));
            })->get(['id', 'full_name']),
            'filters' => $request->only(['search', 'department', 'position', 'schedule', 'supervisor', 'status', 'is_minimum_wage']),
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
            'departments' => Department::active()->with('compensationTypes')->get(['id', 'name', 'code']),
            'positions' => Position::active()
                ->with([
                    'defaultSchedule:id,name',
                    'compensationTypes',
                    'supervisorPosition:id,name',
                    'department:id,name',
                    'anchorEmployee.compensationTypes',
                ])
                ->get(),
            'schedules' => Schedule::active()->get(['id', 'name', 'code', 'entry_time', 'exit_time', 'break_minutes', 'daily_work_hours', 'late_tolerance_minutes', 'is_flexible', 'working_days']),
            'employees' => Employee::active()->get(['id', 'full_name', 'position_id']),
            'compensationTypes' => CompensationType::active()->get(),
            'vacationTable' => VacationTable::orderBy('years_of_service')->get(),
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
            'address_street' => ['nullable', 'string', 'max:255'],
            'address_city' => ['nullable', 'string', 'max:100'],
            'address_state' => ['nullable', 'string', 'max:100'],
            'address_zip' => ['nullable', 'string', 'max:10'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
            'emergency_phone' => ['nullable', 'string', 'max:20'],
            'credential_type' => ['nullable', 'string', 'max:50'],
            'credential_number' => ['nullable', 'string', 'max:100'],
            'hire_date' => ['required', 'date'],
            'termination_date' => ['nullable', 'date', 'after:hire_date'],
            'department_id' => ['required', 'exists:departments,id'],
            'position_id' => ['required', 'exists:positions,id'],
            'schedule_id' => ['required', 'exists:schedules,id'],
            'schedule_overrides' => ['nullable', 'array'],
            'schedule_overrides.entry_time' => ['nullable', 'date_format:H:i'],
            'schedule_overrides.exit_time' => ['nullable', 'date_format:H:i'],
            'schedule_overrides.break_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'schedule_overrides.daily_work_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'schedule_overrides.late_tolerance_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
            'schedule_overrides.working_days' => ['nullable', 'array'],
            'schedule_overrides.day_schedules' => ['nullable', 'array'],
            'schedule_overrides.day_schedules.*' => ['nullable', 'array'],
            'schedule_overrides.day_schedules.*.entry_time' => ['nullable', 'date_format:H:i'],
            'schedule_overrides.day_schedules.*.exit_time' => ['nullable', 'date_format:H:i'],
            'schedule_overrides.day_schedules.*.break_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'schedule_overrides.day_schedules.*.daily_work_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'supervisor_id' => ['nullable', 'exists:employees,id'],
            'hourly_rate' => ['required', 'numeric', 'min:0'],
            'is_minimum_wage' => ['boolean'],
            'is_trial_period' => ['boolean'],
            'trial_period_end_date' => ['nullable', 'date', 'after_or_equal:hire_date'],
            'imss_number' => ['nullable', 'string', 'max:50'],
            'daily_salary' => ['nullable', 'numeric', 'min:0'],
            'monthly_bonus_type' => ['nullable', 'string', Rule::in(['none', 'fixed', 'variable'])],
            'monthly_bonus_amount' => ['nullable', 'numeric', 'min:0'],
            'vacation_days_entitled' => ['nullable', 'integer', 'min:0'],
            'vacation_days_used' => ['nullable', 'integer', 'min:0'],
            'vacation_days_reserved' => ['nullable', 'integer', 'min:0'],
            'vacation_premium_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'terminated'])],
            'compensation_type_ids' => ['nullable', 'array'],
            'compensation_type_ids.*' => ['exists:compensation_types,id'],
            'compensation_type_overrides' => ['nullable', 'array'],
            'emergency_contacts' => ['required', 'array', 'min:1'],
            'emergency_contacts.*.name' => ['required', 'string', 'max:100'],
            'emergency_contacts.*.phone' => ['required', 'string', 'max:20'],
            'emergency_contacts.*.email' => ['nullable', 'email', 'max:255'],
            'emergency_contacts.*.relationship' => ['required', 'string', 'max:50'],
            'emergency_contacts.*.address' => ['nullable', 'string', 'max:255'],
        ]);

        $validated['full_name'] = $validated['first_name'] . ' ' . $validated['last_name'];
        $validated['vacation_days_entitled'] = $validated['vacation_days_entitled'] ?? 6;
        $validated['vacation_days_used'] = $validated['vacation_days_used'] ?? 0;
        $validated['vacation_days_reserved'] = $validated['vacation_days_reserved'] ?? 0;
        $validated['vacation_premium_percentage'] = $validated['vacation_premium_percentage'] ?? 25.00;
        $validated['status'] = $validated['status'] ?? 'active';
        $validated['is_minimum_wage'] = $validated['is_minimum_wage'] ?? false;
        $validated['is_trial_period'] = $validated['is_trial_period'] ?? false;
        $validated['monthly_bonus_type'] = $validated['monthly_bonus_type'] ?? 'none';
        $validated['monthly_bonus_amount'] = $validated['monthly_bonus_amount'] ?? 0;

        // Handle photo upload
        if ($request->hasFile('photo')) {
            $validated['photo_path'] = $request->file('photo')->store('employees/photos', 'public');
        }
        unset($validated['photo']);

        // Clean schedule_overrides: only keep values that differ from the base schedule
        $validated['schedule_overrides'] = $this->cleanScheduleOverrides(
            $validated['schedule_overrides'] ?? [],
            $validated['schedule_id']
        );

        $emergencyContacts = $validated['emergency_contacts'];
        $employeeData = collect($validated)->except(['compensation_type_ids', 'compensation_type_overrides', 'emergency_contacts'])->toArray();
        $employee = Employee::create($employeeData);

        // Create emergency contacts
        $employee->emergencyContacts()->createMany($emergencyContacts);

        // Sync compensation types
        if ($request->has('compensation_type_ids') && ! empty($request->compensation_type_ids)) {
            $ctTypes = CompensationType::whereIn('id', $request->compensation_type_ids)
                ->pluck('calculation_type', 'id');
            $syncData = [];
            foreach ($request->compensation_type_ids as $typeId) {
                $override = $request->compensation_type_overrides[$typeId] ?? null;
                $syncData[$typeId] = [
                    'custom_percentage' => ($ctTypes[$typeId] ?? '') === 'percentage' ? $override : null,
                    'custom_fixed_amount' => ($ctTypes[$typeId] ?? '') === 'fixed' ? $override : null,
                ];
            }
            $employee->compensationTypes()->sync($syncData);
        }

        // Auto-resolve supervisor from position hierarchy
        if (! $employee->supervisor_id) {
            app(SupervisorResolutionService::class)->resolveAndAssign($employee);
        }

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
        $employee->load([
            'department',
            'position',
            'schedule',
            'supervisor',
            'compensationTypes',
            'emergencyContacts',
            'attendanceRecords' => function ($q) {
                $q->orderBy('work_date', 'desc')->limit(30);
            },
            'incidents' => function ($q) {
                $q->with('incidentType')->orderBy('start_date', 'desc')->limit(10);
            },
        ]);

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
            'employee' => $employee->load(['supervisor', 'compensationTypes', 'emergencyContacts']),
            'departments' => Department::active()->with('compensationTypes')->get(['id', 'name', 'code']),
            'positions' => Position::active()
                ->with([
                    'defaultSchedule:id,name',
                    'compensationTypes',
                    'supervisorPosition:id,name',
                    'department:id,name',
                ])
                ->get(),
            'schedules' => Schedule::active()->get(['id', 'name', 'code', 'entry_time', 'exit_time', 'break_minutes', 'daily_work_hours', 'late_tolerance_minutes', 'is_flexible', 'working_days']),
            'employees' => Employee::active()->where('id', '!=', $employee->id)->get(['id', 'full_name', 'position_id']),
            'compensationTypes' => CompensationType::active()->get(),
            'vacationTable' => VacationTable::orderBy('years_of_service')->get(),
        ]);
    }

    /**
     * Update the specified employee.
     */
    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorize('update', $employee);

        $scheduleChanging = $request->schedule_id != $employee->schedule_id;

        $rules = [
            'employee_number' => ['required', 'string', 'max:50', Rule::unique('employees')->ignore($employee->id)],
            'contpaqi_code' => ['nullable', 'string', 'max:50', Rule::unique('employees')->ignore($employee->id)],
            'zkteco_user_id' => ['required', 'integer', Rule::unique('employees')->ignore($employee->id)],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address_street' => ['nullable', 'string', 'max:255'],
            'address_city' => ['nullable', 'string', 'max:100'],
            'address_state' => ['nullable', 'string', 'max:100'],
            'address_zip' => ['nullable', 'string', 'max:10'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
            'emergency_phone' => ['nullable', 'string', 'max:20'],
            'credential_type' => ['nullable', 'string', 'max:50'],
            'credential_number' => ['nullable', 'string', 'max:100'],
            'hire_date' => ['required', 'date'],
            'termination_date' => ['nullable', 'date', 'after:hire_date'],
            'department_id' => ['required', 'exists:departments,id'],
            'position_id' => ['required', 'exists:positions,id'],
            'schedule_id' => ['required', 'exists:schedules,id'],
            'schedule_overrides' => ['nullable', 'array'],
            'schedule_overrides.entry_time' => ['nullable', 'date_format:H:i'],
            'schedule_overrides.exit_time' => ['nullable', 'date_format:H:i'],
            'schedule_overrides.break_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'schedule_overrides.daily_work_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'schedule_overrides.late_tolerance_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
            'schedule_overrides.working_days' => ['nullable', 'array'],
            'schedule_overrides.day_schedules' => ['nullable', 'array'],
            'schedule_overrides.day_schedules.*' => ['nullable', 'array'],
            'schedule_overrides.day_schedules.*.entry_time' => ['nullable', 'date_format:H:i'],
            'schedule_overrides.day_schedules.*.exit_time' => ['nullable', 'date_format:H:i'],
            'schedule_overrides.day_schedules.*.break_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'schedule_overrides.day_schedules.*.daily_work_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'supervisor_id' => ['nullable', 'exists:employees,id', Rule::notIn([$employee->id])],
            'hourly_rate' => ['required', 'numeric', 'min:0'],
            'is_minimum_wage' => ['boolean'],
            'is_trial_period' => ['boolean'],
            'trial_period_end_date' => ['nullable', 'date', 'after_or_equal:hire_date'],
            'imss_number' => ['nullable', 'string', 'max:50'],
            'daily_salary' => ['nullable', 'numeric', 'min:0'],
            'monthly_bonus_type' => ['nullable', 'string', Rule::in(['none', 'fixed', 'variable'])],
            'monthly_bonus_amount' => ['nullable', 'numeric', 'min:0'],
            'vacation_days_entitled' => ['required', 'integer', 'min:0'],
            'vacation_days_used' => ['required', 'integer', 'min:0'],
            'vacation_days_reserved' => ['nullable', 'integer', 'min:0'],
            'vacation_premium_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'status' => ['required', Rule::in(['active', 'inactive', 'terminated'])],
            'compensation_type_ids' => ['nullable', 'array'],
            'compensation_type_ids.*' => ['exists:compensation_types,id'],
            'compensation_type_overrides' => ['nullable', 'array'],
            'emergency_contacts' => ['required', 'array', 'min:1'],
            'emergency_contacts.*.name' => ['required', 'string', 'max:100'],
            'emergency_contacts.*.phone' => ['required', 'string', 'max:20'],
            'emergency_contacts.*.email' => ['nullable', 'email', 'max:255'],
            'emergency_contacts.*.relationship' => ['required', 'string', 'max:50'],
            'emergency_contacts.*.address' => ['nullable', 'string', 'max:255'],
        ];

        if ($scheduleChanging) {
            $rules['schedule_change_evidence'] = ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'];
        }

        $validated = $request->validate($rules, [
            'schedule_change_evidence.required' => 'Se requiere evidencia al cambiar el horario del empleado.',
        ]);

        // Handle evidence file upload
        if ($scheduleChanging && $request->hasFile('schedule_change_evidence')) {
            $path = $request->file('schedule_change_evidence')->store('schedule-changes', 'public');
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
        $validated['is_minimum_wage'] = $validated['is_minimum_wage'] ?? false;
        $validated['is_trial_period'] = $validated['is_trial_period'] ?? false;
        $validated['monthly_bonus_type'] = $validated['monthly_bonus_type'] ?? 'none';
        $validated['monthly_bonus_amount'] = $validated['monthly_bonus_amount'] ?? 0;
        $validated['vacation_days_reserved'] = $validated['vacation_days_reserved'] ?? 0;
        $validated['vacation_premium_percentage'] = $validated['vacation_premium_percentage'] ?? 25.00;
        unset($validated['schedule_change_evidence']);

        // Handle photo upload
        if ($request->hasFile('photo')) {
            // Delete old photo if exists
            if ($employee->photo_path) {
                Storage::disk('public')->delete($employee->photo_path);
            }
            $validated['photo_path'] = $request->file('photo')->store('employees/photos', 'public');
        }
        unset($validated['photo']);

        // Clean schedule_overrides: only keep values that differ from the base schedule
        $validated['schedule_overrides'] = $this->cleanScheduleOverrides(
            $validated['schedule_overrides'] ?? [],
            $validated['schedule_id']
        );

        $positionChanged = $request->position_id != $employee->position_id;

        $emergencyContacts = $validated['emergency_contacts'];
        $employeeData = collect($validated)->except(['compensation_type_ids', 'compensation_type_overrides', 'emergency_contacts'])->toArray();
        $employee->update($employeeData);

        // Sync emergency contacts (delete + recreate)
        $employee->emergencyContacts()->delete();
        $employee->emergencyContacts()->createMany($emergencyContacts);

        // Sync compensation types
        if ($request->has('compensation_type_ids')) {
            $ctTypes = CompensationType::whereIn('id', $request->compensation_type_ids)
                ->pluck('calculation_type', 'id');
            $syncData = [];
            foreach ($request->compensation_type_ids as $typeId) {
                $override = $request->compensation_type_overrides[$typeId] ?? null;
                $syncData[$typeId] = [
                    'custom_percentage' => ($ctTypes[$typeId] ?? '') === 'percentage' ? $override : null,
                    'custom_fixed_amount' => ($ctTypes[$typeId] ?? '') === 'fixed' ? $override : null,
                ];
            }
            $employee->compensationTypes()->sync($syncData);
        } else {
            $employee->compensationTypes()->detach();
        }

        // Re-resolve supervisor if position changed and no manual override
        if ($positionChanged && ! $request->has('supervisor_override')) {
            $employee->update(['supervisor_id' => null]);
            app(SupervisorResolutionService::class)->resolveAndAssign($employee->fresh());
        }

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
     *
     * Supports:
     * - set_field: Set a specific field to a value for all selected employees
     * - adjust_compensation: Adjust hourly_rate or compensation_types by fixed/percentage
     * - is_minimum_wage_filter: Pre-filter employee_ids by minimum wage status
     */
    public function bulkUpdate(Request $request): RedirectResponse
    {
        $user = Auth::user();

        if (! $user->hasPermissionTo('employees.bulk_edit')) {
            abort(403, 'No tienes permiso para edicion masiva.');
        }

        $validated = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['exists:employees,id'],
            'operation_type' => ['required', 'string', Rule::in(['set_field', 'adjust_compensation'])],
            // Minimum wage filter (Fix 7)
            'is_minimum_wage_filter' => ['nullable', 'string', Rule::in(['all', 'only_minimum', 'above_minimum'])],
            // For set_field
            'field' => ['required_if:operation_type,set_field', 'string', Rule::in(['department_id', 'position_id', 'schedule_id', 'supervisor_id', 'status', 'is_minimum_wage'])],
            'value' => ['required_if:operation_type,set_field'],
            // For adjust_compensation
            'compensation_field' => ['required_if:operation_type,adjust_compensation', 'string', Rule::in(['hourly_rate', 'overtime_rate', 'holiday_rate', 'compensation_types'])],
            'adjustment_type' => ['required_if:operation_type,adjust_compensation', 'string', Rule::in(['fixed', 'percentage'])],
            'adjustment_value' => ['required_if:operation_type,adjust_compensation', 'numeric'],
            // For compensation_types adjustment (Fix 8)
            'compensation_type_ids' => ['nullable', 'array'],
            'compensation_type_ids.*' => ['exists:compensation_types,id'],
        ]);

        // Apply minimum wage filter (Fix 7)
        $employeeIds = $validated['employee_ids'];
        $wageFilter = $validated['is_minimum_wage_filter'] ?? 'all';
        if ($wageFilter === 'only_minimum') {
            $employeeIds = Employee::whereIn('id', $employeeIds)
                ->where('is_minimum_wage', true)
                ->pluck('id')
                ->toArray();
        } elseif ($wageFilter === 'above_minimum') {
            $employeeIds = Employee::whereIn('id', $employeeIds)
                ->where('is_minimum_wage', false)
                ->pluck('id')
                ->toArray();
        }

        if (empty($employeeIds)) {
            return redirect()->route('employees.index')
                ->with('warning', 'Ningun empleado coincide con el filtro de salario seleccionado.');
        }

        if ($validated['operation_type'] === 'set_field') {
            Employee::whereIn('id', $employeeIds)
                ->update([$validated['field'] => $validated['value']]);
        } elseif ($validated['operation_type'] === 'adjust_compensation') {
            $field = $validated['compensation_field'];

            // Fix 8: Compensation types adjustment
            if ($field === 'compensation_types') {
                $typeIds = $validated['compensation_type_ids'] ?? [];
                if (! empty($typeIds)) {
                    $employees = Employee::whereIn('id', $employeeIds)->with('compensationTypes')->get();
                    foreach ($employees as $employee) {
                        $existingPivots = $employee->compensationTypes->keyBy('id');
                        $syncData = [];
                        foreach ($employee->compensationTypes as $ct) {
                            $pivotData = ['custom_percentage' => $ct->pivot->custom_percentage, 'custom_fixed_amount' => $ct->pivot->custom_fixed_amount];
                            if (in_array($ct->id, $typeIds)) {
                                // Adjust this compensation type
                                if ($ct->calculation_type === 'fixed') {
                                    $current = (float) ($ct->pivot->custom_fixed_amount ?? $ct->fixed_amount ?? 0);
                                    if ($validated['adjustment_type'] === 'fixed') {
                                        $pivotData['custom_fixed_amount'] = max(0, round($current + $validated['adjustment_value'], 2));
                                    } else {
                                        $pivotData['custom_fixed_amount'] = max(0, round($current * (1 + $validated['adjustment_value'] / 100), 2));
                                    }
                                } else {
                                    $current = (float) ($ct->pivot->custom_percentage ?? $ct->percentage_value ?? 0);
                                    if ($validated['adjustment_type'] === 'fixed') {
                                        $pivotData['custom_percentage'] = max(0, round($current + $validated['adjustment_value'], 2));
                                    } else {
                                        $pivotData['custom_percentage'] = max(0, round($current * (1 + $validated['adjustment_value'] / 100), 2));
                                    }
                                }
                            }
                            $syncData[$ct->id] = $pivotData;
                        }
                        $employee->compensationTypes()->sync($syncData);
                    }
                }
            } else {
                // Standard field adjustment (hourly_rate, overtime_rate, holiday_rate)
                $employees = Employee::whereIn('id', $employeeIds)->get();
                foreach ($employees as $employee) {
                    $currentValue = (float) $employee->$field;

                    if ($validated['adjustment_type'] === 'fixed') {
                        $newValue = $currentValue + $validated['adjustment_value'];
                    } else {
                        $newValue = $currentValue * (1 + $validated['adjustment_value'] / 100);
                    }

                    $employee->update([$field => max(0, round($newValue, 2))]);
                }
            }
        }

        return redirect()->route('employees.index')
            ->with('success', count($employeeIds) . ' empleados actualizados exitosamente.');
    }

    /**
     * Remove override values that match the base schedule (no real override).
     *
     * Returns null if no overrides remain, otherwise the cleaned array.
     */
    private function cleanScheduleOverrides(array $overrides, int $scheduleId): ?array
    {
        if (empty($overrides)) {
            return null;
        }

        $schedule = Schedule::find($scheduleId);
        if (! $schedule) {
            return null;
        }

        $cleaned = [];
        $fields = ['entry_time', 'exit_time', 'break_minutes', 'daily_work_hours', 'late_tolerance_minutes', 'working_days'];

        foreach ($fields as $field) {
            if (! isset($overrides[$field]) || $overrides[$field] === '' || $overrides[$field] === null) {
                continue;
            }

            $baseValue = $schedule->{$field};

            // Normalize for comparison
            if (in_array($field, ['entry_time', 'exit_time'])) {
                // Compare HH:MM format (strip seconds from base if present)
                $baseShort = substr($baseValue ?? '', 0, 5);
                $overrideShort = substr($overrides[$field], 0, 5);
                if ($overrideShort !== $baseShort) {
                    $cleaned[$field] = $overrides[$field];
                }
            } elseif ($field === 'working_days') {
                $baseDays = is_array($baseValue) ? $baseValue : [];
                $overrideDays = is_array($overrides[$field]) ? $overrides[$field] : [];
                sort($baseDays);
                sort($overrideDays);
                if ($baseDays !== $overrideDays) {
                    $cleaned[$field] = $overrides[$field];
                }
            } else {
                if ((float) $overrides[$field] !== (float) $baseValue) {
                    $cleaned[$field] = $overrides[$field];
                }
            }
        }

        return empty($cleaned) ? null : $cleaned;
    }
}
