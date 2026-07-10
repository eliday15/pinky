<?php

namespace App\Services\Fiscal;

use App\Models\EmployeeAnnualTotal;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;

/**
 * Reconstruye los acumulados anuales por empleado desde los periodos BASE
 * (semanales) aprobados/pagados del año. Rebuild-desde-cero: idempotente por
 * diseño — re-aprobar o recalcular un periodo nunca duplica; basta volver a
 * correr el rebuild del año. Las columnas external_* (importadas de Contpaq al
 * corte) no se tocan.
 */
class EmployeeAnnualTotalService
{
    /**
     * Reconstruye el año completo. Se invoca al aprobar un periodo semanal y
     * desde el comando fiscal:rebuild-annual-totals.
     */
    public function rebuildYear(int $year): int
    {
        $periodIds = PayrollPeriod::whereIn('status', ['approved', 'paid'])
            ->where('type', 'weekly')
            ->whereYear('start_date', $year)
            ->pluck('id');

        // Acumula por empleado leyendo el breakdown fiscal de cada entry (la
        // base gravable y el gravado/exento viven ahí).
        $totals = [];
        PayrollEntry::whereIn('payroll_period_id', $periodIds)
            ->select(['employee_id', 'regular_pay', 'isr_amount', 'subsidy_amount', 'calculation_breakdown'])
            ->chunk(500, function ($entries) use (&$totals) {
                foreach ($entries as $entry) {
                    $fiscal = $entry->calculation_breakdown['fiscal'] ?? [];
                    $scope = $entry->calculation_breakdown['scope'] ?? [];
                    $id = $entry->employee_id;
                    $totals[$id] ??= ['taxable' => 0.0, 'exempt' => 0.0, 'isr' => 0.0, 'subsidy' => 0.0, 'days' => 0.0];

                    // Gravable: la base fiscal del breakdown; fallback al sueldo
                    // base para entries anteriores al campo taxable_base.
                    $taxable = (float) ($fiscal['taxable_base'] ?? 0) ?: (float) $entry->regular_pay;
                    $transferExtras = (float) ($fiscal['taxable_transfer_extras'] ?? 0);
                    $totals[$id]['taxable'] += $taxable;
                    // Exento = percepciones de transferencia no gravadas (prima
                    // dentro de 15 UMA, aguinaldo dentro de 30) — se aproxima
                    // desde el breakdown cuando existe.
                    $totals[$id]['exempt'] += max(0.0, (float) ($fiscal['transfer_extras_total'] ?? 0) - $transferExtras);
                    $totals[$id]['isr'] += (float) $entry->isr_amount;
                    $totals[$id]['subsidy'] += (float) $entry->subsidy_amount;
                    $totals[$id]['days'] += (float) ($scope['week_days'] ?? 7);
                }
            });

        foreach ($totals as $employeeId => $t) {
            EmployeeAnnualTotal::updateOrCreate(
                ['employee_id' => $employeeId, 'year' => $year],
                [
                    'taxable_income' => round($t['taxable'], 2),
                    'exempt_income' => round($t['exempt'], 2),
                    'isr_withheld' => round($t['isr'], 2),
                    'subsidy_paid' => round($t['subsidy'], 2),
                    'days_paid' => round($t['days'], 2),
                ],
            );
        }

        return count($totals);
    }
}
