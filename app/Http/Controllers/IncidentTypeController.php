<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\IncidentType;
use App\Models\Position;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing incident type concepts.
 */
class IncidentTypeController extends Controller
{
    /**
     * Display a listing of incident types.
     *
     * Args:
     *     request: The HTTP request with optional search, status filters.
     *
     * Returns:
     *     Inertia response rendering IncidentTypes/Index with paginated incident types and filters.
     */
    public function index(Request $request): Response
    {
        $user = Auth::user();
        if (! $user->hasPermissionTo('incident_types.manage')) {
            abort(403);
        }

        $query = IncidentType::query();

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

        $incidentTypes = $query->withCount(['positions', 'departments'])
            ->orderBy('priority')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('IncidentTypes/Index', [
            'incidentTypes' => $incidentTypes,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    /**
     * Show the form for creating a new incident type.
     *
     * Returns:
     *     Inertia response rendering IncidentTypes/Create with positions and departments.
     */
    public function create(): Response
    {
        $user = Auth::user();
        if (! $user->hasPermissionTo('incident_types.manage')) {
            abort(403);
        }

        return Inertia::render('IncidentTypes/Create', [
            'positions' => Position::active()->get(['id', 'name', 'code']),
            'departments' => Department::active()->get(['id', 'name', 'code']),
        ]);
    }

    /**
     * Store a newly created incident type.
     *
     * Args:
     *     request: The HTTP request containing incident type data.
     *
     * Returns:
     *     Redirect to incident-types.index with a success message.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = Auth::user();
        if (! $user->hasPermissionTo('incident_types.manage')) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:50', 'unique:incident_types'],
            'description' => ['nullable', 'string', 'max:500'],
            'category' => ['required', Rule::in(['vacation', 'sick_leave', 'permission', 'absence', 'late_accumulation', 'special'])],
            'is_paid' => ['boolean'],
            'deducts_vacation' => ['boolean'],
            'requires_approval' => ['boolean'],
            'requires_document' => ['boolean'],
            'affects_attendance' => ['boolean'],
            'has_time_range' => ['boolean'],
            'uses_vacation_hours' => ['boolean'],
            'color' => ['required', 'string', 'max:20'],
            'is_active' => ['boolean'],
            'priority' => ['integer', 'min:0'],
            'position_ids' => ['nullable', 'array'],
            'position_ids.*' => ['exists:positions,id'],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['exists:departments,id'],
        ]);

        $incidentType = IncidentType::create(
            $this->forcePaidWhenVacationHours(
                collect($validated)->only([
                    'name', 'code', 'description', 'category',
                    'is_paid', 'deducts_vacation', 'requires_approval',
                    'requires_document', 'affects_attendance', 'has_time_range',
                    'uses_vacation_hours', 'color', 'is_active', 'priority',
                ])->toArray()
            )
        );

        $this->syncPositions($incidentType, $request);
        $this->syncDepartments($incidentType, $request);

        AuditLog::record(
            module: AuditLog::MODULE_CATALOGS,
            action: AuditLog::ACTION_CREATE,
            model: $incidentType,
            description: "Creo el tipo de incidencia {$incidentType->name} ({$incidentType->code})",
            subjectLabel: $incidentType->name,
        );

        return redirect()->route('incident-types.index')
            ->with('success', 'Tipo de incidencia creado exitosamente.');
    }

    /**
     * Show the form for editing the specified incident type.
     *
     * Args:
     *     incidentType: The incident type model instance to edit.
     *
     * Returns:
     *     Inertia response rendering IncidentTypes/Edit with the incident type, positions, and departments.
     */
    public function edit(IncidentType $incidentType): Response
    {
        $user = Auth::user();
        if (! $user->hasPermissionTo('incident_types.manage')) {
            abort(403);
        }

        $incidentType->load(['positions', 'departments']);

        return Inertia::render('IncidentTypes/Edit', [
            'incidentType' => $incidentType,
            'positions' => Position::active()->get(['id', 'name', 'code']),
            'departments' => Department::active()->get(['id', 'name', 'code']),
        ]);
    }

    /**
     * Update the specified incident type.
     *
     * Args:
     *     request: The HTTP request containing updated incident type data.
     *     incidentType: The incident type model instance to update.
     *
     * Returns:
     *     Redirect to incident-types.index with a success message.
     */
    public function update(Request $request, IncidentType $incidentType): RedirectResponse
    {
        $user = Auth::user();
        if (! $user->hasPermissionTo('incident_types.manage')) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:50', Rule::unique('incident_types')->ignore($incidentType->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'category' => ['required', Rule::in(['vacation', 'sick_leave', 'permission', 'absence', 'late_accumulation', 'special'])],
            'is_paid' => ['boolean'],
            'deducts_vacation' => ['boolean'],
            'requires_approval' => ['boolean'],
            'requires_document' => ['boolean'],
            'affects_attendance' => ['boolean'],
            'has_time_range' => ['boolean'],
            'uses_vacation_hours' => ['boolean'],
            'color' => ['required', 'string', 'max:20'],
            'is_active' => ['boolean'],
            'priority' => ['integer', 'min:0'],
            'position_ids' => ['nullable', 'array'],
            'position_ids.*' => ['exists:positions,id'],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['exists:departments,id'],
        ]);

        $incidentType->update(
            $this->forcePaidWhenVacationHours(
                collect($validated)->only([
                    'name', 'code', 'description', 'category',
                    'is_paid', 'deducts_vacation', 'requires_approval',
                    'requires_document', 'affects_attendance', 'has_time_range',
                    'uses_vacation_hours', 'color', 'is_active', 'priority',
                ])->toArray()
            )
        );

        $this->syncPositions($incidentType, $request);
        $this->syncDepartments($incidentType, $request);

        AuditLog::record(
            module: AuditLog::MODULE_CATALOGS,
            action: AuditLog::ACTION_UPDATE,
            model: $incidentType,
            description: "Actualizo el tipo de incidencia {$incidentType->name} ({$incidentType->code})",
            subjectLabel: $incidentType->name,
        );

        return redirect()->route('incident-types.index')
            ->with('success', 'Tipo de incidencia actualizado exitosamente.');
    }

    /**
     * Un tipo que gasta la bolsa de horas SIEMPRE es con goce de sueldo.
     *
     * Las horas salen del saldo de vacaciones, que ya es tiempo pagado; dejarlo
     * "sin goce" descontaba además el día COMPLETO del sueldo (el permiso de 3 h
     * costaba 1 día + 3 h de vacaciones). Se fuerza aquí para que la mala
     * configuración no pueda volver a entrar por la UI (Dani 2026-07-15).
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function forcePaidWhenVacationHours(array $attributes): array
    {
        if (! empty($attributes['uses_vacation_hours'])) {
            $attributes['is_paid'] = true;
        }

        return $attributes;
    }

    /**
     * Soft-deactivate the specified incident type.
     *
     * Args:
     *     incidentType: The incident type model instance to deactivate.
     *
     * Returns:
     *     Redirect to incident-types.index with a success message.
     */
    public function destroy(IncidentType $incidentType): RedirectResponse
    {
        $user = Auth::user();
        if (! $user->hasPermissionTo('incident_types.manage')) {
            abort(403);
        }

        $incidentType->update(['is_active' => false]);

        AuditLog::record(
            module: AuditLog::MODULE_CATALOGS,
            action: AuditLog::ACTION_DELETE,
            model: $incidentType,
            description: "Desactivo el tipo de incidencia {$incidentType->name} ({$incidentType->code})",
            subjectLabel: $incidentType->name,
        );

        return redirect()->route('incident-types.index')
            ->with('success', 'Tipo de incidencia desactivado exitosamente.');
    }

    /**
     * Sync position assignments for the incident type (no pivot data).
     *
     * Args:
     *     incidentType: The incident type model instance.
     *     request: The HTTP request containing position_ids.
     *
     * Returns:
     *     void
     */
    private function syncPositions(IncidentType $incidentType, Request $request): void
    {
        if ($request->has('position_ids') && ! empty($request->position_ids)) {
            $incidentType->positions()->sync($request->position_ids);
        } else {
            $incidentType->positions()->detach();
        }
    }

    /**
     * Sync department assignments for the incident type (no pivot data).
     *
     * Args:
     *     incidentType: The incident type model instance.
     *     request: The HTTP request containing department_ids.
     *
     * Returns:
     *     void
     */
    private function syncDepartments(IncidentType $incidentType, Request $request): void
    {
        if ($request->has('department_ids') && ! empty($request->department_ids)) {
            $incidentType->departments()->sync($request->department_ids);
        } else {
            $incidentType->departments()->detach();
        }
    }
}
