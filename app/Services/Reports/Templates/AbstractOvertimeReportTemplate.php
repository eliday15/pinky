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
}
