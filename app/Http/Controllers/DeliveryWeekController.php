<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\DeliveryWeek;
use App\Models\Employee;
use App\Services\PayrollInvalidationService;
use App\Services\ZktecoSyncService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Personal de entregas por semana" (Dani 2026-07-28).
 *
 * Los que salen a entregas se van turnando; cada semana RRHH selecciona quiénes
 * salieron. A los marcados, su velada y tiempo extra AUTORIZADOS se pagan/
 * reflejan completos esa semana (sin topar contra la checada).
 */
class DeliveryWeekController extends Controller
{
    public function __construct(
        private ZktecoSyncService $sync,
        private PayrollInvalidationService $invalidation,
    ) {}

    /** Pantalla: elige una semana y marca a quiénes salieron a entregas. */
    public function index(Request $request): Response
    {
        $this->authorizeAccess();

        $weekStart = DeliveryWeek::weekStartFor($request->input('week') ?: now());
        $weekEnd = Carbon::parse($weekStart)->endOfWeek()->toDateString();

        $marked = DeliveryWeek::where('week_start', $weekStart)
            ->pluck('employee_id')
            ->all();

        $employees = Employee::active()
            ->with('department:id,name')
            ->when($request->search, fn ($q, $s) => $q->where('full_name', 'like', "%{$s}%"))
            ->when($request->integer('department_id'), fn ($q, $id) => $q->where('department_id', $id))
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'employee_number', 'department_id'])
            ->map(fn (Employee $e) => [
                'id' => $e->id,
                'full_name' => $e->full_name,
                'employee_number' => $e->employee_number,
                'department' => $e->department?->name,
                'on_delivery' => in_array($e->id, $marked, true),
            ]);

        return Inertia::render('Deliveries/Index', [
            'weekStart' => $weekStart,
            'weekEnd' => $weekEnd,
            'employees' => $employees,
            'departments' => Department::active()->orderBy('name')->get(['id', 'name']),
            'filters' => [
                'search' => $request->search,
                'department_id' => $request->integer('department_id') ?: null,
            ],
            'markedCount' => count($marked),
        ]);
    }

    /**
     * Guardar la selección de una semana (sincroniza: agrega los nuevos, quita
     * los desmarcados) y recalcula la asistencia de esos colaboradores para que
     * la velada/tiempo extra se destope de inmediato.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAccess();

        $validated = $request->validate([
            'week_start' => ['required', 'date'],
            'employee_ids' => ['array'],
            'employee_ids.*' => ['integer', 'exists:employees,id'],
        ]);

        $weekStart = DeliveryWeek::weekStartFor($validated['week_start']);
        $wanted = collect($validated['employee_ids'] ?? [])->unique()->values();

        $current = DeliveryWeek::where('week_start', $weekStart)->pluck('employee_id');
        $toAdd = $wanted->diff($current);
        $toRemove = $current->diff($wanted);

        DB::transaction(function () use ($weekStart, $toAdd, $toRemove) {
            if ($toRemove->isNotEmpty()) {
                DeliveryWeek::where('week_start', $weekStart)
                    ->whereIn('employee_id', $toRemove)
                    ->delete();
            }
            foreach ($toAdd as $employeeId) {
                DeliveryWeek::create([
                    'employee_id' => $employeeId,
                    'week_start' => $weekStart,
                    'created_by' => Auth::id(),
                ]);
            }
        });

        // Recalcular la asistencia de los afectados (agregados y quitados) en la
        // semana, para que velada/tiempo extra se destope o se retope al vuelo.
        $affected = $toAdd->merge($toRemove)->unique();
        $this->recalculateWeek($affected, $weekStart);

        return redirect()->route('deliveries.index', ['week' => $weekStart])
            ->with('success', "Se guardó el personal de entregas de la semana del {$weekStart} ({$wanted->count()} colaborador(es)).");
    }

    /**
     * Recalcula la asistencia de la semana para los empleados dados y marca la
     * nómina de esas fechas para recálculo.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $employeeIds
     */
    private function recalculateWeek($employeeIds, string $weekStart): void
    {
        if ($employeeIds->isEmpty()) {
            return;
        }

        $start = Carbon::parse($weekStart);
        $end = $start->copy()->endOfWeek();

        $records = \App\Models\AttendanceRecord::whereIn('employee_id', $employeeIds)
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->get();

        foreach ($records as $record) {
            $this->sync->recalculateAttendanceRecord($record);
            $this->invalidation->invalidate($record->employee_id, Carbon::parse($record->work_date)->toDateString());
        }
    }

    private function authorizeAccess(): void
    {
        if (! Auth::user()->hasPermissionTo('deliveries.manage')) {
            abort(403);
        }
    }
}
