<?php

namespace App\Http\Controllers;

use App\Models\CompensationType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Schedule;
use App\Services\SupervisorResolutionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing positions (employee templates).
 */
class PositionController extends Controller
{
    /**
     * Display a listing of positions.
     */
    public function index(Request $request): Response
    {
        $user = Auth::user();
        if (! $user->hasPermissionTo('positions.manage')) {
            abort(403);
        }

        $query = Position::with(['department:id,name', 'defaultSchedule:id,name', 'supervisorPosition:id,name']);

        $query->when($request->search, function ($q, $search) {
            $q->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        })->when($request->department, function ($q, $department) {
            $q->where('department_id', $department);
        })->when($request->has('status'), function ($q) use ($request) {
            if ($request->status !== 'all') {
                $q->where('is_active', $request->status === 'active');
            }
        }, function ($q) {
            $q->where('is_active', true);
        });

        $positions = $query->withCount('employees')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Positions/Index', [
            'positions' => $positions,
            'departments' => Department::active()->get(['id', 'name']),
            'filters' => $request->only(['search', 'department', 'status']),
        ]);
    }

    /**
     * Show the form for creating a new position.
     */
    public function create(): Response
    {
        $user = Auth::user();
        if (! $user->hasPermissionTo('positions.manage')) {
            abort(403);
        }

        return Inertia::render('Positions/Create', [
            'departments' => Department::active()->get(['id', 'name']),
            'schedules' => Schedule::active()->get(['id', 'name', 'code']),
            'positions' => Position::active()->get(['id', 'name', 'code']),
            'compensationTypes' => CompensationType::active()->get(),
            'employees' => Employee::active()->orderBy('full_name')->get(['id', 'full_name', 'employee_number']),
        ]);
    }

    /**
     * Store a newly created position.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = Auth::user();
        if (! $user->hasPermissionTo('positions.manage')) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:50', 'unique:positions'],
            'description' => ['nullable', 'string', 'max:500'],
            'position_type' => ['required', Rule::in(['operativo', 'administrativo', 'gerencial', 'directivo'])],
            'base_hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'default_overtime_rate' => ['nullable', 'numeric', 'min:1'],
            'default_holiday_rate' => ['nullable', 'numeric', 'min:1'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'supervisor_position_id' => ['nullable', 'exists:positions,id'],
            'default_schedule_id' => ['nullable', 'exists:schedules,id'],
            'anchor_employee_id' => ['nullable', 'exists:employees,id'],
            'compensation_type_ids' => ['nullable', 'array'],
            'compensation_type_ids.*' => ['exists:compensation_types,id'],
            'compensation_type_percentages' => ['nullable', 'array'],
            'compensation_type_fixed_amounts' => ['nullable', 'array'],
        ]);

        // Validate no circular supervisor reference
        if (isset($validated['supervisor_position_id'])) {
            $this->validateNoCycle($validated['supervisor_position_id'], null);
        }

        $position = Position::create(collect($validated)->except(['compensation_type_ids', 'compensation_type_percentages', 'compensation_type_fixed_amounts'])->toArray());

        // Sync compensation types
        if ($request->has('compensation_type_ids')) {
            $syncData = [];
            foreach ($request->compensation_type_ids as $typeId) {
                $syncData[$typeId] = [
                    'default_percentage' => $request->compensation_type_percentages[$typeId] ?? null,
                    'default_fixed_amount' => $request->compensation_type_fixed_amounts[$typeId] ?? null,
                ];
            }
            $position->compensationTypes()->sync($syncData);
        }

        return redirect()->route('positions.index')
            ->with('success', 'Puesto creado exitosamente.');
    }

    /**
     * Display the specified position.
     */
    public function show(Position $position): Response
    {
        $user = Auth::user();
        if (! $user->hasPermissionTo('positions.manage')) {
            abort(403);
        }

        $position->load([
            'department:id,name',
            'defaultSchedule:id,name,entry_time,exit_time',
            'supervisorPosition:id,name',
            'subordinatePositions:id,name,code',
            'compensationTypes',
            'employees' => function ($q) {
                $q->active()->select(['id', 'full_name', 'employee_number', 'position_id', 'department_id'])
                    ->with('department:id,name')
                    ->limit(50);
            },
        ]);
        $position->loadCount('employees');

        return Inertia::render('Positions/Show', [
            'position' => $position,
        ]);
    }

    /**
     * Show the form for editing the specified position.
     */
    public function edit(Position $position): Response
    {
        $user = Auth::user();
        if (! $user->hasPermissionTo('positions.manage')) {
            abort(403);
        }

        $position->load(['compensationTypes', 'anchorEmployee']);

        return Inertia::render('Positions/Edit', [
            'position' => $position,
            'departments' => Department::active()->get(['id', 'name']),
            'schedules' => Schedule::active()->get(['id', 'name', 'code']),
            'positions' => Position::active()->where('id', '!=', $position->id)->get(['id', 'name', 'code']),
            'compensationTypes' => CompensationType::active()->get(),
            'employees' => Employee::active()->orderBy('full_name')->get(['id', 'full_name', 'employee_number']),
        ]);
    }

    /**
     * Update the specified position.
     */
    public function update(Request $request, Position $position): RedirectResponse
    {
        $user = Auth::user();
        if (! $user->hasPermissionTo('positions.manage')) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:50', Rule::unique('positions')->ignore($position->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'position_type' => ['required', Rule::in(['operativo', 'administrativo', 'gerencial', 'directivo'])],
            'base_hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'default_overtime_rate' => ['nullable', 'numeric', 'min:1'],
            'default_holiday_rate' => ['nullable', 'numeric', 'min:1'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'supervisor_position_id' => ['nullable', 'exists:positions,id', Rule::notIn([$position->id])],
            'default_schedule_id' => ['nullable', 'exists:schedules,id'],
            'anchor_employee_id' => ['nullable', 'exists:employees,id'],
            'compensation_type_ids' => ['nullable', 'array'],
            'compensation_type_ids.*' => ['exists:compensation_types,id'],
            'compensation_type_percentages' => ['nullable', 'array'],
            'compensation_type_fixed_amounts' => ['nullable', 'array'],
        ]);

        // Validate no circular supervisor reference
        if (isset($validated['supervisor_position_id'])) {
            $this->validateNoCycle($validated['supervisor_position_id'], $position->id);
        }

        $position->update(collect($validated)->except(['compensation_type_ids', 'compensation_type_percentages', 'compensation_type_fixed_amounts'])->toArray());

        // Sync compensation types
        if ($request->has('compensation_type_ids')) {
            $syncData = [];
            foreach ($request->compensation_type_ids as $typeId) {
                $syncData[$typeId] = [
                    'default_percentage' => $request->compensation_type_percentages[$typeId] ?? null,
                    'default_fixed_amount' => $request->compensation_type_fixed_amounts[$typeId] ?? null,
                ];
            }
            $position->compensationTypes()->sync($syncData);
        } else {
            $position->compensationTypes()->detach();
        }

        // Resync supervisor assignments for employees with this position
        app(SupervisorResolutionService::class)->resyncAllForPosition($position->id);

        return redirect()->route('positions.index')
            ->with('success', 'Puesto actualizado exitosamente.');
    }

    /**
     * Soft-deactivate the specified position.
     */
    public function destroy(Position $position): RedirectResponse
    {
        $user = Auth::user();
        if (! $user->hasPermissionTo('positions.manage')) {
            abort(403);
        }

        if ($position->employees()->where('status', 'active')->exists()) {
            return back()->with('error', 'No se puede desactivar un puesto con empleados activos asignados.');
        }

        $position->update(['is_active' => false]);

        return redirect()->route('positions.index')
            ->with('success', 'Puesto desactivado exitosamente.');
    }

    /**
     * Validate that setting a supervisor position doesn't create a cycle.
     *
     * Args:
     *     supervisorPositionId: The proposed supervisor position ID
     *     currentPositionId: The current position being edited (null for create)
     */
    private function validateNoCycle(int $supervisorPositionId, ?int $currentPositionId): void
    {
        if (! $currentPositionId) {
            return;
        }

        $visited = [$currentPositionId];
        $checkId = $supervisorPositionId;

        while ($checkId) {
            if (in_array($checkId, $visited)) {
                abort(422, 'La jerarquia de puestos crearia un ciclo. Seleccione otro puesto supervisor.');
            }
            $visited[] = $checkId;
            $checkId = Position::where('id', $checkId)->value('supervisor_position_id');
        }
    }
}
