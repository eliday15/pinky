<?php

namespace Tests\Feature\Incidents;

use App\Models\Employee;
use App\Models\Incident;
use App\Models\IncidentType;
use Tests\FeatureTestCase;

/**
 * Formato individual de vacaciones en MEDIA CARTA (Dani 2026-08-12): el
 * encargado imprime la hoja al capturar — fechas tomadas, desglose del saldo
 * y líneas de firma. Solo aplica a incidencias de categoría vacation y respeta
 * el scoping de visibilidad del módulo.
 */
class VacationFormPrintTest extends FeatureTestCase
{
    private function vacationIncident(): Incident
    {
        $type = IncidentType::factory()->create([
            'code' => 'VACX',
            'name' => 'Vacaciones',
            'category' => 'vacation',
        ]);
        $employee = Employee::factory()->create([
            'status' => 'active',
            'vacation_days_entitled' => 22,
            'vacation_days_used' => 10,
        ]);

        return Incident::factory()->approved()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'start_date' => '2026-06-08',
            'end_date' => '2026-06-09',
            'days_count' => 2,
        ]);
    }

    public function test_vacation_form_downloads_as_half_letter_pdf(): void
    {
        $this->actingAsAdmin();
        $incident = $this->vacationIncident();

        $response = $this->get(route('incidents.vacationForm', $incident));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));
    }

    public function test_non_vacation_incident_returns_404(): void
    {
        $this->actingAsAdmin();
        $type = IncidentType::factory()->create(['code' => 'PERX', 'category' => 'permission']);
        $incident = Incident::factory()->approved()->create([
            'employee_id' => Employee::factory()->create()->id,
            'incident_type_id' => $type->id,
            'start_date' => '2026-06-08',
            'end_date' => '2026-06-08',
            'days_count' => 1,
        ]);

        $this->get(route('incidents.vacationForm', $incident))->assertNotFound();
    }

    public function test_supervisor_cannot_print_foreign_team_form(): void
    {
        $incident = $this->vacationIncident();
        $this->actingAsSupervisor(); // sin equipo que incluya al empleado

        $this->get(route('incidents.vacationForm', $incident))->assertForbidden();
    }
}
