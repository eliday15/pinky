<?php

namespace App\Http\Controllers;

use App\Models\CompensationType;
use App\Models\Department;
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
            'position_ids' => ['nullable', 'array'],
            'position_ids.*' => ['exists:positions,id'],
            'position_percentages' => ['nullable', 'array'],
            'position_fixed_amounts' => ['nullable', 'array'],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['exists:departments,id'],
            'department_percentages' => ['nullable', 'array'],
            'department_fixed_amounts' => ['nullable', 'array'],
        ]);

        $compensationType = CompensationType::create(
            collect($validated)->only(['name', 'code', 'description', 'calculation_type', 'percentage_value', 'fixed_amount', 'is_active'])->toArray()
        );

        // Sync positions
        $this->syncPositions($compensationType, $request);

        // Sync departments
        $this->syncDepartments($compensationType, $request);

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

        $compensationType->load(['positions', 'departments']);

        return Inertia::render('CompensationTypes/Edit', [
            'compensationType' => $compensationType,
            'positions' => Position::active()->get(['id', 'name', 'code']),
            'departments' => Department::active()->get(['id', 'name', 'code']),
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
            'position_ids' => ['nullable', 'array'],
            'position_ids.*' => ['exists:positions,id'],
            'position_percentages' => ['nullable', 'array'],
            'position_fixed_amounts' => ['nullable', 'array'],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['exists:departments,id'],
            'department_percentages' => ['nullable', 'array'],
            'department_fixed_amounts' => ['nullable', 'array'],
        ]);

        $compensationType->update(
            collect($validated)->only(['name', 'code', 'description', 'calculation_type', 'percentage_value', 'fixed_amount', 'is_active'])->toArray()
        );

        // Sync positions
        $this->syncPositions($compensationType, $request);

        // Sync departments
        $this->syncDepartments($compensationType, $request);

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
     */
    private function syncPositions(CompensationType $compensationType, Request $request): void
    {
        if ($request->has('position_ids') && ! empty($request->position_ids)) {
            $syncData = [];
            foreach ($request->position_ids as $positionId) {
                $syncData[$positionId] = [
                    'default_percentage' => $request->position_percentages[$positionId] ?? null,
                    'default_fixed_amount' => $request->position_fixed_amounts[$positionId] ?? null,
                ];
            }
            $compensationType->positions()->sync($syncData);
        } else {
            $compensationType->positions()->detach();
        }
    }

    /**
     * Sync department assignments with pivot data.
     */
    private function syncDepartments(CompensationType $compensationType, Request $request): void
    {
        if ($request->has('department_ids') && ! empty($request->department_ids)) {
            $syncData = [];
            foreach ($request->department_ids as $departmentId) {
                $syncData[$departmentId] = [
                    'default_percentage' => $request->department_percentages[$departmentId] ?? null,
                    'default_fixed_amount' => $request->department_fixed_amounts[$departmentId] ?? null,
                ];
            }
            $compensationType->departments()->sync($syncData);
        } else {
            $compensationType->departments()->detach();
        }
    }
}
