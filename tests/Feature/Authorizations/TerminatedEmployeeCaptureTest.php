<?php

namespace Tests\Feature\Authorizations;

use App\Models\Authorization;
use App\Models\Department;
use App\Models\Employee;
use App\Services\Reports\WeeklyOvertimeReportService;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\FeatureTestCase;

/**
 * Bajas recientes en captura y reporte de TE (Dani 2026-08-12: dos de
 * Almacén PT dados de baja el viernes con cena/TE/velada de su última semana
 * sin capturar — el selector los escondía y el reporte los omitía).
 *
 * Reglas: los selectores de captura incluyen bajas de los últimos 30 días,
 * etiquetadas "(baja dd/mm)"; el reporte semanal de TE incluye al dado de
 * baja en la semana de su baja (y anteriores), nunca en semanas posteriores.
 */
class TerminatedEmployeeCaptureTest extends FeatureTestCase
{
    private function terminated(string $date, array $attrs = []): Employee
    {
        return Employee::factory()->create(array_merge([
            'status' => 'terminated',
            'termination_date' => $date,
        ], $attrs));
    }

    public function test_capture_selector_includes_recent_terminated_with_label_but_not_old_ones(): void
    {
        $this->actingAsAdmin();
        $reciente = $this->terminated(now()->subDays(5)->toDateString(), ['full_name' => 'Sebastian Baja Reciente']);
        $viejo = $this->terminated(now()->subDays(90)->toDateString(), ['full_name' => 'Baja Antigua']);

        $label = 'Sebastian Baja Reciente (baja '.Carbon::parse($reciente->termination_date)->format('d/m').')';

        $this->get(route('authorizations.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('employees', fn ($employees) => collect($employees)->contains('full_name', $label)
                    && ! collect($employees)->contains('id', $viejo->id)));

        $this->get(route('authorizations.createBulk'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('employees', fn ($employees) => collect($employees)->contains('full_name', $label)
                    && ! collect($employees)->contains('id', $viejo->id)));
    }

    public function test_overtime_can_be_captured_for_recently_terminated(): void
    {
        $this->actingAsSupervisor();
        $emp = $this->terminated(now()->subDays(3)->toDateString());

        $this->from(route('authorizations.create'))->post(route('authorizations.store'), [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => now()->subDays(5)->toDateString(),
            'start_time' => '17:00',
            'end_time' => '18:30',
            'hours' => 1.5,
            'reason' => 'TE de su última semana, capturado tras la baja',
        ])->assertRedirect(route('authorizations.index'));

        $this->assertDatabaseHas('authorizations', [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
        ]);
    }

    public function test_weekly_report_includes_employee_terminated_within_week_only(): void
    {
        $dept = Department::factory()->create();
        // Baja el viernes 13 de marzo; la semana reportada arranca lunes 9.
        $emp = $this->terminated('2026-03-13', [
            'department_id' => $dept->id,
            'full_name' => 'Nolberto Ultima Semana',
        ]);
        Authorization::factory()->create([
            'employee_id' => $emp->id,
            'date' => '2026-03-10',
            'type' => Authorization::TYPE_OVERTIME,
            'start_time' => '17:00',
            'end_time' => '19:00',
            'hours' => 2.0,
            'status' => Authorization::STATUS_APPROVED,
        ]);

        $service = app(WeeklyOvertimeReportService::class);

        $semanaDeBaja = $service->buildReport($dept, Carbon::parse('2026-03-09'));
        $nombres = collect($semanaDeBaja['rows'])->pluck('employee.full_name');
        $this->assertTrue($nombres->contains('Nolberto Ultima Semana'), 'aparece en la semana de su baja');

        $semanaSiguiente = $service->buildReport($dept, Carbon::parse('2026-03-16'));
        $nombresDespues = collect($semanaSiguiente['rows'])->pluck('employee.full_name');
        $this->assertFalse($nombresDespues->contains('Nolberto Ultima Semana'), 'ya no aparece en semanas posteriores');
    }
}
