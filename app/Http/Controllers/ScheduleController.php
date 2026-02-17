<?php

namespace App\Http\Controllers;

use App\Models\Schedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing employee schedules.
 */
class ScheduleController extends Controller
{
    /**
     * Display a listing of schedules.
     */
    public function index(Request $request): Response
    {
        $user = Auth::user();
        if (! $user->hasPermissionTo('schedules.manage')) {
            abort(403);
        }

        $query = Schedule::query();

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

        $schedules = $query->withCount('employees')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Schedules/Index', [
            'schedules' => $schedules,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    /**
     * Show the form for creating a new schedule.
     */
    public function create(): Response
    {
        $user = Auth::user();
        if (! $user->hasPermissionTo('schedules.manage')) {
            abort(403);
        }

        return Inertia::render('Schedules/Create');
    }

    /**
     * Store a newly created schedule.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = Auth::user();
        if (! $user->hasPermissionTo('schedules.manage')) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:50', 'unique:schedules'],
            'description' => ['nullable', 'string', 'max:500'],
            'entry_time' => ['required', 'date_format:H:i'],
            'exit_time' => ['required', 'date_format:H:i'],
            'break_start' => ['nullable', 'date_format:H:i'],
            'break_end' => ['nullable', 'date_format:H:i'],
            'break_minutes' => ['required', 'integer', 'min:0'],
            'late_tolerance_minutes' => ['required', 'integer', 'min:0'],
            'daily_work_hours' => ['required', 'numeric', 'min:1', 'max:24'],
            'is_flexible' => ['boolean'],
            'is_active' => ['boolean'],
            'working_days' => ['required', 'array', 'min:1'],
            'working_days.*' => ['string', Rule::in(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])],
            'day_schedules' => ['nullable', 'array'],
            'day_schedules.*.entry_time' => ['nullable', 'date_format:H:i'],
            'day_schedules.*.exit_time' => ['nullable', 'date_format:H:i'],
            'day_schedules.*.break_start' => ['nullable', 'date_format:H:i'],
            'day_schedules.*.break_end' => ['nullable', 'date_format:H:i'],
            'day_schedules.*.break_minutes' => ['nullable', 'integer', 'min:0'],
            'day_schedules.*.daily_work_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
        ]);

        // Clean empty day_schedules entries
        if (isset($validated['day_schedules'])) {
            $validated['day_schedules'] = array_filter($validated['day_schedules'], fn($day) => !empty(array_filter($day)));
            if (empty($validated['day_schedules'])) {
                $validated['day_schedules'] = null;
            }
        }

        Schedule::create($validated);

        return redirect()->route('schedules.index')
            ->with('success', 'Horario creado exitosamente.');
    }

    /**
     * Display the specified schedule.
     */
    public function show(Schedule $schedule): Response
    {
        $user = Auth::user();
        if (! $user->hasPermissionTo('schedules.manage')) {
            abort(403);
        }

        $schedule->loadCount('employees');
        $schedule->load(['employees' => function ($q) {
            $q->active()->select(['id', 'full_name', 'employee_number', 'schedule_id', 'department_id'])
                ->with('department:id,name')
                ->limit(50);
        }]);

        return Inertia::render('Schedules/Show', [
            'schedule' => $schedule,
        ]);
    }

    /**
     * Show the form for editing the specified schedule.
     */
    public function edit(Schedule $schedule): Response
    {
        $user = Auth::user();
        if (! $user->hasPermissionTo('schedules.manage')) {
            abort(403);
        }

        return Inertia::render('Schedules/Edit', [
            'schedule' => $schedule,
        ]);
    }

    /**
     * Update the specified schedule.
     */
    public function update(Request $request, Schedule $schedule): RedirectResponse
    {
        $user = Auth::user();
        if (! $user->hasPermissionTo('schedules.manage')) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:50', Rule::unique('schedules')->ignore($schedule->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'entry_time' => ['required', 'date_format:H:i'],
            'exit_time' => ['required', 'date_format:H:i'],
            'break_start' => ['nullable', 'date_format:H:i'],
            'break_end' => ['nullable', 'date_format:H:i'],
            'break_minutes' => ['required', 'integer', 'min:0'],
            'late_tolerance_minutes' => ['required', 'integer', 'min:0'],
            'daily_work_hours' => ['required', 'numeric', 'min:1', 'max:24'],
            'is_flexible' => ['boolean'],
            'is_active' => ['boolean'],
            'working_days' => ['required', 'array', 'min:1'],
            'working_days.*' => ['string', Rule::in(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])],
            'day_schedules' => ['nullable', 'array'],
            'day_schedules.*.entry_time' => ['nullable', 'date_format:H:i'],
            'day_schedules.*.exit_time' => ['nullable', 'date_format:H:i'],
            'day_schedules.*.break_start' => ['nullable', 'date_format:H:i'],
            'day_schedules.*.break_end' => ['nullable', 'date_format:H:i'],
            'day_schedules.*.break_minutes' => ['nullable', 'integer', 'min:0'],
            'day_schedules.*.daily_work_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
        ]);

        // Clean empty day_schedules entries
        if (isset($validated['day_schedules'])) {
            $validated['day_schedules'] = array_filter($validated['day_schedules'], fn($day) => !empty(array_filter($day)));
            if (empty($validated['day_schedules'])) {
                $validated['day_schedules'] = null;
            }
        }

        $schedule->update($validated);

        return redirect()->route('schedules.index')
            ->with('success', 'Horario actualizado exitosamente.');
    }

    /**
     * Soft-deactivate the specified schedule.
     */
    public function destroy(Schedule $schedule): RedirectResponse
    {
        $user = Auth::user();
        if (! $user->hasPermissionTo('schedules.manage')) {
            abort(403);
        }

        if ($schedule->employees()->where('status', 'active')->exists()) {
            return back()->with('error', 'No se puede desactivar un horario con empleados activos asignados.');
        }

        $schedule->update(['is_active' => false]);

        return redirect()->route('schedules.index')
            ->with('success', 'Horario desactivado exitosamente.');
    }
}
