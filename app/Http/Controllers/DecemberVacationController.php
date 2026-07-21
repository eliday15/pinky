<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\DecemberVacationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuración del cierre obligatorio de diciembre (Dani 2026-07-17).
 *
 * El Administrador define, una vez al año, cuántos días de vacaciones son
 * obligatorios para el cierre — el mismo número para toda la empresa — y los
 * aplica. Esos días quedan apartados y no se pueden solicitar en otra fecha.
 */
class DecemberVacationController extends Controller
{
    public function __construct(private DecemberVacationService $service) {}

    /** Pantalla de configuración con el impacto previsto. */
    public function index(Request $request): Response
    {
        $this->authorizeAccess();

        $days = (int) ($request->integer('dias') ?: $this->service->configuredDays());

        return Inertia::render('Settings/DecemberVacation', [
            'configuredDays' => $this->service->configuredDays(),
            'appliedYear' => $this->service->appliedYear(),
            'previewDays' => $days,
            'preview' => $days > 0 ? $this->service->preview($days) : null,
            'currentYear' => (int) now()->year,
            'affected' => $this->affectedSample(),
        ]);
    }

    /** Aplicar los días obligatorios a toda la empresa. */
    public function apply(Request $request): RedirectResponse
    {
        $this->authorizeAccess();

        $validated = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:31'],
        ]);

        $stats = $this->service->apply($validated['days']);

        return redirect()->route('settings.december-vacation')->with(
            'success',
            "Se apartaron {$validated['days']} dias a {$stats['total']} colaboradores. ".
            "Con adelanto (nuevo ingreso): {$stats['con_adelanto']} ({$stats['dias_adelantados']} dias)."
        );
    }

    /** Liberar los días apartados y los adelantos pendientes. */
    public function clear(): RedirectResponse
    {
        $this->authorizeAccess();

        $count = $this->service->clear();

        return redirect()->route('settings.december-vacation')
            ->with('success', "Se liberaron los dias apartados de {$count} colaboradores.");
    }

    /** Saldar las deudas de adelanto de quienes ya generaron derecho. */
    public function settle(): RedirectResponse
    {
        $this->authorizeAccess();

        $result = $this->service->settleAdvances();

        return redirect()->route('settings.december-vacation')->with(
            'success',
            "Se saldaron {$result['dias']} dias adelantados de {$result['empleados']} colaboradores."
        );
    }

    /**
     * Muestra de colaboradores con días apartados o adelantados, para que el
     * Administrador vea el resultado sin salir de la pantalla.
     *
     * @return array<int, array<string, mixed>>
     */
    private function affectedSample(): array
    {
        return Employee::where('status', 'active')
            ->where(function ($q) {
                $q->where('vacation_days_reserved', '>', 0)
                    ->orWhere('vacation_days_advanced', '>', 0);
            })
            ->orderByDesc('vacation_days_advanced')
            ->orderBy('full_name')
            ->limit(50)
            ->get()
            ->map(fn (Employee $e) => [
                'id' => $e->id,
                'full_name' => $e->full_name,
                'employee_number' => $e->employee_number,
                'entitled' => (int) $e->vacation_days_entitled,
                'used' => (int) $e->vacation_days_used,
                'reserved' => (int) $e->vacation_days_reserved,
                'advanced' => (int) $e->vacation_days_advanced,
                'for_enjoyment' => $e->vacation_days_for_enjoyment,
                'available' => $e->vacation_days_remaining,
                'is_new_hire' => $e->isNewHire(),
            ])
            ->all();
    }

    private function authorizeAccess(): void
    {
        if (! Auth::user()->hasPermissionTo('settings.edit')) {
            abort(403);
        }
    }
}
