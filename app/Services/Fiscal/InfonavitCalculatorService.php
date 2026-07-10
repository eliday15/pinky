<?php

namespace App\Services\Fiscal;

use App\Models\Employee;

/**
 * Descuento de crédito Infonavit del trabajador. El crédito se captura por
 * empleado (importado del PDF de Contpaq):
 * - CF (cuota fija): monto semanal fijo, prorrateado por días del periodo.
 * - FD (factor de descuento): factor × SDI × días.
 */
class InfonavitCalculatorService
{
    /**
     * @param  float  $sdi  Salario Diario Integrado.
     * @param  float  $days  Días del periodo.
     * @param  float  $weekDays  Días de la semana completa (para prorratear la CF).
     */
    public function deduction(Employee $employee, float $sdi, float $days, float $weekDays = 7.0): float
    {
        $type = $employee->infonavit_credit_type;
        $value = (float) ($employee->infonavit_credit_value ?? 0);

        if ($value <= 0 || $type === 'none' || $type === null) {
            return 0.0;
        }

        if ($type === 'fd') {
            // Factor de descuento sobre el SDI por los días.
            return round($value * $sdi * $days, 2);
        }

        // CF: cuota fija semanal, prorrateada por los días trabajados del periodo.
        return round($value * ($weekDays > 0 ? $days / $weekDays : 1), 2);
    }
}
