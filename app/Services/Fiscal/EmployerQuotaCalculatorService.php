<?php

namespace App\Services\Fiscal;

use App\Models\FiscalCyvBracket;
use App\Models\SystemSetting;
use Illuminate\Support\Collection;

/**
 * Cuotas PATRONALES por empleado/periodo (costo de la empresa — informativo y
 * de provisión, NO reduce el pago del trabajador): IMSS por ramo, Retiro SAR,
 * Infonavit, Riesgo de Trabajo e Impuesto sobre Nómina estatal.
 *
 * Modelo VALIDADO contra el bloque "Obligaciones/Rubros IMSS" del Total
 * General de Contpaq Sem28 (cada rubro a <1%):
 * - EyM cuota fija: 20.40% de UMA × días (todos los empleados).
 * - EyM excedente: 1.10% × max(0, SBC − 3 UMA) × días — exacto.
 * - EyM dinero+GMP: 1.75% × SBC × días (sin descuento de faltas, Art. 31).
 * - IV: 1.75% × SBC × días sin falta.
 * - CyV: tabla escalonada 2026 por SBC/UMA (fiscal_cyv_brackets) × días sin
 *   falta — se aplica por SBC a TODOS (el SBC del mínimo cae en ~2.8 UMA).
 * - Guarderías: 1.00% × SBC × días sin falta.
 * - Retiro SAR: 2.00% × SBC × días. Infonavit: 5.00% × SBC × días.
 * - Riesgo de Trabajo: prima de la empresa (setting, capturar la vigente).
 * - ABSORCIÓN Art. 36: para salario mínimo el patrón paga además la cuota
 *   obrera (EyM dinero+GMP 0.625% todos los días; IV 0.625% y CyV 1.125% días
 *   sin falta; el excedente no aplica, SBC del mínimo < 3 UMA).
 * - ISN estatal: 3.00% sobre las percepciones del periodo (se calcula aparte
 *   porque su base es la nómina, no el SBC).
 */
class EmployerQuotaCalculatorService
{
    private float $uma;

    private float $minWage;

    private float $eymFixedUmaPct;

    private float $eymExcessPct;

    private float $eymMoneyGmpPct;

    private float $ivPct;

    private float $guarderiaPct;

    private float $retiroPct;

    private float $infonavitPct;

    private float $riesgoTrabajoPct;

    private float $isnPct;

    private ?Collection $cyvBrackets = null;

    public function __construct()
    {
        $this->uma = (float) SystemSetting::get('fiscal_uma_daily', 117.31);
        $this->minWage = (float) SystemSetting::get('fiscal_minimum_wage_daily', 315.04);
        $this->eymFixedUmaPct = (float) SystemSetting::get('fiscal_emp_eym_fixed_uma_pct', 20.40);
        $this->eymExcessPct = (float) SystemSetting::get('fiscal_emp_eym_excess_pct', 1.10);
        $this->eymMoneyGmpPct = (float) SystemSetting::get('fiscal_emp_eym_money_gmp_pct', 1.75);
        $this->ivPct = (float) SystemSetting::get('fiscal_emp_iv_pct', 1.75);
        $this->guarderiaPct = (float) SystemSetting::get('fiscal_emp_guarderia_pct', 1.00);
        $this->retiroPct = (float) SystemSetting::get('fiscal_emp_retiro_pct', 2.00);
        $this->infonavitPct = (float) SystemSetting::get('fiscal_emp_infonavit_pct', 5.00);
        $this->riesgoTrabajoPct = (float) SystemSetting::get('fiscal_emp_riesgo_trabajo_pct', 0);
        $this->isnPct = (float) SystemSetting::get('fiscal_isn_pct', 3.00);
    }

    /**
     * Cuotas patronales del periodo para un empleado.
     *
     * Args:
     *     sbc: Salario Base de Cotización diario.
     *     days: Días del periodo (7 en la semanal).
     *     absenceDays: Faltas enteras (reducen IV/CyV/guarderías, no EyM).
     *     dailySalary: Sueldo diario (absorción Art. 36 para mínimos).
     *     grossPerceptions: Percepciones del periodo (base del ISN).
     *
     * Returns:
     *     Arreglo por rubro + 'total' (todo redondeado a centavos).
     */
    public function quotas(float $sbc, float $days, int $absenceDays, float $dailySalary, float $grossPerceptions): array
    {
        $zero = [
            'eym_fixed' => 0.0, 'eym_excess' => 0.0, 'eym_money_gmp' => 0.0,
            'iv' => 0.0, 'cyv' => 0.0, 'guarderia' => 0.0, 'retiro' => 0.0,
            'infonavit' => 0.0, 'riesgo_trabajo' => 0.0, 'absorbed_worker' => 0.0,
            'isn' => 0.0, 'total' => 0.0,
        ];
        if ($sbc <= 0 || $days <= 0) {
            return $zero;
        }

        $entDays = max(0.0, $days - max(0, $absenceDays));
        $excess = max(0.0, $sbc - 3 * $this->uma);
        $isMinWage = $dailySalary > 0 && $dailySalary <= $this->minWage + 0.01;

        $q = $zero;
        $q['eym_fixed'] = $this->uma * ($this->eymFixedUmaPct / 100) * $days;
        $q['eym_excess'] = $excess * ($this->eymExcessPct / 100) * $days;
        $q['eym_money_gmp'] = $sbc * ($this->eymMoneyGmpPct / 100) * $days;
        $q['iv'] = $sbc * ($this->ivPct / 100) * $entDays;
        $q['cyv'] = $sbc * ($this->cyvRate($sbc) / 100) * $entDays;
        $q['guarderia'] = $sbc * ($this->guarderiaPct / 100) * $entDays;
        $q['retiro'] = $sbc * ($this->retiroPct / 100) * $days;
        $q['infonavit'] = $sbc * ($this->infonavitPct / 100) * $days;
        $q['riesgo_trabajo'] = $sbc * ($this->riesgoTrabajoPct / 100) * $days;

        // Art. 36 LSS: el patrón absorbe la cuota obrera del salario mínimo
        // (EyM dinero+GMP 0.625% sin descuento; IV 0.625% + CyV 1.125% con
        // descuento de faltas; excedente no aplica al mínimo).
        if ($isMinWage) {
            $q['absorbed_worker'] = $sbc * 0.00625 * $days
                + $sbc * (0.00625 + 0.01125) * $entDays;
        }

        $q['isn'] = max(0.0, $grossPerceptions) * ($this->isnPct / 100);

        foreach ($q as $k => $v) {
            $q[$k] = round($v, 2);
        }
        $q['total'] = round(array_sum(array_diff_key($q, ['total' => 0])), 2);

        return $q;
    }

    /**
     * % patronal de CyV según el SBC diario en UMA (tabla escalonada 2026).
     */
    public function cyvRate(float $sbc): float
    {
        $this->cyvBrackets ??= FiscalCyvBracket::orderBy('upper_uma')->get();
        if ($this->cyvBrackets->isEmpty()) {
            return 3.150; // tasa pre-reforma como último recurso
        }

        $multiple = $sbc / $this->uma;
        foreach ($this->cyvBrackets as $bracket) {
            if ($multiple <= (float) $bracket->upper_uma) {
                return (float) $bracket->employer_pct;
            }
        }

        return (float) $this->cyvBrackets->last()->employer_pct;
    }
}
