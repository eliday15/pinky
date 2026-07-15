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
 * Salario mínimo → ISR exento (0) SOLO mientras no tenga percepciones
 * gravables extra: si un mínimo cobra un extra gravado (cumpleaños, etc.), el
 * ISR aplica sobre TODO el gravable (regla verificada con FOFH/LOAX/LOLV de
 * Contpaq Sem28: base mínima 2205.28 + cumpleaños 315.04 → tarifa sobre
 * 2520.32 = 191.55, − subsidio 123.34 = 68.21 exacto). Tarifa calibrada contra
 * Contpaq Sem28 (reproduce el ISR de 155 empleados al ±$1).
 */
class IsrCalculatorService
{
    private float $minWage;

    /**
     * Memo de tarifas por period_type: las tablas de brackets no cambian
     * durante un cálculo, así que se cargan una vez (ordenadas descendente por
     * lower_limit) y el bracket se elige en memoria — antes eran 2 queries por
     * empleado.
     *
     * @var array<string, \Illuminate\Support\Collection<int, FiscalIsrBracket>>
     */
    private array $isrBrackets = [];

    /** @var array<string, \Illuminate\Support\Collection<int, FiscalSubsidyBracket>> */
    private array $subsidyBrackets = [];

    public function __construct()
    {
        $this->minWage = (float) SystemSetting::get('fiscal_minimum_wage_daily', 315.04);
    }

    /**
     * @param  float  $taxableBase  Base gravable del periodo (percepciones gravables).
     * @param  float  $dailySalary  Sueldo diario (para la exención de salario mínimo).
     * @param  string  $periodType  Tipo de tarifa ('weekly').
     * @param  float  $days  Días del periodo (para el tope de la exención de mínimo).
     * @return array{isr: float, subsidy_credited: float, isr_before_subsidy: float}
     */
    public function calculate(float $taxableBase, float $dailySalary, string $periodType = 'weekly', float $days = 7.0): array
    {
        // Salario mínimo: exento de ISR mientras el gravable no exceda su base
        // pura (SD×días). Con extras gravados (cumpleaños, bonos) el ISR aplica
        // sobre todo el gravable (verificado vs Contpaq: FOFH/LOAX/LOLV).
        if ($dailySalary <= $this->minWage + 0.01 && $taxableBase <= $this->minWage * $days + 0.01) {
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
        $this->isrBrackets[$periodType] ??= FiscalIsrBracket::where('period_type', $periodType)
            ->orderByDesc('lower_limit')
            ->get();

        // Ordenados descendente: el primero con lower_limit <= gravable es el
        // bracket que aplica (mismo criterio que el query original).
        $bracket = $this->isrBrackets[$periodType]
            ->first(fn ($b) => (float) $b->lower_limit <= $gravable);

        if (! $bracket) {
            return 0.0;
        }

        $isr = (float) $bracket->fixed_fee
            + ((float) $bracket->percent_over_excess / 100) * ($gravable - (float) $bracket->lower_limit);

        return max(0.0, round($isr, 2));
    }

    private function subsidyFor(float $gravable, string $periodType): float
    {
        $this->subsidyBrackets[$periodType] ??= FiscalSubsidyBracket::where('period_type', $periodType)
            ->orderByDesc('lower_limit')
            ->get();

        $bracket = $this->subsidyBrackets[$periodType]->first(
            fn ($b) => (float) $b->lower_limit <= $gravable
                && ($b->upper_limit === null || (float) $b->upper_limit >= $gravable)
        );

        return $bracket ? (float) $bracket->subsidy : 0.0;
    }
}
