<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\DeliveryPeriod;
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
 * "Personal de entregas" por rango de fechas (Dani 2026-07-28).
 *
 * RRHH elige un rango (de qué fecha a qué fecha) y marca quiénes salieron a
 * entregas. A los marcados, su velada y tiempo extra AUTORIZADOS se pagan/
 * reflejan completos en esas fechas (sin topar contra la checada).
 */
class DeliveryController extends Controller
{
    public function __construct(
        private ZktecoSyncService $sync,
        private PayrollInvalidationService $invalidation,
    ) {}

    /** Pantalla: elige un rango y marca a quiénes salieron a entregas. */
    public function index(Request $request): Response
    {
        $this->authorizeAccess();

        [$from, $to] = $this->resolveRange($request);

        // Marcados para ESTE rango exacto (misma base que el guardado por rango).
        $marked = DeliveryPeriod::where('start_date', $from)
            ->where('end_date', $to)
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
            'from' => $from,
            'to' => $to,
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
     * Guardar la selección de un rango (sincroniza para ese rango exacto:
     * agrega los nuevos, quita los desmarcados) y recalcula la asistencia de los
     * afectados para que la velada/tiempo extra se destope al vuelo.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAccess();

        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'employee_ids' => ['array'],
            'employee_ids.*' => ['integer', 'exists:employees,id'],
        ]);

        $from = Carbon::parse($validated['start_date'])->toDateString();
        $to = Carbon::parse($validated['end_date'])->toDateString();
        $wanted = collect($validated['employee_ids'] ?? [])->unique()->values();

        $current = DeliveryPeriod::where('start_date', $from)->where('end_date', $to)->pluck('employee_id');
        $toAdd = $wanted->diff($current);
        $toRemove = $current->diff($wanted);

        DB::transaction(function () use ($from, $to, $toAdd, $toRemove) {
            if ($toRemove->isNotEmpty()) {
                DeliveryPeriod::where('start_date', $from)->where('end_date', $to)
                    ->whereIn('employee_id', $toRemove)
                    ->delete();
            }
            foreach ($toAdd as $employeeId) {
                DeliveryPeriod::create([
                    'employee_id' => $employeeId,
                    'start_date' => $from,
                    'end_date' => $to,
                    'created_by' => Auth::id(),
                ]);
            }
        });

        // Recalcular la asistencia de los afectados en el rango, para que la
        // velada/tiempo extra se destope o se retope al vuelo.
        $this->recalculateRange($toAdd->merge($toRemove)->unique(), $from, $to);

        return redirect()->route('deliveries.index', ['from' => $from, 'to' => $to])
            ->with('success', "Se guardó el personal de entregas del {$from} al {$to} ({$wanted->count()} colaborador(es)).");
    }

    /**
     * Rango a mostrar: el que venga por query, o por defecto la semana actual
     * (lunes a domingo).
     *
     * @return array{0: string, 1: string}
     */
    private function resolveRange(Request $request): array
    {
        $from = $request->input('from')
            ? Carbon::parse($request->input('from'))->toDateString()
            : Carbon::now()->startOfWeek()->toDateString();

        $to = $request->input('to')
            ? Carbon::parse($request->input('to'))->toDateString()
            : Carbon::now()->endOfWeek()->toDateString();

        // Nunca un rango invertido.
        if ($to < $from) {
            $to = $from;
        }

        return [$from, $to];
    }

    /**
     * Recalcula la asistencia del rango para los empleados dados y marca la
     * nómina de esas fechas para recálculo.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $employeeIds
     */
    private function recalculateRange($employeeIds, string $from, string $to): void
    {
        if ($employeeIds->isEmpty()) {
            return;
        }

        $records = AttendanceRecord::whereIn('employee_id', $employeeIds)
            ->whereBetween('work_date', [$from, $to])
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
