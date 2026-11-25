<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Services\Payroll\PrenominaService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PRENÓMINA (Luis 2026-11-25): pantalla gerencial con lo que va a cobrar cada
 * persona en la semanal y en la mensual ANTES de generar la nómina, filtrable
 * por departamento y por empleado, con el desglose de por qué cobra cada cosa.
 * No guarda nada: ver PrenominaService.
 */
class PrenominaController extends Controller
{
    public function __construct(private PrenominaService $prenomina) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->hasPermissionTo('payroll.preview'), 403);

        $validated = $request->validate([
            'scope' => ['nullable', 'integer', 'exists:departments,id'],
            'week_start' => ['nullable', 'date'],
            'week_end' => ['nullable', 'date', 'after_or_equal:week_start'],
            'extras_start' => ['nullable', 'date'],
            'extras_end' => ['nullable', 'date', 'after_or_equal:extras_start'],
            'without_extras' => ['nullable', 'boolean'],
        ]);

        $separate = Department::separatePayroll()->orderBy('name')->get(['id', 'name']);
        $scopeId = isset($validated['scope']) && $separate->contains('id', (int) $validated['scope'])
            ? (int) $validated['scope']
            : null;

        $defaults = $this->prenomina->defaultRange($scopeId);
        $weekStart = $validated['week_start'] ?? $defaults['week_start'];
        $weekEnd = $validated['week_end'] ?? $defaults['week_end'];
        $hasExtras = $defaults['extras_start'] !== null && ! $request->boolean('without_extras');
        $extrasStart = $hasExtras ? ($validated['extras_start'] ?? $defaults['extras_start']) : null;
        $extrasEnd = $hasExtras ? ($validated['extras_end'] ?? $defaults['extras_end']) : null;

        $withAmounts = $user->hasPermissionTo('payroll.view_complete');

        return Inertia::render('Payroll/Prenomina', [
            'scopes' => collect([['id' => null, 'name' => 'General']])
                ->merge($separate->map(fn ($d) => ['id' => $d->id, 'name' => $d->name]))
                ->values(),
            'filters' => [
                'scope' => $scopeId,
                'week_start' => $weekStart,
                'week_end' => $weekEnd,
                'extras_start' => $extrasStart,
                'extras_end' => $extrasEnd,
                'can_have_extras' => $defaults['extras_start'] !== null,
            ],
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            // El cálculo tarda (~25 s con toda la plantilla): la pantalla se
            // pinta de inmediato y el resultado llega después.
            'result' => Inertia::defer(fn () => $this->prenomina->build($scopeId, $weekStart, $weekEnd, $extrasStart, $extrasEnd, $withAmounts)),
            'withAmounts' => $withAmounts,
        ]);
    }
}
