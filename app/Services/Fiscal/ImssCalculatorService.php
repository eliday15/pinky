<?php

namespace App\Services\Fiscal;

use App\Models\SystemSetting;

/**
 * Cuota obrera del IMSS por ramo, con AUSENTISMO (Art. 31 LSS). Trabajador de
 * salario mínimo: cuota obrera = 0 (Art. 36 LSS, el patrón la absorbe).
 *
 * Regla por ramo (DERIVADA de los 6 casos con faltas de Contpaq Sem28 —
 * sistema de ecuaciones sobre sbc/imss reales, 6/6 exactos):
 * - Ramos de ENFERMEDAD Y MATERNIDAD — prestaciones en dinero (0.25%) + gastos
 *   médicos pensionados (0.375%) + excedente de 3 UMA (0.40%) — cotizan TODOS
 *   los días del periodo aunque haya faltas (ausencias ≤7 días siguen
 *   cotizando EyM, Art. 31 frac. I y IV LSS).
 * - INVALIDEZ Y VIDA (0.625%) + CESANTÍA Y VEJEZ (1.125%) = 1.75% cotizan solo
 *   los días SIN falta (días − faltas enteras).
 * En semana completa ambas convenciones coinciden con el agregado histórico
 * 2.375% + 0.40% excedente (validado $0.47 de error en 79 empleados, UMA
 * $117.31 oficial INEGI 2026).
 */
class ImssCalculatorService
{
    private float $uma;

    private float $minWage;

    private float $eymFixedPct;

    private float $ivCvPct;

    private float $excessPct;

    private float $excessUmaMultiple;

    public function __construct()
    {
        $this->uma = (float) SystemSetting::get('fiscal_uma_daily', 117.31);
        $this->minWage = (float) SystemSetting::get('fiscal_minimum_wage_daily', 315.04);
        // EyM en dinero 0.25 + gastos médicos pensionados 0.375 (no descuentan faltas).
        $this->eymFixedPct = (float) SystemSetting::get('fiscal_imss_eym_fixed_pct', 0.625);
        // Invalidez y Vida 0.625 + Cesantía y Vejez 1.125 (descuentan faltas).
        $this->ivCvPct = (float) SystemSetting::get('fiscal_imss_ivcv_pct', 1.75);
        $this->excessPct = (float) SystemSetting::get('fiscal_imss_eym_excess_pct', 0.40);
        $this->excessUmaMultiple = (float) SystemSetting::get('fiscal_imss_excess_uma_multiple', 3);
    }

    /**
     * Cuota obrera del periodo.
     *
     * Args:
     *     sbc: Salario Base de Cotización (diario).
     *     days: Días del periodo (7 en la semanal).
     *     dailySalary: Sueldo diario (para la exención de salario mínimo).
     *     absenceDays: Faltas ENTERAS del periodo (reducen solo IV+CyV).
     *
     * Returns:
     *     Cuota obrera redondeada a centavos.
     */
    public function workerQuota(float $sbc, float $days, float $dailySalary, int $absenceDays = 0): float
    {
        if ($sbc <= 0 || $days <= 0) {
            return 0.0;
        }

        // Salario mínimo: el patrón absorbe la cuota obrera.
        if ($dailySalary <= $this->minWage + 0.01) {
            return 0.0;
        }

        $excess = max(0.0, $sbc - $this->excessUmaMultiple * $this->uma);
        $ivCvDays = max(0.0, $days - max(0, $absenceDays));

        // EyM (fijo + excedente): todos los días; IV+CyV: solo días sin falta.
        $eym = ($sbc * ($this->eymFixedPct / 100) + $excess * ($this->excessPct / 100)) * $days;
        $ivCv = $sbc * ($this->ivCvPct / 100) * $ivCvDays;

        return round($eym + $ivCv, 2);
    }
}
