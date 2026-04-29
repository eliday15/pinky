<?php

namespace App\Services\Reports\Templates;

use Carbon\Carbon;

/**
 * BIES format (transposed): employees as columns, days/categories as rows.
 *
 * Reproduces the paper layout where each employee is a column and the
 * rows enumerate the days of the week plus VELADA / CENA / FIN DE SEMANA / COMIDA markers.
 */
class BiesTemplate extends AbstractOvertimeReportTemplate
{
    public function vueComponent(): string
    {
        return 'bies';
    }

    public function pdfView(): string
    {
        return 'pdf.overtime-weekly.bies';
    }

    public function excelHeadings(array $report): array
    {
        $headings = ['CONCEPTO'];

        foreach ($report['rows'] as $row) {
            $headings[] = $row['employee']['full_name'];
        }
        $headings[] = 'TOTAL';

        return $headings;
    }

    public function excelRows(array $report): array
    {
        $rows = [];

        foreach ($report['dates'] as $date) {
            $rows[] = $this->buildDayRow($report, $date, $this->dayLabel($date));
        }

        $rows[] = $this->buildTotalRow($report, 'TOTAL', fn ($row) => $row['totals']['total_hours']);
        $rows[] = $this->buildTotalRow($report, 'CENA', fn ($row) => $row['totals']['cena_count']);
        $rows[] = $this->buildTotalRow(
            $report,
            'VELADA '.$this->formatDate($report['week_start']),
            fn ($row) => $row['totals']['velada_count'],
        );
        $rows[] = $this->buildTotalRow(
            $report,
            'CENA '.$this->formatDate($report['week_start']),
            fn ($row) => $row['totals']['cena_count'],
        );
        $rows[] = $this->buildTotalRow($report, 'FIN DE SEMANA', fn ($row) => $row['totals']['weekend_hours']);
        $rows[] = $this->buildTotalRow($report, 'COMIDA', fn ($row) => $row['totals']['comida_count']);

        return $rows;
    }

    /**
     * Per-day row: extra hours per employee on that date.
     */
    private function buildDayRow(array $report, string $date, string $label): array
    {
        $line = [$label];
        $sum = 0.0;
        foreach ($report['rows'] as $row) {
            $day = $row['days'][$date];
            $value = $day['overtime_hours'] + $day['velada_hours'];
            $sum += $value;
            $line[] = $this->formatHours($value);
        }
        $line[] = $this->formatHours($sum);

        return $line;
    }

    /**
     * Aggregated total row for a category across all employees.
     */
    private function buildTotalRow(array $report, string $label, callable $extractor): array
    {
        $line = [$label];
        $sum = 0;
        foreach ($report['rows'] as $row) {
            $value = $extractor($row);
            $sum += $value;
            $line[] = is_int($value) ? $value : $this->formatHours((float) $value);
        }
        $line[] = is_int($sum) ? $sum : $this->formatHours((float) $sum);

        return $line;
    }

    /**
     * Spanish day label like "LUNES".
     */
    private function dayLabel(string $date): string
    {
        $names = [
            'Monday' => 'LUNES',
            'Tuesday' => 'MARTES',
            'Wednesday' => 'MIERCOLES',
            'Thursday' => 'JUEVES',
            'Friday' => 'VIERNES',
            'Saturday' => 'SABADO',
            'Sunday' => 'DOMINGO',
        ];

        return $names[Carbon::parse($date)->format('l')] ?? strtoupper($date);
    }
}
