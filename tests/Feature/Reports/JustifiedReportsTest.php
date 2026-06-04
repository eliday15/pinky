<?php

namespace Tests\Feature\Reports;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Incident;
use App\Models\IncidentType;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\FeatureTestCase;

/**
 * Fase B (auditoría C2, hallazgos 12 y 35): los reportes de faltas (web y
 * CSV) y de salidas tempranas excluyen los días cubiertos por incidencias
 * aprobadas que justifican — la misma regla que respeta la nómina
 * (Incident::typeJustifiesAbsence). Una falta justificada ya no aparece como
 * falta; un permiso de salida (PSA) exime la "falta" por salida temprana.
 */
class JustifiedReportsTest extends FeatureTestCase
{
    private function employee(): Employee
    {
        return Employee::factory()->create(['status' => 'active']);
    }

    /**
     * Algunos códigos pueden existir ya (la migración
     * migrate_admin_authorizations_to_incidents crea PSA/PEN): actualizar o crear.
     */
    private function typeWithCode(string $code, array $attributes): IncidentType
    {
        $existing = IncidentType::where('code', $code)->first();

        if ($existing) {
            $existing->update($attributes);

            return $existing->fresh();
        }

        return IncidentType::factory()->create(array_merge(['code' => $code], $attributes));
    }

    private function fjuType(): IncidentType
    {
        return $this->typeWithCode('FJU', [
            'category' => 'absence',
            'is_paid' => true,
            'requires_approval' => true,
        ]);
    }

    private function psaType(): IncidentType
    {
        return $this->typeWithCode('PSA', [
            'category' => 'permission',
            'affects_attendance' => true,
            'is_paid' => true,
            'requires_approval' => true,
        ]);
    }

    private function absentNoShow(Employee $employee, string $date = '2026-06-03'): AttendanceRecord
    {
        return AttendanceRecord::factory()->for($employee)->create([
            'work_date' => $date,
            'check_in' => null,
            'check_out' => null,
            'status' => 'absent',
            'worked_hours' => 0,
        ]);
    }

    public function test_faltas_report_excludes_justified_absences(): void
    {
        $justified = $this->employee();
        $unjustified = $this->employee();

        $this->absentNoShow($justified);
        $this->absentNoShow($unjustified);

        Incident::factory()->approved()->create([
            'employee_id' => $justified->id,
            'incident_type_id' => $this->fjuType()->id,
            'start_date' => '2026-06-03',
            'end_date' => '2026-06-03',
            'days_count' => 1,
        ]);

        $this->actingAsAdmin();

        $this->get(route('reports.faltas', [
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]))->assertInertia(fn (Assert $page) => $page
            ->component('Reports/Faltas')
            ->has('byEmployee', 1)
            ->where('byEmployee.0.employee.id', $unjustified->id)
            ->where('byEmployee.0.no_show_faltas', 1)
            ->where('summary.total_faltas', 1)
        );
    }

    public function test_export_faltas_excludes_justified_absences(): void
    {
        $justified = $this->employee();
        $unjustified = $this->employee();

        $this->absentNoShow($justified);
        $this->absentNoShow($unjustified);

        Incident::factory()->approved()->create([
            'employee_id' => $justified->id,
            'incident_type_id' => $this->fjuType()->id,
            'start_date' => '2026-06-03',
            'end_date' => '2026-06-03',
            'days_count' => 1,
        ]);

        $this->actingAsAdmin();

        $response = $this->get(route('reports.export.faltas', [
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]));

        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringNotContainsString($justified->full_name, $content, 'la falta justificada no se exporta');
        $this->assertStringContainsString($unjustified->full_name, $content);
    }

    public function test_early_departures_report_respects_approved_exit_permission(): void
    {
        $withPermission = $this->employee();
        $without = $this->employee();

        // El empleado SIN permiso tiene 2 salidas tempranas (Jun 3 y 4) para
        // que el orden por departure_count sea determinista: él va primero.
        foreach (['2026-06-03', '2026-06-04'] as $date) {
            AttendanceRecord::factory()->for($without)->create([
                'work_date' => $date,
                'check_in' => '08:00:00',
                'check_out' => '15:00:00',
                'status' => 'present',
                'early_departure_minutes' => 120,
            ]);
        }

        AttendanceRecord::factory()->for($withPermission)->create([
            'work_date' => '2026-06-03',
            'check_in' => '08:00:00',
            'check_out' => '15:00:00',
            'status' => 'present',
            'early_departure_minutes' => 120,
        ]);

        Incident::factory()->approved()->create([
            'employee_id' => $withPermission->id,
            'incident_type_id' => $this->psaType()->id,
            'start_date' => '2026-06-03',
            'end_date' => '2026-06-03',
            'days_count' => 1,
            'hours' => 2.0,
        ]);

        $this->actingAsAdmin();

        $this->get(route('reports.earlyDepartures', [
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]))->assertInertia(fn (Assert $page) => $page
            ->component('Reports/SalidasTempranas')
            ->has('byEmployee', 2)
            // [0] = sin permiso (2 salidas): ambas son falta.
            ->where('byEmployee.0.employee.id', $without->id)
            ->where('byEmployee.0.faltas_count', 2)
            ->where('byEmployee.0.dates.0.is_falta', true)
            // [1] = con PSA aprobado: la salida no es falta.
            ->where('byEmployee.1.employee.id', $withPermission->id)
            ->where('byEmployee.1.faltas_count', 0)
            ->where('byEmployee.1.dates.0.is_falta', false)
            ->where('summary.faltas_generated', 2)
        );
    }
}
