<?php

namespace App\Services\Reports\Templates;

use Carbon\Carbon;

/**
 * Shared helpers for overtime report templates.
 */
abstract class AbstractOvertimeReportTemplate implements OvertimeReportTemplate
{
    /**
     * Format a date as "DD/MM/YYYY" for column headers in Excel/PDF.
     */
    protected function formatDate(string $date): string
    {
        return Carbon::parse($date)->format('d/m/Y');
    }

    /**
     * Format zero-or-numeric value, mimicking the paper format.
     */
    protected function formatHours(float $value): string
    {
        if ($value <= 0) {
            return '0';
        }

        // Strip trailing zero in decimals (e.g., 1.50 -> 1.5)
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * "Otros conceptos" aprobados sin columna fija, como texto para una celda:
     * "Nombre (conteo): $valor" por concepto y una SUMA total al final (Luis
     * 2026-07-09: "que me dé el valor y al final la suma"). Los descuentos
     * (monto negativo) salen con signo.
     *
     * @param  list<array{name: string, count: int, hours: float, amount?: float}>  $items
     */
    protected function formatExtraConcepts(array $items): string
    {
        if (empty($items)) {
            return '';
        }

        $lines = collect($items)
            ->map(fn (array $c) => "{$c['name']} ({$c['count']}): ".$this->formatMoney((float) ($c['amount'] ?? 0)))
            ->all();

        $total = collect($items)->sum(fn (array $c) => (float) ($c['amount'] ?? 0));
        $lines[] = 'Total: '.$this->formatMoney($total);

        return implode('; ', $lines);
    }

    /**
     * Formatea un monto como "$1,234.56" (negativo: "-$300.00").
     */
    protected function formatMoney(float $value): string
    {
        return ($value < 0 ? '-' : '').'$'.number_format(abs($value), 2);
    }
}
