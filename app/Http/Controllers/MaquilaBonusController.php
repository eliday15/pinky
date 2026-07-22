<?php

namespace App\Http\Controllers;

use App\Models\Authorization;
use App\Models\CompensationType;
use App\Services\MaquilaBonusAuthorizationService;
use App\Services\MaquilaBonusMetricsService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        $rows = [];
        foreach (MaquilaBonusMetricsService::catalog() as $code => $meta) {
            $concept = $concepts->get($code);
            $counts = $concept ? ($statusCounts->get($concept->id) ?? collect()) : collect();

            $rows[] = [
                'code' => $code,
                'name' => $meta['name'],
                'description' => $meta['description'],
                'exists' => $concept !== null,
                'compensation_type_id' => $concept?->id,
                'cost_per_unit' => $concept ? (float) $concept->fixed_amount : null,
                'assigned_count' => $concept->assigned_count ?? 0,
                'approver_restricted' => ($concept->approvers_count ?? 0) > 0,
                'quantity' => $quantities[$code] ?? null,
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
