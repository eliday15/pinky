<?php

namespace App\Http\Controllers;

use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Employee;
use App\Services\MaquilaBonusAuthorizationService;
use App\Services\MaquilaBonusMetricsService;
use App\Services\CompensationRateResolverService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pantalla de los bonos de maquila: muestra los 5 conceptos, su costo por
 * unidad y empleados asignados, la CANTIDAD del mes calculada en vivo desde
 * basemaquila, y el estado de las autorizaciones ya generadas. Permite
 * disparar la generación manual de un mes (además del job automático del día 1).
 *
 * La cantidad se llena sola; el superadmin fija el costo y aprueba. Asignar
 * empleados, costo por unidad y aprobadores se hace en la pantalla de
 * CompensationTypes (Editar concepto).
 */
class MaquilaBonusController extends Controller
{
    public function __construct(
        private readonly MaquilaBonusMetricsService $metrics,
        private readonly MaquilaBonusAuthorizationService $generator,
        private readonly CompensationRateResolverService $rateResolver,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeAccess();

        $month = $this->resolveMonth($request->query('month'));

        return Inertia::render('MaquilaBonuses/Index', [
            'month' => $month->format('Y-m'),
            'monthLabel' => $month->locale('es')->translatedFormat('F Y'),
            'concepts' => $this->conceptRows($month),
            'metricsError' => $this->metricsError,
        ]);
    }

    /** Genera/actualiza las autorizaciones pendientes del mes elegido. */
    public function generate(Request $request): RedirectResponse
    {
        $this->authorizeAccess();

        $validated = $request->validate([
            'month' => ['required', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $month = Carbon::createFromFormat('Y-m-d', $validated['month'] . '-01')->startOfMonth();

        try {
            $summary = $this->generator->generateForMonth(
                (int) $month->year,
                (int) $month->month,
                requestedBy: Auth::id(),
            );
        } catch (\Throwable $e) {
            return redirect()->route('maquila-bonuses.index', ['month' => $month->format('Y-m')])
                ->with('error', 'No se pudieron generar los bonos: ' . $e->getMessage());
        }

        $created = array_sum(array_column($summary, 'created'));
        $updated = array_sum(array_column($summary, 'updated'));
        $locked = array_sum(array_column($summary, 'locked'));
        $label = $month->locale('es')->translatedFormat('F Y');

        return redirect()->route('maquila-bonuses.index', ['month' => $month->format('Y-m')])->with(
            'success',
            "Bonos de {$label}: {$created} autorizaciones creadas, {$updated} actualizadas" .
            ($locked > 0 ? ", {$locked} ya aprobadas/pagadas (sin tocar)." : '.'),
        );
    }

    /**
     * Asigna qué cortador cobra un empleado en un concepto.
     *
     * Luis 2026-10-08: "cada quien cobra lo que cortó según la lista de
     * cortadores". Antes había un solo nombre por concepto y su conteo se le
     * pagaba COMPLETO a cada empleado asignado — con dos operadores, cada uno
     * terminaba cobrando también las órdenes del otro.
     */
    public function saveCortador(Request $request): RedirectResponse
    {
        $this->authorizeAccess();

        $validated = $request->validate([
            'code' => ['required', Rule::in(MaquilaBonusMetricsService::cortador2FilteredCodes())],
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'cortador' => ['nullable', 'string', 'max:300'],
            'month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $name = trim((string) ($validated['cortador'] ?? ''));

        $this->metrics->setCortadorForEmployee(
            $validated['code'],
            (int) $validated['employee_id'],
            $name,
        );

        $employee = Employee::find($validated['employee_id']);

        return redirect()->route('maquila-bonuses.index', array_filter(['month' => $validated['month'] ?? null]))
            ->with('success', $name !== ''
                ? "{$employee?->full_name} cobra las órdenes de «{$name}»."
                : "{$employee?->full_name} se quedó sin cortador asignado: no se le genera nada hasta que le asignes uno.");
    }

    private ?string $metricsError = null;

    /**
     * Filas por concepto: costo/unidad, empleados asignados, restricción de
     * aprobador, cantidad del mes (en vivo) y estado de sus autorizaciones.
     *
     * @return array<int, array<string, mixed>>
     */
    private function conceptRows(Carbon $month): array
    {
        $codes = array_keys(MaquilaBonusMetricsService::catalog());

        $concepts = CompensationType::whereIn('code', $codes)
            ->with([
                'positions',
                'departments',
                'employees' => fn ($q) => $q
                    ->where('employee_compensation_type.is_active', true)
                    ->with(['compensationTypes', 'position', 'department']),
            ])
            ->withCount([
                'employees as assigned_count' => fn ($q) => $q->where('employee_compensation_type.is_active', true),
                'approvers',
            ])
            ->get()
            ->keyBy('code');

        $quantities = [];
        try {
            $quantities = $this->metrics->metricsForMonth((int) $month->year, (int) $month->month);
        } catch (\Throwable $e) {
            $this->metricsError = 'No se pudo consultar basemaquila (revisa el túnel): ' . $e->getMessage();
        }

        $groupId = sprintf('MAQBONO-%04d-%02d', $month->year, $month->month);
        $statusCounts = Authorization::where('bulk_group_id', $groupId)
            ->selectRaw('compensation_type_id, status, COUNT(*) as n')
            ->groupBy('compensation_type_id', 'status')
            ->get()
            ->groupBy('compensation_type_id');

        $cortador2Codes = MaquilaBonusMetricsService::cortador2FilteredCodes();

        $rows = [];
        foreach (MaquilaBonusMetricsService::catalog() as $code => $meta) {
            $concept = $concepts->get($code);
            $counts = $concept ? ($statusCounts->get($concept->id) ?? collect()) : collect();
            $supportsCortador2 = in_array($code, $cortador2Codes, true);
            $quantity = $quantities[$code] ?? null;
            // En los conceptos por cortador cada empleado cobra SOLO lo que él
            // cortó: su cantidad sale de su propio nombre en cortador2.
            $cortadorMap = $supportsCortador2 ? $this->metrics->cortadorMapFor($code) : [];

            $employeeRates = $concept?->employees
                ->map(function ($employee) use ($concept, $quantity, $supportsCortador2, $cortadorMap, $month) {
                    $rate = $this->rateResolver->resolveRate($employee, $concept);
                    $unitRate = (float) ($rate['fixed_amount'] ?? 0);
                    $cortador = $supportsCortador2 ? ($cortadorMap[$employee->id] ?? '') : '';
                    $employeeQuantity = $quantity;

                    if ($supportsCortador2) {
                        $employeeQuantity = null;

                        if ($cortador !== '') {
                            try {
                                $employeeQuantity = $this->metrics->quantityForCortador(
                                    $concept->code,
                                    (int) $month->year,
                                    (int) $month->month,
                                    $cortador,
                                );
                            } catch (\Throwable $e) {
                                $this->metricsError ??= 'No se pudo consultar basemaquila (revisa el túnel): '.$e->getMessage();
                            }
                        }
                    }

                    return [
                        'employee_id' => $employee->id,
                        'name' => $employee->full_name,
                        'unit_rate' => $unitRate,
                        'cortador' => $cortador,
                        'quantity' => $employeeQuantity,
                        'estimated_payout' => $employeeQuantity === null ? null : round($unitRate * $employeeQuantity, 2),
                    ];
                })
                ->values() ?? collect();
            $unitRates = $employeeRates->pluck('unit_rate');
            $payouts = $employeeRates->pluck('estimated_payout')->filter(fn ($value) => $value !== null);

            $rows[] = [
                'code' => $code,
                'name' => $meta['name'],
                'description' => $meta['description'],
                'exists' => $concept !== null,
                'compensation_type_id' => $concept?->id,
                'cost_per_unit' => $concept ? (float) $concept->fixed_amount : null,
                'assigned_count' => $concept->assigned_count ?? 0,
                'approver_restricted' => ($concept->approvers_count ?? 0) > 0,
                'quantity' => $quantity,
                'effective_unit_rate_min' => $unitRates->isEmpty() ? null : (float) $unitRates->min(),
                'effective_unit_rate_max' => $unitRates->isEmpty() ? null : (float) $unitRates->max(),
                'estimated_payout_min' => $payouts->isEmpty() ? null : (float) $payouts->min(),
                'estimated_payout_max' => $payouts->isEmpty() ? null : (float) $payouts->max(),
                'estimated_total' => $payouts->isEmpty() ? null : round((float) $payouts->sum(), 2),
                'employee_payouts' => $employeeRates->all(),
                'supports_cortador2_filter' => $supportsCortador2,
                // Nombres que existen de verdad en cortador2: se eligen de una
                // lista en vez de escribirlos (una letra de más = conteo en 0).
                'available_cortadores' => $supportsCortador2 ? $this->availableCortadores($code) : [],
                'authorizations' => [
                    'pending' => (int) $counts->firstWhere('status', Authorization::STATUS_PENDING)?->n,
                    'approved' => (int) $counts->firstWhere('status', Authorization::STATUS_APPROVED)?->n,
                    'paid' => (int) $counts->firstWhere('status', Authorization::STATUS_PAID)?->n,
                    'rejected' => (int) $counts->firstWhere('status', Authorization::STATUS_REJECTED)?->n,
                ],
            ];
        }

        return $rows;
    }

    /**
     * Nombres de cortador que existen en basemaquila, tolerando el túnel caído
     * (la pantalla ya avisa del error y la lista sale vacía).
     *
     * @return list<string>
     */
    private function availableCortadores(string $code): array
    {
        try {
            return $this->metrics->availableCortadores($code);
        } catch (\Throwable $e) {
            $this->metricsError ??= 'No se pudo consultar basemaquila (revisa el túnel): '.$e->getMessage();

            return [];
        }
    }

    private function resolveMonth(?string $raw): Carbon
    {
        if ($raw !== null && preg_match('/^\d{4}-\d{2}$/', $raw)) {
            return Carbon::createFromFormat('Y-m-d', $raw . '-01')->startOfMonth();
        }

        return Carbon::today()->startOfMonth()->subMonthNoOverflow();
    }

    private function authorizeAccess(): void
    {
        if (! Auth::user()->hasPermissionTo('compensation_types.manage')) {
            abort(403);
        }
    }
}
