<?php

namespace App\Services\Reports;

use App\Models\Authorization;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * ¿Qué autorizaciones aprobadas ya salieron en una nómina cerrada?
 *
 * La nómina nunca cambia el status de la autorización a "pagada" (en prod no
 * había ni una), así que el reporte de pendientes mostraba lo ya pagado
 * (Luis 2026-10-28: Adriana Vázquez y Humberto Velázquez). Se deriva del
 * recibo: una autorización cuenta como pagada si hay una nómina CERRADA
 * (aprobada, pagada o con el efectivo preparado) cuyo rango —semana o rango de
 * extras— contiene su fecha, en la que el empleado tiene recibo con ese mismo
 * concepto, y se aprobó antes de cerrarla (una captura tardía no se pagó).
 */
class PaidAuthorizationResolver
{
    /**
     * @param  Collection<int, Authorization>  $authorizations  Aprobadas, con compensationType cargado.
     * @return array<int, true> Ids de las que ya se pagaron.
     */
    public function paidIds(Collection $authorizations, string $from, string $to): array
    {
        if ($authorizations->isEmpty()) {
            return [];
        }

        $periods = PayrollPeriod::query()
            ->where(fn ($q) => $q->whereIn('status', ['approved', 'paid'])->orWhereNotNull('cash_closed_at'))
            ->where(fn ($q) => $q
                ->where(fn ($r) => $r->where('start_date', '<=', $to)->where('end_date', '>=', $from))
                ->orWhere(fn ($r) => $r->where('extras_start_date', '<=', $to)->where('extras_end_date', '>=', $from)))
            ->get()
            ->keyBy('id');
        if ($periods->isEmpty()) {
            return [];
        }

        // Conceptos que cada recibo pagó: employee_id → [[periodo, códigos]].
        $paidConcepts = PayrollEntry::whereIn('payroll_period_id', $periods->keys())
            ->whereIn('employee_id', $authorizations->pluck('employee_id')->unique())
            ->get(['employee_id', 'payroll_period_id', 'calculation_breakdown'])
            ->groupBy('employee_id')
            ->map(fn (Collection $entries) => $entries->map(fn (PayrollEntry $entry) => [
                'period' => $periods[$entry->payroll_period_id],
                'codes' => collect($entry->calculation_breakdown['compensation_concepts'] ?? [])
                    ->map(fn ($c) => strtoupper((string) ($c['code'] ?? '')))->filter()->unique()->all(),
            ]));

        $paid = [];
        foreach ($authorizations as $auth) {
            $code = strtoupper((string) $auth->compensationType?->code);
            if ($code === '') {
                continue;
            }
            $date = $auth->date->toDateString();
            foreach ($paidConcepts->get($auth->employee_id, []) as ['period' => $period, 'codes' => $codes]) {
                if (in_array($code, $codes, true) && $this->covers($period, $date) && $this->approvedBeforeClose($auth, $period)) {
                    $paid[$auth->id] = true;
                    break;
                }
            }
        }

        return $paid;
    }

    private function covers(PayrollPeriod $period, string $date): bool
    {
        $inBase = $date >= $period->start_date->toDateString() && $date <= $period->end_date->toDateString();
        $inExtras = $period->isUnified()
            && $date >= $period->extras_start_date->toDateString() && $date <= $period->extras_end_date->toDateString();

        return $inBase || $inExtras;
    }

    private function approvedBeforeClose(Authorization $auth, PayrollPeriod $period): bool
    {
        $closedAt = $period->cash_closed_at ? Carbon::parse($period->cash_closed_at) : $period->updated_at;
        $approvedAt = $auth->approved_at ?? $auth->updated_at;

        return $closedAt === null || $approvedAt === null || $approvedAt->lte($closedAt);
    }
}
