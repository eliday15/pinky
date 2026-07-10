<?php

namespace App\Services\Fiscal;

use App\Models\FiscalIsrBracket;
use App\Models\FiscalSubsidyBracket;
use App\Models\SystemSetting;

/**
 * ISR del trabajador (Art. 96) con subsidio para el empleo, por periodo.
 * ISR = cuota_fija + %excedente × (gravable − lower) del bracket. Luego se
 * acredita el subsidio: isr_final = max(0, isr − subsidio); si el subsidio
 * supera al ISR, el excedente se acredita (suma al pago).
 *
 * Salario mínimo → ISR exento (0). Tarifa calibrada contra Contpaq Sem28
 * (reproduce el ISR de 155 empleados al ±$1).
 */
class IsrCalculatorService
{
    private float $minWage;

    public function __construct()
    {
        $this->minWage = (float) SystemSetting::get('fiscal_minimum_wage_daily', 315.04);
    }

    /**
     * @param  float  $taxableBase  Base gravable del periodo (percepciones gravables).
     * @param  float  $dailySalary  Sueldo diario (para la exención de salario mínimo).
     * @return array{isr: float, subsidy_credited: float, isr_before_subsidy: float}
     */
    public function calculate(float $taxableBase, float $dailySalary, string $periodType = 'weekly'): array
    {
        // Salario mínimo: exento de ISR.
        if ($dailySalary <= $this->minWage + 0.01) {
            return ['isr' => 0.0, 'subsidy_credited' => 0.0, 'isr_before_subsidy' => 0.0];
        }

        $isrRaw = $this->tariffIsr($taxableBase, $periodType);
        $subsidy = $this->subsidyFor($taxableBase, $periodType);

        $isr = max(0.0, round($isrRaw - $subsidy, 2));
        $credited = max(0.0, round($subsidy - $isrRaw, 2));

        return ['isr' => $isr, 'subsidy_credited' => $credited, 'isr_before_subsidy' => round($isrRaw, 2)];
    }

    private function tariffIsr(float $gravable, string $periodType): float
    {
        $bracket = FiscalIsrBracket::where('period_type', $periodType)
            ->where('lower_limit', '<=', $gravable)
            ->orderByDesc('lower_limit')
            ->first();

        if (! $bracket) {
            return 0.0;
        }

        $isr = (float) $bracket->fixed_fee
            + ((float) $bracket->percent_over_excess / 100) * ($gravable - (float) $bracket->lower_limit);

        return max(0.0, round($isr, 2));
    }

    private function subsidyFor(float $gravable, string $periodType): float
    {
        $bracket = FiscalSubsidyBracket::where('period_type', $periodType)
            ->where('lower_limit', '<=', $gravable)
            ->where(function ($q) use ($gravable) {
                $q->whereNull('upper_limit')->orWhere('upper_limit', '>=', $gravable);
            })
            ->orderByDesc('lower_limit')
            ->first();

        return $bracket ? (float) $bracket->subsidy : 0.0;
    }
}
