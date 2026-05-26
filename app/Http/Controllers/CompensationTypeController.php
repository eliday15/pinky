<?php

namespace App\Http\Controllers;

use App\Models\CompensationType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing compensation type concepts.
 */
class CompensationTypeController extends Controller
{
    /**
     * Display a listing of compensation types.
     */
    public function index(Request $request): Response
    {
        $user = Auth::user();
        if (! $user->hasPermissionTo('compensation_types.manage')) {
            abort(403);
        }

        $query = CompensationType::query();

        $query->when($request->search, function ($q, $search) {
            $q->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        })->when($request->has('status'), function ($q) use ($request) {
            if ($request->status !== 'all') {
                $q->where('is_active', $request->status === 'active');
            }
        }, function ($q) {
            $q->where('is_active', true);
        });

        $compensationTypes = $query->withCount(['employees', 'positions', 'departments'])
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('CompensationTypes/Index', [
            'compensationTypes' => $compensationTypes,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    /**
     * Show the form for creating a new compensation type.
     */
    public function create(): Response
    {
        $user = Auth::user();
        if (! $user->hasPermissionTo('compensation_types.manage')) {
            abort(403);
        }

        return Inertia::render('CompensationTypes/Create', [
            'positions' => Position::active()->get(['id', 'name', 'code']),
            'departments' => Department::active()->get(['id', 'name', 'code']),
            'employees' => Employee::active()
                ->orderBy('full_name')
                ->get(['id', 'full_name', 'employee_number', 'department_id', 'position_id']),
        ]);
    }

    /**
     * Store a newly created compensation type.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = Auth::user();
        if (! $user->hasPermissionTo('compensation_types.manage')) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:50', 'unique:compensation_types'],
            'description' => ['nullable', 'string', 'max:500'],
            'calculation_type' => ['required', Rule::in(['fixed', 'percentage'])],
            'percentage_value' => ['required_if:calculation_type,percentage', 'nullable', 'numeric', 'min:0.01', 'max:999.99'],
            'fixed_amount' => ['required_if:calculation_type,fixed', 'nullable', 'numeric', 'min:0.01', 'max:999999.99'],
            'is_active' => ['boolean'],
            'application_mode' => ['required', Rule::in(['per_hour', 'per_day', 'one_time'])],
            'authorization_type' => ['nullable', Rule::in(['overtime', 'night_shift', 'holiday_worked', 'special'])],
            'attendance_pull_rule' => ['nullable', Rule::in([CompensationType::PULL_RULE_MEAL, CompensationType::PULL_RULE_WEEKEND])],
            'priority' => ['integer', 'min:0'],
            'position_ids' => ['nullable', 'array'],
            'position_ids.*' => ['exists:positions,id'],
            'position_percentages' => ['nullable', 'array'],
            'position_fixed_amounts' => ['nullable', 'array'],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['exists:departments,id'],
            'department_percentages' => ['nullable', 'array'],
            'department_fixed_amounts' => ['nullable', 'array'],
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['exists:employees,id'],
            'employee_percentages' => ['nullable', 'array'],
            'employee_fixed_amounts' => ['nullable', 'array'],
        ]);

        $compensationType = CompensationType::create(
            collect($validated)->only([
                'name', 'code', 'description', 'calculation_type',
                'percentage_value', 'fixed_amount', 'is_active',
                'application_mode', 'authorization_type', 'attendance_pull_rule', 'priority',
            ])->toArray()
        );

        $this->syncPositions($compensationType, $request);
        $this->syncDepartments($compensationType, $request);
        $this->syncEmployees($compensationType, $request);

        return redirect()->route('compensation-types.index')
            ->with('success', 'Concepto de compensacion creado exitosamente.');
    }

    /**
     * Show the form for editing the specified compensation type.
     */
    public function edit(CompensationType $compensationType): Response
    {
        $user = Auth::user();
        if (! $user->hasPermissionTo('compensation_types.manage')) {
            abort(403);
        }

        $compensationType->load(['positions', 'departments', 'employees']);

        return Inertia::render('CompensationTypes/Edit', [
            'compensationType' => $compensationType,
            'positions' => Position::active()->get(['id', 'name', 'code']),
            'departments' => Department::active()->get(['id', 'name', 'code']),
            'employees' => Employee::active()
                ->orderBy('full_name')
                ->get(['id', 'full_name', 'employee_number', 'department_id', 'position_id']),
        ]);
    }

    /**
     * Update the specified compensation type.
     */
    public function update(Request $request, CompensationType $compensationType): RedirectResponse
    {
        $user = Auth::user();
        if (! $user->hasPermissionTo('compensation_types.manage')) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:50', Rule::unique('compensation_types')->ignore($compensationType->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'calculation_type' => ['required', Rule::in(['fixed', 'percentage'])],
            'percentage_value' => ['required_if:calculation_type,percentage', 'nullable', 'numeric', 'min:0.01', 'max:999.99'],
            'fixed_amount' => ['required_if:calculation_type,fixed', 'nullable', 'numeric', 'min:0.01', 'max:999999.99'],
            'is_active' => ['boolean'],
            'application_mode' => ['required', Rule::in(['per_hour', 'per_day', 'one_time'])],
            'authorization_type' => ['nullable', Rule::in(['overtime', 'night_shift', 'holiday_worked', 'special'])],
            'attendance_pull_rule' => ['nullable', Rule::in([CompensationType::PULL_RULE_MEAL, CompensationType::PULL_RULE_WEEKEND])],
            'priority' => ['integer', 'min:0'],
            'position_ids' => ['nullable', 'array'],
            'position_ids.*' => ['exists:positions,id'],
            'position_percentages' => ['nullable', 'array'],
            'position_fixed_amounts' => ['nullable', 'array'],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['exists:departments,id'],
            'department_percentages' => ['nullable', 'array'],
            'department_fixed_amounts' => ['nullable', 'array'],
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['exists:employees,id'],
            'employee_percentages' => ['nullable', 'array'],
            'employee_fixed_amounts' => ['nullable', 'array'],
        ]);

        $compensationType->update(
            collect($validated)->only([
                'name', 'code', 'description', 'calculation_type',
                'percentage_value', 'fixed_amount', 'is_active',
                'application_mode', 'authorization_type', 'attendance_pull_rule', 'priority',
            ])->toArray()
        );

        $this->syncPositions($compensationType, $request);
        $this->syncDepartments($compensationType, $request);
        $this->syncEmployees($compensationType, $request);

        return redirect()->route('compensation-types.index')
            ->with('success', 'Concepto de compensacion actualizado exitosamente.');
    }

    /**
     * Soft-deactivate the specified compensation type.
     */
    public function destroy(CompensationType $compensationType): RedirectResponse
    {
        $user = Auth::user();
        if (! $user->hasPermissionTo('compensation_types.manage')) {
            abort(403);
        }

        $compensationType->update(['is_active' => false]);

        return redirect()->route('compensation-types.index')
            ->with('success', 'Concepto de compensacion desactivado exitosamente.');
    }

    /**
     * Sync position assignments with pivot data.
     *
     * Only acts when the request actually submitted `position_ids` so forms
     * that don't manage positions won't accidentally detach existing data.
     */
    private function syncPositions(CompensationType $compensationType, Request $request): void
    {
        if (! $request->has('position_ids')) {
            return;
        }

        $positionIds = $request->position_ids ?? [];
        $syncData = [];
        foreach ($positionIds as $positionId) {
            $syncData[$positionId] = [
                'default_percentage' => $request->position_percentages[$positionId] ?? null,
                'default_fixed_amount' => $request->position_fixed_amounts[$positionId] ?? null,
            ];
        }
        $compensationType->positions()->sync($syncData);
    }

    /**
     * Sync department assignments with pivot data.
     *
     * Only acts when the request actually submitted `department_ids` so forms
     * that don't manage departments won't accidentally detach existing data.
     */
    private function syncDepartments(CompensationType $compensationType, Request $request): void
    {
        if (! $request->has('department_ids')) {
            return;
        }

        $departmentIds = $request->department_ids ?? [];
        $syncData = [];
        foreach ($departmentIds as $departmentId) {
            $syncData[$departmentId] = [
                'default_percentage' => $request->department_percentages[$departmentId] ?? null,
                'default_fixed_amount' => $request->department_fixed_amounts[$departmentId] ?? null,
            ];
        }
        $compensationType->departments()->sync($syncData);
    }

    /**
     * Sync direct employee assignments with pivot overrides.
     *
     * Uses sync() so deselected employees are detached on save.
     * Only acts when the request submitted `employee_ids`.
     */
    private function syncEmployees(CompensationType $compensationType, Request $request): void
    {
        if (! $request->has('employee_ids')) {
            return;
        }

        $employeeIds = (array) $request->input('employee_ids', []);
        $percentages = (array) $request->input('employee_percentages', []);
        $fixedAmounts = (array) $request->input('employee_fixed_amounts', []);

        $syncData = [];
        foreach ($employeeIds as $employeeId) {
            $employeeId = (int) $employeeId;
            // JSON object keys arrive as strings; check both int and string keys.
            $percentage = $percentages[$employeeId] ?? $percentages[(string) $employeeId] ?? null;
            $fixedAmount = $fixedAmounts[$employeeId] ?? $fixedAmounts[(string) $employeeId] ?? null;
            $syncData[$employeeId] = [
                'is_active' => true,
                'custom_percentage' => $percentage === '' ? null : $percentage,
                'custom_fixed_amount' => $fixedAmount === '' ? null : $fixedAmount,
            ];
        }
        $compensationType->employees()->sync($syncData);
    }
}
