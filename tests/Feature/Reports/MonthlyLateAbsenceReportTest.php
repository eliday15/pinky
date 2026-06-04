<?php

namespace Tests\Feature\Reports;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\IncidentType;
use App\Models\PayrollPeriod;
use App\Models\SystemSetting;
use App\Services\LateAbsenceService;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\FeatureTestCase;

/**
 * Coherencia reportes ↔ nómina para la regla mensual retardos→falta y los
 * filtros corregidos de reportes:
 * - El reporte de faltas (web y CSV) lee las MISMAS incidencias FRT que cobra
 *   la nómina para meses cerrados, y etiqueta el mes en curso como proyección.
 * - El umbral de retardos viene del SystemSetting (nunca hardcodeado).
 * - El reporte de nómina solo lista periodos cerrados (approved/paid), mismo
 *   criterio que payrollTrends.
 *
 * Fechas viajan a 2026-08-10 (junio/julio cerrados; corte de la regla: 2026-06).
 */
class MonthlyLateAbsenceReportTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 8, 10));

        IncidentType::factory()->create([
            'code' => 'FRT',
            'name' => 'Falta por retardos',
            'category' => 'late_accumulation',
            'is_paid' => false,
            'requires_approval' => false,
            'is_active' => true,
        ]);
    }

    private function employee(): Employee
    {
        return Employee::factory()->create(['status' => 'active']);
    }

    /**
     * 12 retardos en días hábiles de junio 2026 (umbral 6 → 2 faltas).
     */
    private function twelveJuneLates(Employee $employee): void
    {
        $dates = [
            '2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04', '2026-06-05',
            '2026-06-08', '2026-06-09', '2026-06-10', '2026-06-11', '2026-06-12',
            '2026-06-15', '2026-06-16',
        ];

        foreach ($dates as $date) {
            AttendanceRecord::factory()->for($employee)->create([
                'work_date' => $date,
                'status' => 'late',
                'late_minutes' => 15,
            ]);
        }
    }

    public function test_faltas_report_shows_charged_frt_from_incidents(): void
    {
        $employee = $this->employee();
        $this->twelveJuneLates($employee);
        app(LateAbsenceService::class)->ensureMonthlyIncidentsGenerated($employee);

        $this->actingAsAdmin();

        $this->get(route('reports.faltas', [
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]))->assertInertia(fn (Assert $page) => $page
            ->component('Reports/Faltas')
            ->where('byEmployee.0.retardo_faltas', 2)
            ->where('byEmployee.0.total_faltas', 2)
            ->where('byEmployee.0.retardo_details.0.source', 'cobrada')
            ->where('byEmployee.0.retardo_details.0.month', '2026-06')
            ->where('byEmployee.0.retardo_details.0.late_count', 12)
            ->where('byEmployee.0.retardo_details.0.charged_on', '2026-07-01')
            ->where('summary.retardo_faltas', 2)
        );
    }

    public function test_faltas_report_projects_current_month(): void
    {
        $employee = $this->employee();

        // 6 retardos en agosto (mes en curso): proyección, aún no cobrada.
        foreach (['2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06', '2026-08-07', '2026-08-10'] as $date) {
            AttendanceRecord::factory()->for($employee)->create([
                'work_date' => $date,
                'status' => 'late',
                'late_minutes' => 15,
            ]);
        }

        $this->actingAsAdmin();

        $this->get(route('reports.faltas', [
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
        ]))->assertInertia(fn (Assert $page) => $page
            ->component('Reports/Faltas')
            ->where('byEmployee.0.retardo_faltas', 1)
            ->where('byEmployee.0.retardo_details.0.source', 'proyeccion')
        );
    }

    public function test_faltas_report_ignores_months_before_rule_start(): void
    {
        SystemSetting::set('monthly_late_absence_start_month', '2026-09');

        $employee = $this->employee();
        $this->twelveJuneLates($employee);

        $this->actingAsAdmin();

        $this->get(route('reports.faltas', [
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]))->assertInertia(fn (Assert $page) => $page
            ->component('Reports/Faltas')
            ->where('summary.retardo_faltas', 0)
        );
    }

    public function test_export_faltas_marks_charged_months(): void
    {
        $employee = $this->employee();
        $this->twelveJuneLates($employee);
        app(LateAbsenceService::class)->ensureMonthlyIncidentsGenerated($employee);

        $this->actingAsAdmin();

        $response = $this->get(route('reports.export.faltas', [
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]));

        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString('cobrada en nómina', $content);
        $this->assertStringContainsString($employee->full_name, $content);
    }

    public function test_export_faltas_labels_current_month_projection(): void
    {
        $employee = $this->employee();

        foreach (['2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06', '2026-08-07', '2026-08-10'] as $date) {
            AttendanceRecord::factory()->for($employee)->create([
                'work_date' => $date,
                'status' => 'late',
                'late_minutes' => 15,
            ]);
        }

        $this->actingAsAdmin();

        $response = $this->get(route('reports.export.faltas', [
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
        ]));

        $response->assertOk();
        $this->assertStringContainsString('proyección, mes en curso', $response->streamedContent());
    }

    public function test_late_arrivals_uses_configurable_threshold(): void
    {
        // SystemSetting::set solo actualiza filas existentes; en tests la
        // tabla nace vacía (el seeder no corre), así que se crea la fila.
        SystemSetting::factory()->create([
            'key' => 'late_to_absence_count',
            'value' => '3',
            'type' => 'integer',
            'group' => 'attendance',
        ]);

        $employee = $this->employee();

        foreach (['2026-06-01', '2026-06-02', '2026-06-03'] as $date) {
            AttendanceRecord::factory()->for($employee)->create([
                'work_date' => $date,
                'status' => 'late',
                'late_minutes' => 15,
            ]);
        }

        $this->actingAsAdmin();

        $this->get(route('reports.lateArrivals', [
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]))->assertInertia(fn (Assert $page) => $page
            ->component('Reports/LateArrivals')
            ->where('summary.critical_employees', 1)
            ->where('summary.late_to_absence_threshold', 3)
        );
    }

    public function test_payroll_report_lists_only_closed_periods(): void
    {
        $approved = PayrollPeriod::factory()->weekly()->approved()->create([
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-07',
        ]);
        $draft = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-07-08',
            'end_date' => '2026-07-14',
        ]);

        $this->actingAsAdmin();

        // Solo el periodo cerrado aparece en la lista.
        $this->get(route('reports.payroll'))->assertInertia(fn (Assert $page) => $page
            ->component('Reports/Payroll')
            ->has('periods', 1)
            ->where('periods.0.id', $approved->id)
        );

        // Seleccionar explícitamente un periodo en borrador no muestra montos.
        $this->get(route('reports.payroll', ['period' => $draft->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Payroll')
                ->where('selectedPeriod', null)
            );
    }
}
