<?php

namespace App\Services\Reports\Templates;

/**
 * Default template: employees as rows, days as columns, with comida/velada/cena counters.
 *
 * Used as fallback for departments without a department-specific template
 * (Almacén PT, Estampado, General, Habilitación, Mensajería, Oficinas,
 * Planeación, Producción, Saldos, Sistemas, Telas, etc.).
 */
class DefaultTemplate extends AbstractOvertimeReportTemplate
{
    public function vueComponent(): string
    {
        return 'default';
    }

    public function pdfView(): string
    {
        return 'pdf.overtime-weekly.default';
    }

    public function excelHeadings(array $report): array
    {
        $headings = ['NOMBRE'];
        foreach ($report['dates'] as $date) {
            $headings[] = $this->formatDate($date);
        }
        $headings[] = 'TOTAL HORAS';
        $headings[] = ! empty($report['weekend_unit_hours']) ? 'FINES DE SEMANA' : 'FIN DE SEMANA';
        $headings[] = 'COMIDA';
        $headings[] = 'VELADA';
        $headings[] = 'CENA';
        $headings[] = 'OTROS CONCEPTOS';
        if ($report['show_observations'] ?? true) {
            $headings[] = 'OBSERVACIONES';
        }

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
                $cell = $this->formatHours($extra);
                // Con "incluye pendientes": lo capturado sin aprobar sale
                // DISTINGUIDO entre paréntesis (Elias 2026-08-12).
                $pend = ! empty($report['includes_pending']) ? ($day['pending_overtime_hours'] ?? 0) : 0;
                if ($pend > 0) {
                    $cell .= ' (+'.$this->formatHours($pend).' x aprobar)';
                }
                $line[] = $cell;
            }

            $total = $this->formatHours($row['totals']['total_hours']);
            $rowPend = ! empty($report['includes_pending']) ? ($row['totals']['pending_hours'] ?? 0) : 0;
            if ($rowPend > 0) {
                $total .= ' (+'.$this->formatHours($rowPend).' x aprobar)';
            }
            $line[] = $total;
            $line[] = ! empty($report['weekend_unit_hours'])
                ? $row['totals']['weekend_units']
                : $this->formatHours($row['totals']['weekend_hours']);
            $line[] = $row['totals']['comida_count'];
            $line[] = $row['totals']['velada_count'];
            $line[] = $row['totals']['cena_count'];
            $line[] = $this->formatExtraConcepts($row['extra_concepts'] ?? []);
            if ($report['show_observations'] ?? true) {
                $line[] = $row['observations'];
            }

            $rows[] = $line;
        }

        return $rows;
    }
}
