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

    public function test_finiquito_and_cumpleanos_sections(): void
    {
        $this->actingAsAdmin();
        // Cumpleaños: activo que nace en JUNIO (mes del rango) → aparece.
        Employee::factory()->create(['status' => 'active', 'full_name' => 'Cumple June', 'birth_date' => '1990-06-15']);
        // Activo que nace en diciembre → NO aparece (otro mes).
        Employee::factory()->create(['status' => 'active', 'full_name' => 'Cumple Dec', 'birth_date' => '1988-12-20']);
        // Finiquito: baja dentro del rango (incluye dados de baja).
        Employee::factory()->create([
            'status' => 'terminated',
            'full_name' => 'Baja Junio',
            'birth_date' => '1985-01-10',
            'termination_date' => '2026-06-03',
        ]);

        $this->get(route('reports.resumen', ['from' => self::FROM, 'to' => self::TO]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('cumpleanos', 1)
                ->where('cumpleanos.0.name', 'Cumple June')
                ->where('cumpleanos.0.observaciones', 'JUNIO')
                ->has('finiquitos', 1)
                ->where('finiquitos.0.name', 'Baja Junio')
                ->where('finiquitos.0.observaciones', ''));
    }

    public function test_finiquito_amount_prints_when_captured(): void
    {
        // Dani 2026-08-12: el importe se captura en la ficha (junto a la fecha
        // de baja) y sale impreso en la sección; sin captura sigue en blanco.
        $this->actingAsAdmin();
        Employee::factory()->create([
            'status' => 'terminated',
            'full_name' => 'Baja Con Importe',
            'termination_date' => '2026-06-03',
            'finiquito_amount' => 9454.20,
        ]);

        $this->get(route('reports.resumen', ['from' => self::FROM, 'to' => self::TO]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('finiquitos', 1)
                ->where('finiquitos.0.name', 'Baja Con Importe')
                ->where('finiquitos.0.observaciones', '$9,454.20'));
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

    public function test_retardos_below_threshold_never_appear(): void
    {
        // Dani 2026-10-07 (textual): "solo quiero que aparezcan las personas que
        // ya cumplieron los 6 retardos". Acumulados parciales NO se listan.
        $this->actingAsAdmin();
        $e = Employee::factory()->create(['status' => 'active', 'is_attendance_exempt' => false]);
        foreach (['2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04', '2026-06-05'] as $day) {
            AttendanceRecord::factory()->create(['employee_id' => $e->id, 'work_date' => $day, 'status' => 'late']);
        }

        $this->get(route('reports.resumen', ['from' => self::FROM, 'to' => self::TO]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('retardos', 0));
    }

    public function test_frt_appears_while_pending_and_disappears_once_discounted(): void
    {
        // Dani 2026-10-07: la sección lista las faltas por retardo PENDIENTES de
        // descontar; "una vez que ya se haya descontado, que deje de aparecer".
        $this->actingAsAdmin();
        $this->type('FRT', 'late_accumulation', ['is_paid' => false, 'requires_approval' => false]);
        $e = Employee::factory()->create(['status' => 'active', 'is_attendance_exempt' => false]);
        foreach ([
            '2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04', '2026-06-05', '2026-06-08',
        ] as $day) {
            AttendanceRecord::factory()->create(['employee_id' => $e->id, 'work_date' => $day, 'status' => 'late']);
        }
        app(\App\Services\LateAbsenceService::class)->ensureMonthlyIncidentsGenerated($e);

        // Pendiente: aparece en la semana del cruce (6º retardo el 8 de junio)…
        $this->get(route('reports.resumen', ['from' => '2026-06-08', 'to' => '2026-06-14']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('retardos', 1)
                ->where('retardos.0.date', '08/06/2026')
                ->where('retardos.0.observaciones', '1 falta por retardos pendiente de descontar en nómina'));

        // …y SIGUE apareciendo en semanas posteriores mientras no se descuente.
        $this->get(route('reports.resumen', ['from' => '2026-06-22', 'to' => '2026-06-28']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('retardos', 1));

        // Descontada: el periodo semanal que contiene el cargo se cierra con
        // recibo del empleado → deja de aparecer en cualquier semana.
        $period = \App\Models\PayrollPeriod::factory()->weekly()->approved()->create([
            'start_date' => '2026-06-08',
            'end_date' => '2026-06-14',
        ]);
        \App\Models\PayrollEntry::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $e->id,
        ]);

        $this->get(route('reports.resumen', ['from' => '2026-06-22', 'to' => '2026-06-28']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('retardos', 0));
    }

    public function test_pardoned_frt_does_not_appear_as_pending(): void
    {
        // Una FRT borrada es un perdón humano: no está pendiente de nada.
        $this->actingAsAdmin();
        $this->type('FRT', 'late_accumulation', ['is_paid' => false, 'requires_approval' => false]);
        $e = Employee::factory()->create(['status' => 'active', 'is_attendance_exempt' => false]);
        foreach ([
            '2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04', '2026-06-05', '2026-06-08',
        ] as $day) {
            AttendanceRecord::factory()->create(['employee_id' => $e->id, 'work_date' => $day, 'status' => 'late']);
        }
        app(\App\Services\LateAbsenceService::class)->ensureMonthlyIncidentsGenerated($e);
        Incident::where('employee_id', $e->id)->get()->each->delete();

        $this->get(route('reports.resumen', ['from' => '2026-06-08', 'to' => '2026-06-14']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('retardos', 0));
    }

    public function test_faltas_and_retardos_coexist_without_clobbering(): void
    {
        // Guard contra la colisión de variable: con faltas Y una FRT pendiente
        // a la vez, ambas secciones deben quedar como listas pobladas.
        $this->actingAsAdmin();
        $falton = Employee::factory()->create(['status' => 'active', 'is_attendance_exempt' => false]);
        $retardon = Employee::factory()->create(['status' => 'active', 'is_attendance_exempt' => false]);

        $this->type('FRT', 'late_accumulation', ['is_paid' => false, 'requires_approval' => false]);
        AttendanceRecord::factory()->create(['employee_id' => $falton->id, 'work_date' => '2026-06-03', 'status' => 'absent']);
        foreach (['2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04', '2026-06-05', '2026-06-08'] as $day) {
            AttendanceRecord::factory()->create(['employee_id' => $retardon->id, 'work_date' => $day, 'status' => 'late']);
        }
        app(\App\Services\LateAbsenceService::class)->ensureMonthlyIncidentsGenerated($retardon);

        // Rango que incluye la falta (3 jun) y el cargo de la FRT (8 jun).
        $this->get(route('reports.resumen', ['from' => self::FROM, 'to' => '2026-06-14']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('faltas', 1)
                ->where('faltas.0.name', $falton->full_name)
                ->has('retardos', 1)
                ->where('retardos.0.name', $retardon->full_name));
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
