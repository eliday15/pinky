<?php

namespace App\Services\Reports\Templates;

/**
 * CORTE format: full layout — name + days + total + weekend + comida + velada + cena + observations.
 */
class CorteTemplate extends AbstractOvertimeReportTemplate
{
    public function vueComponent(): string
    {
        return 'corte';
    }

    public function pdfView(): string
    {
        return 'pdf.overtime-weekly.corte';
    }

    public function excelHeadings(array $report): array
    {
        $headings = ['NOMBRE'];
        foreach ($report['dates'] as $date) {
            $headings[] = $this->formatDate($date);
        }
        $headings[] = 'TOTAL HORAS';
        $headings[] = 'FIN DE SEMANA';
        $headings[] = 'COMIDA';
        $headings[] = 'VELADA';
        $headings[] = 'CENA';
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
            $line[] = $row['totals']['comida_count'];
            $line[] = $row['totals']['velada_count'];
            $line[] = $row['totals']['cena_count'];
            $line[] = $row['observations'];

            $rows[] = $line;
        }

        return $rows;
    }
}
