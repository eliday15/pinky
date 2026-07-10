<?php

namespace App\Services\Fiscal;

use App\Models\Employee;
use App\Models\SystemSetting;

/**
 * Orquesta las retenciones fiscales del trabajador (ISR + IMSS + Infonavit) y
 * el subsidio para el empleo. Solo aplica a empleados FORMALIZADOS (los que
 * cobran por transferencia: IMSS + número + fuera de prueba) — mismo criterio
 * que Employee::paysBaseInCash() invertido. El resultado reduce el neto/banco.
 */
class FiscalDeductionService
{
    public function __construct(
        private IsrCalculatorService $isr,
        private ImssCalculatorService $imss,
        private InfonavitCalculatorService $infonavit,
    ) {}

    /**
     * ¿Al empleado se le retienen impuestos? (formalizado / cobra por banco).
     */
    public function applies(Employee $employee): bool
    {
        // Interruptor global: las retenciones se activan solo cuando la config
        // fiscal está lista (tablas sembradas, SDI/SBC importados). Apagado por
        // defecto para no alterar la nómina hasta habilitarlo en prod.
        if (! (bool) SystemSetting::get('fiscal_retentions_enabled', false)) {
            return false;
        }

        return ! $employee->paysBaseInCash();
    }

    /**
     * @param  float  $taxableBase  Percepciones gravables del periodo (base + gravable de extras).
     * @param  float  $days  Días del periodo.
     * @return array{isr: float, imss: float, infonavit: float, subsidy: float, total: float}
     */
    public function compute(Employee $employee, float $taxableBase, float $days): array
    {
        $zero = ['isr' => 0.0, 'imss' => 0.0, 'infonavit' => 0.0, 'subsidy' => 0.0, 'total' => 0.0];

        if (! $this->applies($employee) || $days <= 0) {
            return $zero;
        }

        $dailySalary = (float) $employee->daily_salary_computed;
        $sdi = (float) ($employee->sdi ?: $dailySalary);
        $sbc = (float) ($employee->sbc ?: $sdi);

        $isrRes = $this->isr->calculate($taxableBase, $dailySalary);
        $imss = $this->imss->workerQuota($sbc, $days, $dailySalary);
        $infonavit = $this->infonavit->deduction($employee, $sdi, $days);
        $subsidy = $isrRes['subsidy_credited'];

        // El subsidio acreditado SUMA (reduce la deducción total / puede sumar al pago).
        $total = round($isrRes['isr'] + $imss + $infonavit - $subsidy, 2);

        return [
            'isr' => $isrRes['isr'],
            'imss' => $imss,
            'infonavit' => $infonavit,
            'subsidy' => $subsidy,
            'total' => $total,
        ];
    }
}
