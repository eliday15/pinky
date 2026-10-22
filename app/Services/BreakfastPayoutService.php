<?php

namespace App\Services;

use App\Models\BreakfastClaim;
use App\Models\BreakfastPayout;
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cortes de pago al vendedor de desayunos (Luis 2026-10-22).
 *
 * Quien paga elige el rango (por defecto, del día siguiente al último corte
 * hasta hoy) y puede cortar antes por descansos o festivos. Reglas:
 *  - Los cortes son consecutivos: nunca se paga una fecha igual o anterior al
 *    último corte, y no se puede saltar días que tengan desayunos sin pagar.
 *  - No se paga a futuro (Hasta ≤ hoy).
 *  - Solo el ÚLTIMO corte se puede deshacer, para corregir un error de captura.
 */
class BreakfastPayoutService
{
    /**
     * Primer día que todavía se puede pagar (día siguiente al último corte), o
     * null si nunca se ha registrado un corte.
     */
    public function nextStartDate(): ?string
    {
        $lastEnd = BreakfastPayout::max('end_date');

        return $lastEnd ? Carbon::parse($lastEnd)->addDay()->toDateString() : null;
    }

    /**
     * Registra el corte del rango y marca sus desayunos como pagados.
     *
     * @throws ValidationException Si el rango rompe alguna de las reglas de la clase.
     */
    public function register(string $startDate, string $endDate, User $user): BreakfastPayout
    {
        return DB::transaction(function () use ($startDate, $endDate, $user) {
            // Bloquea el último corte para que dos clics simultáneos no paguen
            // el mismo rango dos veces.
            $last = BreakfastPayout::orderByDesc('end_date')->lockForUpdate()->first();
            $start = Carbon::parse($startDate)->startOfDay();
            $end = Carbon::parse($endDate)->startOfDay();

            if ($end->gt(Carbon::today())) {
                $this->fail('end_date', 'No se puede pagar a futuro: la fecha final debe ser hoy o antes.');
            }
            if ($end->lt($start)) {
                $this->fail('end_date', 'La fecha final debe ser igual o posterior a la inicial.');
            }

            if ($last) {
                $nextStart = Carbon::parse($last->end_date)->addDay();
                if ($start->lt($nextStart)) {
                    $this->fail('start_date', 'Ya se pagó hasta el '.Carbon::parse($last->end_date)->format('d/m/Y')
                        .'. El siguiente pago empieza el '.$nextStart->format('d/m/Y').'.');
                }

                $skipped = BreakfastClaim::whereNull('breakfast_payout_id')
                    ->whereBetween('claim_date', [$nextStart->toDateString(), $start->copy()->subDay()->toDateString()])
                    ->count();
                if ($start->gt($nextStart) && $skipped > 0) {
                    $this->fail('start_date', "Del {$nextStart->format('d/m/Y')} al {$start->copy()->subDay()->format('d/m/Y')} hay "
                        ."{$skipped} desayuno(s) sin pagar que quedarían fuera. Empieza el pago el {$nextStart->format('d/m/Y')}.");
                }
            }

            $claims = BreakfastClaim::whereNull('breakfast_payout_id')
                ->dateBetween($start->toDateString(), $end->toDateString())
                ->lockForUpdate()
                ->get(['id', 'unit_cost']);
            if ($claims->isEmpty()) {
                $this->fail('start_date', 'No hay desayunos sin pagar en ese rango.');
            }

            $vendorId = (int) SystemSetting::get('breakfast_vendor_employee_id', 0);
            $payout = BreakfastPayout::create([
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'claims_count' => $claims->count(),
                'amount' => round((float) $claims->sum('unit_cost'), 2),
                'vendor_employee_id' => $vendorId > 0 ? $vendorId : null,
                'paid_by' => $user->id,
            ]);

            BreakfastClaim::whereIn('id', $claims->pluck('id'))->update(['breakfast_payout_id' => $payout->id]);

            return $payout;
        });
    }

    /**
     * Deshace el corte (solo el último) y libera sus desayunos.
     *
     * @throws ValidationException Si no es el último corte.
     */
    public function undo(BreakfastPayout $payout): void
    {
        DB::transaction(function () use ($payout) {
            $last = BreakfastPayout::orderByDesc('end_date')->lockForUpdate()->first();
            if (! $last || $last->id !== $payout->id) {
                $this->fail('payout', 'Solo se puede deshacer el último pago registrado.');
            }

            BreakfastClaim::where('breakfast_payout_id', $payout->id)->update(['breakfast_payout_id' => null]);
            $payout->delete();
        });
    }

    /**
     * @throws ValidationException
     */
    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
