<?php

namespace Tests\Feature\Reports;

use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Department;
use App\Models\Employee;
use App\Services\Reports\WeeklyOvertimeReportService;
use Carbon\Carbon;
use Tests\FeatureTestCase;

/**
 * El reporte semanal de tiempo extra debe mostrar TODOS los conceptos aprobados,
 * no solo los "conocidos" con columna fija (HE/HED/HET, FIN, VEL, CENA, COM).
 * Un concepto nuevo creado a mano (p. ej. "Cena por entrega a Walmart", que no
 * se carga desde checadas) aparece en `extra_concepts` (petición de Dani
 * 2026-07-01).
 */
class WeeklyReportExtraConceptsTest extends FeatureTestCase
{
    private const MONDAY = '2026-03-09';

    private function customConcept(): CompensationType
    {
        return CompensationType::create([
            'code' => 'CENAWM',
            'name' => 'Cena por entrega a Walmart',
            'calculation_type' => 'fixed',
            'fixed_amount' => 60,
            'percentage_value' => null,
            'application_mode' => CompensationType::APPLICATION_PER_DAY,
            'authorization_type' => Authorization::TYPE_SPECIAL,
            'attendance_pull_rule' => null, // no se carga desde checadas
            'is_active' => true,
        ]);
    }

    public function test_report_lists_approved_concepts_without_a_fixed_column(): void
    {
        $dept = Department::factory()->create(['name' => 'Almacén PT', 'code' => 'ALMACENPT']);
        $employee = Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active']);
        $custom = $this->customConcept();

        Authorization::factory()->create([
            'employee_id' => $employee->id,
            'date' => '2026-03-10', // martes de la semana del 9 mar
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $custom->id,
            'hours' => 1,
            'status' => Authorization::STATUS_APPROVED,
        ]);

        $report = app(WeeklyOvertimeReportService::class)->buildReport($dept, Carbon::parse(self::MONDAY));

        $extra = $report['rows'][0]['extra_concepts'];
        $this->assertCount(1, $extra);
        $this->assertSame('Cena por entrega a Walmart', $extra[0]['name']);
        $this->assertSame(1, $extra[0]['count']);

        // También agregado en los totales del reporte.
        $this->assertSame('Cena por entrega a Walmart', $report['totals']['extra_concepts'][0]['name']);
        $this->assertSame(1, $report['totals']['extra_concepts'][0]['count']);

        // Y visible en la columna de observaciones (que todas las plantillas
        // imprimen), así se ve en cualquier departamento sin columna dedicada.
        $this->assertStringContainsString('Cena por entrega a Walmart', $report['rows'][0]['observations']);
    }

    public function test_known_concepts_do_not_appear_as_extra(): void
    {
        $dept = Department::factory()->create(['name' => 'Corte', 'code' => 'CORTE']);
        $employee = Employee::factory()->create(['department_id' => $dept->id, 'status' => 'active']);

        // Un concepto conocido (FIN) NO debe salir en "otros conceptos".
        $fin = CompensationType::updateOrCreate(['code' => 'FIN'], [
            'name' => 'Fin de Semana',
            'calculation_type' => 'fixed',
            'fixed_amount' => 200,
            'application_mode' => CompensationType::APPLICATION_PER_DAY,
            'authorization_type' => Authorization::TYPE_SPECIAL,
            'attendance_pull_rule' => CompensationType::PULL_RULE_WEEKEND,
            'is_active' => true,
        ]);

        Authorization::factory()->create([
            'employee_id' => $employee->id,
            'date' => '2026-03-14', // sábado
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $fin->id,
            'hours' => 1,
            'status' => Authorization::STATUS_APPROVED,
        ]);

        $report = app(WeeklyOvertimeReportService::class)->buildReport($dept, Carbon::parse(self::MONDAY));

        $this->assertSame([], $report['rows'][0]['extra_concepts']);
    }
}
