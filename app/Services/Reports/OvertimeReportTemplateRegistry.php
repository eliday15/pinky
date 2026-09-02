<?php

namespace App\Services\Reports;

use App\Models\Department;
use App\Services\Reports\Templates\DefaultTemplate;
use App\Services\Reports\Templates\OvertimeReportTemplate;

/**
 * Maps a department to its overtime report template.
 *
 * TODOS los departamentos usan el formato de Almacén PT (DefaultTemplate):
 * nombre + días + Total Horas + Fin de Semana + Comida + Velada + Cena +
 * Otros Conceptos, en web, PDF y Excel (petición de Luis/fábrica 2026-07-09
 * "que sean iguales al de Almacén PT"). Antes cada depto tenía su formato
 * (Calidad sin Comida/Velada/Cena, Diseño con desglose M/V, BIES transpuesto);
 * esos templates (Bies/Calidad/Corte/Diseno + sus vistas Vue/PDF) se
 * conservan en el código por si se quiere restaurar alguno, pero ya no se
 * enrutan aquí.
 */
class OvertimeReportTemplateRegistry
{
    /**
     * Resolve the template for a department.
     */
    public function for(Department $department): OvertimeReportTemplate
    {
        return new DefaultTemplate;
    }

    public function consolidated(): OvertimeReportTemplate
    {
        return new DefaultTemplate;
    }
}
