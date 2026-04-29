<?php

namespace App\Services\Reports;

use App\Models\Department;
use App\Services\Reports\Templates\BiesTemplate;
use App\Services\Reports\Templates\CalidadTemplate;
use App\Services\Reports\Templates\CorteTemplate;
use App\Services\Reports\Templates\DefaultTemplate;
use App\Services\Reports\Templates\DisenoTemplate;
use App\Services\Reports\Templates\OvertimeReportTemplate;

/**
 * Maps a department to its overtime report template.
 *
 * Departments without a specific template fall back to DefaultTemplate.
 */
class OvertimeReportTemplateRegistry
{
    /**
     * Resolve the template for a department.
     */
    public function for(Department $department): OvertimeReportTemplate
    {
        return match (strtoupper($department->code ?? '')) {
            'BIES' => new BiesTemplate,
            'CALIDAD' => new CalidadTemplate,
            'CORTE' => new CorteTemplate,
            'DISENO' => new DisenoTemplate,
            default => new DefaultTemplate,
        };
    }
}
