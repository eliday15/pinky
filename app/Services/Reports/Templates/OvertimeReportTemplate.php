<?php

namespace App\Services\Reports\Templates;

/**
 * Contract for per-department weekly overtime report templates.
 *
 * Each template describes how its department's data is laid out in the
 * PDF (Blade view), Excel export, and Vue HTML preview.
 */
interface OvertimeReportTemplate
{
    /**
     * Slug used by the Vue preview to pick the right component.
     */
    public function vueComponent(): string;

    /**
     * Blade view path for the PDF render.
     */
    public function pdfView(): string;

    /**
     * Column headers for the Excel export.
     *
     * Args:
     *     report: The DTO returned by WeeklyOvertimeReportService::buildReport().
     */
    public function excelHeadings(array $report): array;

    /**
     * Data rows for the Excel export.
     *
     * Args:
     *     report: The DTO returned by WeeklyOvertimeReportService::buildReport().
     */
    public function excelRows(array $report): array;
}
