<?php

namespace Tests\Feature\Payroll;

use App\Models\Employee;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Services\Contpaqi\ContpaqiImportBuilder;
use Tests\FeatureTestCase;

/**
 * Generador del archivo de importación a CONTPAQi (hoja "Movimientos"): una fila
 * por empleado con los movimientos variables de la semana, mapeados desde lo que
 * calculó la nómina.
 */
class ContpaqiImportExportTest extends FeatureTestCase
{
    private function period(): PayrollPeriod
    {
        return PayrollPeriod::factory()->create([
            'type' => 'weekly',
            'start_date' => '2026-07-20', // lunes, semana ISO 30
            'end_date' => '2026-07-26',
            'status' => 'review',
        ]);
    }

    public function test_builder_maps_variable_movements_per_employee(): void
    {
        $period = $this->period();
        $employee = Employee::factory()->create([
            'contpaqi_code' => 'AEMB-740',
            'full_name' => 'ACEVEDO MENDOZA BLANCA',
            'status' => 'active',
        ]);

        // Incapacidad 21-22 jul (2 días calendario).
        $incType = IncidentType::firstOrCreate(['code' => 'INC'], [
            'name' => 'Incapacidad', 'category' => 'sick_leave', 'is_paid' => true, 'requires_approval' => true,
        ]);
        Incident::factory()->approved()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $incType->id,
            'start_date' => '2026-07-21',
            'end_date' => '2026-07-22',
            'days_count' => 2,
        ]);

        PayrollEntry::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'days_absent' => 2,
            'vacation_days_paid' => 1,
            'calculation_breakdown' => [
                'base' => ['absence_deduction_days' => 2],
                'compensation_concepts' => [
                    ['code' => 'HED', 'name' => 'Hora Extra Doble', 'hours' => 4, 'days' => 0, 'amount' => 200],
                    ['code' => 'PUNT', 'name' => 'Bono puntualidad', 'hours' => 0, 'days' => 0, 'amount' => 20],
                    ['code' => 'PREST', 'name' => 'Préstamo empresa', 'hours' => 0, 'days' => 0, 'amount' => -150],
                ],
            ],
        ]);

        $rows = app(ContpaqiImportBuilder::class)->rows($period);

        $this->assertCount(1, $rows);
        $row = $rows[0];

        $this->assertSame('AEMB-740', $row[0]);                 // CODIGO EMPLEADO
        $this->assertSame('ACEVEDO MENDOZA BLANCA', $row[1]);   // NOMBRE
        $this->assertSame(30, $row[2]);                          // SEMANA (ISO)
        $this->assertSame('20/07/2026', $row[3]);                // FECHA INICIAL
        $this->assertSame('26/07/2026', $row[4]);                // FECHA FINAL
        $this->assertSame(2, $row[5]);                           // AUSENCIAS (DIAS)
        $this->assertSame(1, $row[6]);                           // VACACIONES (DIAS)
        $this->assertSame(2, $row[7]);                           // INCAPACIDAD (DIAS)
        $this->assertSame(4, $row[8]);                           // H.E. DOBLES (HORAS)
        $this->assertSame(0, $row[9]);                           // H.E. TRIPLES (HORAS)
        $this->assertSame(20, $row[10]);                         // BONO PUNTUALIDAD ($)
        $this->assertSame(150, $row[19]);                        // PRESTAMO EMPRESA ($) (abs)
    }

    public function test_route_downloads_xlsx(): void
    {
        $period = $this->period();
        $employee = Employee::factory()->create(['status' => 'active']);
        PayrollEntry::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
        ]);

        $this->actingAsAdmin();

        $response = $this->get(route('payroll.export.contpaqi-import', ['payroll' => $period->id]));

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml',
            $response->headers->get('content-type') ?? '',
        );
    }

    public function test_headers_match_template(): void
    {
        $this->assertSame('CODIGO EMPLEADO', ContpaqiImportBuilder::HEADERS[0]);
        $this->assertSame('ESTATUS VALIDACION', ContpaqiImportBuilder::HEADERS[23]);
        $this->assertCount(24, ContpaqiImportBuilder::HEADERS);
    }
}
