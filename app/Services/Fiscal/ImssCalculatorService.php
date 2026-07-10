<?php

namespace App\Services\Fiscal;

use App\Models\SystemSetting;

/**
 * Cuota obrera del IMSS: parte fija (% del SBC) + excedente (% sobre lo que el
 * SBC pasa de N UMA), por los días cotizados. Trabajador de salario mínimo:
 * cuota obrera = 0 (Art. 36 LSS, el patrón la absorbe).
 *
 * Calibrado contra Contpaq Sem28: 2.375% + 0.40% sobre excedente de 3 UMA,
 * UMA $113.14 — reproduce el IMSS de la semana completa al centavo.
 */
class ImssCalculatorService
{
    private float $uma;
    private float $minWage;
    private float $fixedPct;
    private float $excessPct;
    private float $excessUmaMultiple;

    public function __construct()
    {
        $this->uma = (float) SystemSetting::get('fiscal_uma_daily', 113.14);
        $this->minWage = (float) SystemSetting::get('fiscal_minimum_wage_daily', 315.04);
        $this->fixedPct = (float) SystemSetting::get('fiscal_imss_worker_fixed_pct', 2.375);
        $this->excessPct = (float) SystemSetting::get('fiscal_imss_eym_excess_pct', 0.40);
        $this->excessUmaMultiple = (float) SystemSetting::get('fiscal_imss_excess_uma_multiple', 3);
    }

    /**
     * @param  float  $sbc  Salario Base de Cotización (diario).
     * @param  float  $days  Días cotizados del periodo.
     * @param  float  $dailySalary  Sueldo diario (para la exención de salario mínimo).
     */
    public function workerQuota(float $sbc, float $days, float $dailySalary): float
    {
        if ($sbc <= 0 || $days <= 0) {
            return 0.0;
        }

        // Salario mínimo: el patrón absorbe la cuota obrera.
        if ($dailySalary <= $this->minWage + 0.01) {
            return 0.0;
        }

        $excess = max(0.0, $sbc - $this->excessUmaMultiple * $this->uma);
        $perDay = $sbc * ($this->fixedPct / 100) + $excess * ($this->excessPct / 100);

        return round($perDay * $days, 2);
    }
}
