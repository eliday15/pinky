<?php

namespace Tests\Feature\Reports;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Incident;
use App\Models\IncidentType;
use Tests\FeatureTestCase;

/**
 * Resumen semanal en una vista (Luis 2026-07-30): Vacaciones, Faltas, Faltas por
 * retardo e Incapacidades del rango. Cada sección respeta la misma fuente de
 * verdad que su reporte dedicado (las justificadas no cuentan como falta).
 */
class WeeklySummaryReportTest extends FeatureTestCase
{
    private const FROM = '2026-06-01';

    private const TO = '2026-06-07';

    private function type(string $code, string $category, array $extra = []): IncidentType
    {
        return IncidentType::firstOrCreate(['code' => $code], array_merge([
            'name' => $code,
            'category' => $category,
            'is_paid' => true,
            'requires_approval' => true,
        ], $extra));
    }

    private function approvedIncident(Employee $e, IncidentType $type, string $start, string $end): Incident
    {
        return Incident::factory()->create([
            'employee_id' => $e->id,
            'incident_type_id' => $type->id,
            'status' => 'approved',
            'start_date' => $start,
            'end_date' => $end,
            'days_count' => 1,
        ]);
    }

    public function test_index_renders_the_four_sections(): void
    {
        $this->actingAsAdmin();
        $e = Employee::factory()->create(['status' => 'active', 'full_name' => 'Ana Lopez']);

        $this->approvedIncident($e, $this->type('VAC', 'vacation', ['deducts_vacation' => true]), self::FROM, self::FROM);
        $this->approvedIncident($e, $this->type('INC', 'sick_leave'), '2026-06-02', '2026-06-02');

        $this->get(route('reports.resumen', ['from' => self::FROM, 'to' => self::TO]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Reports/ResumenSemanal')
                ->where('from', self::FROM)
                ->where('to', self::TO)
                ->has('vacaciones', 1)
                ->has('incapacidades', 1)
                ->has('faltas')
                ->has('retardos'));
    }

    public function test_absent_day_appears_in_faltas_but_justified_does_not(): void
    {
        $this->actingAsAdmin();
        $falton = Employee::factory()->create(['status' => 'active', 'is_attendance_exempt' => false]);
        $justificado = Employee::factory()->create(['status' => 'active', 'is_attendance_exempt' => false]);

        // Falta pura (sin incidencia que la justifique).
        AttendanceRecord::factory()->create([
            'employee_id' => $falton->id,
            'work_date' => '2026-06-03',
            'status' => 'absent',
        ]);

        // Ausencia el mismo día pero justificada por una vacación aprobada.
        AttendanceRecord::factory()->create([
            'employee_id' => $justificado->id,
            'work_date' => '2026-06-03',
            'status' => 'absent',
        ]);
        $this->approvedIncident($justificado, $this->type('VAC', 'vacation', ['deducts_vacation' => true]), '2026-06-03', '2026-06-03');

        $this->get(route('reports.resumen', ['from' => self::FROM, 'to' => self::TO]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('faltas', 1) // solo el faltón; el justificado NO aparece
                ->where('faltas.0.name', $falton->full_name));
    }

    public function test_late_records_group_into_retardos_with_count(): void
    {
        $this->actingAsAdmin();
        $e = Employee::factory()->create(['status' => 'active', 'is_attendance_exempt' => false]);

        foreach (['2026-06-01', '2026-06-02', '2026-06-03'] as $day) {
            AttendanceRecord::factory()->create([
                'employee_id' => $e->id,
                'work_date' => $day,
                'status' => 'late',
            ]);
        }

        $this->get(route('reports.resumen', ['from' => self::FROM, 'to' => self::TO]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('retardos', 1)
                ->where('retardos.0.count', 3)
                ->where('retardos.0.observaciones', '3 retardos'));
    }

    public function test_export_downloads_xlsx(): void
    {
        $this->actingAsAdmin();

        $this->get(route('reports.resumen.export', ['from' => self::FROM, 'to' => self::TO]))
            ->assertDownload('resumen_semanal_'.self::FROM.'_'.self::TO.'.xlsx');
    }

    public function test_user_without_reports_view_all_is_forbidden(): void
    {
        $this->actingAs($this->employeeUser());

        $this->get(route('reports.resumen'))->assertForbidden();
    }
}
