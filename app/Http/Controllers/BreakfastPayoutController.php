<?php

namespace App\Http\Controllers;

use App\Models\BreakfastPayout;
use App\Services\BreakfastPayoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Cortes de pago al vendedor de desayunos: registrar un pago por rango de
 * fechas y deshacer el último si se capturó mal.
 */
class BreakfastPayoutController extends Controller
{
    public function __construct(
        private readonly BreakfastPayoutService $service,
    ) {}

    /**
     * Registra el pago de los desayunos sin pagar del rango elegido.
     */
    public function store(Request $request): RedirectResponse
    {
        if (! auth()->user()->hasPermissionTo('breakfasts.pay')) {
            abort(403);
        }

        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date'],
        ]);

        $payout = $this->service->register($validated['start_date'], $validated['end_date'], auth()->user());

        return redirect()->back()->with('success', "Pago registrado: {$payout->claims_count} desayunos, $"
            .number_format((float) $payout->amount, 2).'.');
    }

    /**
     * Deshace el último corte y deja sus desayunos otra vez sin pagar.
     */
    public function destroy(BreakfastPayout $payout): RedirectResponse
    {
        if (! auth()->user()->hasPermissionTo('breakfasts.pay')) {
            abort(403);
        }

        $this->service->undo($payout);

        return redirect()->back()->with('success', 'Pago deshecho; sus desayunos quedaron sin pagar.');
    }
}
