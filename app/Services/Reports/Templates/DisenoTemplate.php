<?php

namespace App\Services\Reports\Templates;

/**
 * DISEÑO format: each day has two sub-columns "M" (matutino) and "V" (vespertino).
 *
 * M = day shift hours; V = night shift hours (split is driven by
 * AttendanceRecord::is_night_shift on each record).
 */
class DisenoTemplate extends AbstractOvertimeReportTemplate
{
    public function vueComponent(): string
    {
        return 'diseno';
    }

    public function pdfView(): string
    {
        return 'pdf.overtime-weekly.diseno';
    }

    public function excelHeadings(array $report): array
    {
        $headings = ['NOMBRE'];
        foreach ($report['dates'] as $date) {
            $label = $this->formatDate($date);
            $headings[] = "{$label} M";
            $headings[] = "{$label} V";
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
                $line[] = $this->formatHours($day['m_hours']);
                $line[] = $this->formatHours($day['v_hours']);
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
