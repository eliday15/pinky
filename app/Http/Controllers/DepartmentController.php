<?php

namespace App\Http\Controllers;

use App\Models\Department;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing departments.
 */
class DepartmentController extends Controller
{
    /**
     * Display a listing of departments.
     */
    public function index(Request $request): Response
    {
        $user = Auth::user();
        if (! $user->hasPermissionTo('departments.manage')) {
            abort(403);
        }

        $query = Department::query();

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

        $departments = $query->withCount(['employees', 'positions'])
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Departments/Index', [
            'departments' => $departments,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    /**
     * Show the form for creating a new department.
     */
    public function create(): Response
    {
        $user = Auth::user();
        if (! $user->hasPermissionTo('departments.manage')) {
            abort(403);
        }

        return Inertia::render('Departments/Create');
    }

    /**
     * Store a newly created department.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = Auth::user();
        if (! $user->hasPermissionTo('departments.manage')) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:50', 'unique:departments'],
            'description' => ['nullable', 'string', 'max:500'],
            'default_break_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
        ]);

        Department::create($validated);

        return redirect()->route('departments.index')
            ->with('success', 'Departamento creado exitosamente.');
    }

    /**
     * Display the specified department.
     */
    public function show(Department $department): Response
    {
        $user = Auth::user();
        if (! $user->hasPermissionTo('departments.manage')) {
            abort(403);
        }

        $department->loadCount(['employees', 'positions']);
        $department->load([
            'employees' => function ($q) {
                $q->active()->select(['id', 'full_name', 'employee_number', 'department_id', 'position_id'])
                    ->with('position:id,name')
                    ->limit(50);
            },
            'positions' => function ($q) {
                $q->active()->select(['id', 'name', 'code', 'department_id']);
            },
        ]);

        return Inertia::render('Departments/Show', [
            'department' => $department,
        ]);
    }

    /**
     * Show the form for editing the specified department.
     */
    public function edit(Department $department): Response
    {
        $user = Auth::user();
        if (! $user->hasPermissionTo('departments.manage')) {
            abort(403);
        }

        return Inertia::render('Departments/Edit', [
            'department' => $department,
        ]);
    }

    /**
     * Update the specified department.
     */
    public function update(Request $request, Department $department): RedirectResponse
    {
        $user = Auth::user();
        if (! $user->hasPermissionTo('departments.manage')) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:50', Rule::unique('departments')->ignore($department->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'default_break_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
        ]);

        $department->update($validated);

        return redirect()->route('departments.index')
            ->with('success', 'Departamento actualizado exitosamente.');
    }

    /**
     * Soft-deactivate the specified department.
     */
    public function destroy(Department $department): RedirectResponse
    {
        $user = Auth::user();
        if (! $user->hasPermissionTo('departments.manage')) {
            abort(403);
        }

        if ($department->employees()->where('status', 'active')->exists()) {
            return back()->with('error', 'No se puede desactivar un departamento con empleados activos.');
        }

        $department->update(['is_active' => false]);

        return redirect()->route('departments.index')
            ->with('success', 'Departamento desactivado exitosamente.');
    }
}
