<?php

namespace Tests\Feature\Reports;

use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Services\Reports\AccountantReportService;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\FeatureTestCase;

/**
 * "Reporte al contador": resumen semanal por empresa (VP / AVL / POR FUERA)
 * con vacaciones, prima vacacional, faltas, faltas por retardo, incapacidades y
 * finiquitos. Cada empleado cae en la hoja de su campo `empresa`.
 */
class AccountantReportTest extends FeatureTestCase
{
    private const WEEK_START = '2026-07-13'; // lunes
    private const WEEK_END = '2026-07-21';   // martes siguiente

    private function typeWithCode(string $code, array $attributes): IncidentType
    {
        $existing = IncidentType::where('code', $code)->first();
        if ($existing) {
            $existing->update($attributes);

            return $existing->fresh();
        }

        return IncidentType::factory()->create(array_merge(['code' => $code], $attributes));
    }

    private function vacType(): IncidentType
    {
        return $this->typeWithCode('VAC', ['category' => 'vacation', 'is_paid' => true, 'requires_approval' => true]);
    }

    private function incType(): IncidentType
    {
        return $this->typeWithCode('INC', ['category' => 'sick_leave', 'is_paid' => true, 'requires_approval' => true]);
    }

    private function frtType(): IncidentType
    {
        return $this->typeWithCode('FRT', ['category' => 'late_accumulation', 'is_paid' => false, 'requires_approval' => false]);
    }

    private function build(): array
    {
        return app(AccountantReportService::class)->build(
            Carbon::parse(self::WEEK_START),
            Carbon::parse(self::WEEK_END),
        );
    }

    /** VP = fuera de taller y ya formalizado; la empresa se deriva sola. */
    private function vpEmployee(): Employee
    {
        return Employee::factory()->create([
            'status' => 'active',
            'department_id' => Department::factory()->create(['name' => 'Corte'])->id,
            'is_trial_period' => false,
        ]);
    }

    /** AVL = departamento Taller (la empresa se deriva a AVL). */
    private function avlEmployee(): Employee
    {
        return Employee::factory()->create([
            'status' => 'active',
            'department_id' => Department::factory()->create(['name' => 'Taller Adriana'])->id,
            'is_trial_period' => false,
        ]);
    }

    /** POR FUERA = fuera de taller y en periodo de prueba. */
    private function porFueraEmployee(): Employee
    {
        return Employee::factory()->create([
            'status' => 'active',
            'department_id' => Department::factory()->create(['name' => 'Corte'])->id,
            'is_trial_period' => true,
        ]);
    }

    public function test_service_splits_sections_by_empresa(): void
    {
        $vp = $this->vpEmployee();
        $avl = $this->avlEmployee();
        $fuera = $this->porFueraEmployee();

        // VP: una falta (martes 14) + vacaciones 15-16.
        AttendanceRecord::factory()->for($vp)->create([
            'work_date' => '2026-07-14',
            'check_in' => null,
            'check_out' => null,
            'status' => 'absent',
            'worked_hours' => 0,
        ]);
        Incident::factory()->approved()->create([
            'employee_id' => $vp->id,
            'incident_type_id' => $this->vacType()->id,
            'start_date' => '2026-07-15',
            'end_date' => '2026-07-16',
            'days_count' => 2,
        ]);

        // AVL: incapacidad 13-14 + finiquito (baja el 16).
        Incident::factory()->approved()->create([
            'employee_id' => $avl->id,
            'incident_type_id' => $this->incType()->id,
            'start_date' => '2026-07-13',
            'end_date' => '2026-07-14',
            'days_count' => 2,
        ]);
        $avl->update(['termination_date' => '2026-07-16', 'status' => 'terminated']);

        // POR FUERA: vacaciones el 20 → vacaciones + prima vacacional (por fuera).
        Incident::factory()->approved()->create([
            'employee_id' => $fuera->id,
            'incident_type_id' => $this->vacType()->id,
            'start_date' => '2026-07-20',
            'end_date' => '2026-07-20',
            'days_count' => 1,
        ]);

        $report = $this->build();

        // VP
        $this->assertCount(2, $report['VP']['vacaciones']);
        $this->assertCount(1, $report['VP']['faltas']);
        $this->assertSame($vp->full_name, $report['VP']['faltas'][0][0]);
        $this->assertSame('14/07/2026', $report['VP']['faltas'][0][1]);
        $this->assertCount(0, $report['VP']['prima_vacacional']);

        // AVL
        $this->assertCount(2, $report['AVL']['incapacidades']);
        $this->assertCount(1, $report['AVL']['finiquito']);
        $this->assertSame('16/07/2026', $report['AVL']['finiquito'][0][1]);
        $this->assertCount(0, $report['AVL']['vacaciones']);

        // POR FUERA
        $this->assertCount(1, $report['POR_FUERA']['vacaciones']);
        $this->assertCount(1, $report['POR_FUERA']['prima_vacacional']);
        $this->assertSame($fuera->full_name, $report['POR_FUERA']['prima_vacacional'][0][0]);
    }

    public function test_service_reports_charged_faltas_por_retardo(): void
    {
        $vp = $this->vpEmployee();

        Incident::factory()->approved()->create([
            'employee_id' => $vp->id,
            'incident_type_id' => $this->frtType()->id,
            'start_date' => '2026-07-15',
            'end_date' => '2026-07-15',
            'late_month' => '2026-07',
            'days_count' => 2,
        ]);

        $report = $this->build();

        $this->assertCount(1, $report['VP']['faltas_retardo']);
        $this->assertStringContainsString('cobrada en nómina', $report['VP']['faltas_retardo'][0][2]);
    }

    public function test_page_renders_for_admin(): void
    {
        $this->actingAsAdmin();

        $this->get(route('reports.accountant', [
            'start_date' => self::WEEK_START,
            'end_date' => self::WEEK_END,
        ]))->assertInertia(fn (Assert $page) => $page
            ->component('Reports/Accountant')
            ->has('report.VP')
            ->has('report.AVL')
            ->has('report.POR_FUERA')
            ->where('weekLabel', 'SEMANA DEL 13 AL 21 julio 2026')
        );
    }

    public function test_export_downloads_xlsx(): void
    {
        $this->actingAsAdmin();

        $response = $this->get(route('reports.accountant.export', [
            'start_date' => self::WEEK_START,
            'end_date' => self::WEEK_END,
        ]));

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml',
            $response->headers->get('content-type') ?? '',
        );
    }
}
