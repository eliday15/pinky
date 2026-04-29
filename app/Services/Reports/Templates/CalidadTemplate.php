<?php

namespace App\Services\Reports\Templates;

/**
 * CALIDAD format: simplest layout — name + 7 days + total + weekend + observations.
 */
class CalidadTemplate extends AbstractOvertimeReportTemplate
{
    public function vueComponent(): string
    {
        return 'calidad';
    }

    public function pdfView(): string
    {
        return 'pdf.overtime-weekly.calidad';
    }

    public function excelHeadings(array $report): array
    {
        $headings = ['NOMBRE'];
        foreach ($report['dates'] as $date) {
            $headings[] = $this->formatDate($date);
        }
        $headings[] = 'TOTAL HORAS';
        $headings[] = 'FIN DE SEMANA';
        $headings[] = 'OBSERVACIONES';

        return $headings;
    }

    public function excelRows(array $report): array
    {
        $rows = [];

        foreach ($report['rows'] as $row) {
            $line = [$row['employee']['full_name']];

            foreach ($report['dates'] as $date) {
                $day = $row['days'][$date];
                $extra = $day['overtime_hours'] + $day['velada_hours'];
                $line[] = $this->formatHours($extra);
            }

            $line[] = $this->formatHours($row['totals']['total_hours']);
            $line[] = $this->formatHours($row['totals']['weekend_hours']);
            $line[] = $row['observations'];

            $rows[] = $line;
        }

        return $rows;
    }
}
