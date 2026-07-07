<?php

namespace Tests\Feature\Payroll;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Schedule;
use App\Services\VeladaCalculatorService;
use Tests\FeatureTestCase;

/**
 * Saldos – Opción A (Dani 2026-06-29): en FIN DE SEMANA, el trabajo se paga como
 * fin de semana normal y las horas que EXCEDAN de un umbral (7) se pagan como
 * tiempo extra. Se modela con departments.weekend_overtime_after_hours; el pago
 * de overtime lo produce VeladaCalculatorService (usa ese umbral solo en días de
 * fin de semana, en vez de la jornada normal). El FIN se paga por día aparte, así
 * que el tiempo extra es aditivo.
 */
class WeekendOvertimeThresholdTest extends FeatureTestCase
{
    private const SATURDAY = '2026-06-20';
    private const WEDNESDAY = '2026-06-17';

    private function employeeIn(?float $weekendOtThreshold, ?int $weekendUnitHours = null): Employee
    {
        $dept = Department::factory()->create([
            'weekend_overtime_after_hours' => $weekendOtThreshold,
            'weekend_unit_hours' => $weekendUnitHours,
        ]);
        $schedule = Schedule::factory()->create([
            'daily_work_hours' => 8,
            'break_minutes' => 60,
            'entry_time' => '08:00',
            'exit_time' => '17:00',
        ]);

        return Employee::factory()->create([
            'department_id' => $dept->id,
            'schedule_id' => $schedule->id,
            'status' => 'active',
        ]);
    }

    /** 08:00–19:00 = 11 h de presencia − 60 min de comida = 10 h trabajadas. */
    private function record(Employee $e, string $date, bool $isWeekend): AttendanceRecord
    {
        return AttendanceRecord::factory()->create([
            'employee_id' => $e->id,
            'work_date' => $date,
            'check_in' => '08:00:00',
            'check_out' => '19:00:00',
            'is_weekend_work' => $isWeekend,
            'worked_hours' => 8,
            'overtime_hours' => 2,
        ]);
    }

    private function approveOvertime(Employee $e, string $date, float $hours = 24): void
    {
        Authorization::factory()->create([
            'employee_id' => $e->id,
            'date' => $date,
            'type' => Authorization::TYPE_OVERTIME,
            'hours' => $hours,
            'status' => Authorization::STATUS_APPROVED,
        ]);
    }

    public function test_saldos_weekend_overtime_starts_after_seven_hours(): void
    {
        $e = $this->employeeIn(7);
        $rec = $this->record($e, self::SATURDAY, true);
        $this->approveOvertime($e, self::SATURDAY);

        $split = app(VeladaCalculatorService::class)->calculate($rec->fresh(), $e->fresh());

        // 10 h trabajadas − 7 = 3 h de tiempo extra.
        $this->assertEqualsWithDelta(3.0, (float) $split['overtime_authorized'], 0.01);
    }

    public function test_normal_department_weekend_pays_all_worked_hours_as_overtime(): void
    {
        // Dani 2026-07-07 (caso Carla/Calidad): en un depto que NO paga por
        // unidades, TODO lo trabajado en fin de semana es tiempo extra "sin
        // importar el horario". Umbral = 0 → 10 h trabajadas = 10 h extra.
        $e = $this->employeeIn(null);
        $rec = $this->record($e, self::SATURDAY, true);
        $this->approveOvertime($e, self::SATURDAY);

        $split = app(VeladaCalculatorService::class)->calculate($rec->fresh(), $e->fresh());

        $this->assertEqualsWithDelta(10.0, (float) $split['overtime_authorized'], 0.01);
    }

    public function test_almacen_weekend_is_not_paid_as_overtime(): void
    {
        // Almacén PT (weekend_unit_hours) paga el fin de semana por UNIDADES, no
        // como tiempo extra: el umbral de fin de semana es NULL, así que se
        // conserva la jornada normal (8 h) y el split de OT no se dispara por la
        // regla nueva. (10 h − 8 = 2 h, el comportamiento entre-semana normal;
        // Almacén de todas formas no cobra OT porque se excluye del concepto.)
        $e = $this->employeeIn(null, weekendUnitHours: 6);
        $rec = $this->record($e, self::SATURDAY, true);
        $this->approveOvertime($e, self::SATURDAY);

        $this->assertNull($e->fresh()->weekendOvertimeThreshold());

        $split = app(VeladaCalculatorService::class)->calculate($rec->fresh(), $e->fresh());
        $this->assertEqualsWithDelta(2.0, (float) $split['overtime_authorized'], 0.01);
    }

    public function test_weekend_overtime_threshold_helper_by_department_kind(): void
    {
        $this->assertNull($this->employeeIn(null, weekendUnitHours: 6)->weekendOvertimeThreshold(), 'Almacén: sin OT de fin de semana');
        $this->assertEqualsWithDelta(7.0, (float) $this->employeeIn(7)->weekendOvertimeThreshold(), 0.01, 'Saldos: umbral 7');
        $this->assertEqualsWithDelta(0.0, (float) $this->employeeIn(null)->weekendOvertimeThreshold(), 0.01, 'Normal: todo es OT (umbral 0)');
    }

    public function test_normal_department_weekend_pull_offers_all_worked_hours(): void
    {
        // "Cargar desde checadas" (Hora Extra) para un depto normal en fin de
        // semana sugiere TODAS las horas trabajadas (10 h) para autorizar.
        $this->actingAsAdmin();
        $e = $this->employeeIn(null);
        $this->record($e, self::SATURDAY, true);

        $this->getJson(route('authorizations.suggestBulk', [
            'employee_ids' => [$e->id],
            'start_date' => self::SATURDAY,
            'end_date' => self::SATURDAY,
            'type' => Authorization::TYPE_OVERTIME,
        ]))
            ->assertOk()
            ->assertJsonPath('suggestions.0.hours', '10.00')
            ->assertJsonPath('eligible_count', 1);
    }

    public function test_saldos_threshold_only_applies_to_weekend_days(): void
    {
        // Entre semana el umbral sigue siendo la jornada (8), no el de fin de semana.
        $e = $this->employeeIn(7);
        $rec = $this->record($e, self::WEDNESDAY, false);
        $this->approveOvertime($e, self::WEDNESDAY);

        $split = app(VeladaCalculatorService::class)->calculate($rec->fresh(), $e->fresh());

        $this->assertEqualsWithDelta(2.0, (float) $split['overtime_authorized'], 0.01);
    }

    public function test_suggest_bulk_pulls_weekend_overtime_after_threshold(): void
    {
        // "Cargar desde checadas" para Saldos en fin de semana sugiere el excedente
        // sobre 7 h (10 h trabajadas − 7 = 3 h) para que RRHH lo autorice.
        $this->actingAsAdmin();
        $e = $this->employeeIn(7);
        $this->record($e, self::SATURDAY, true);

        $this->getJson(route('authorizations.suggestBulk', [
            'employee_ids' => [$e->id],
            'start_date' => self::SATURDAY,
            'end_date' => self::SATURDAY,
            'type' => Authorization::TYPE_OVERTIME,
        ]))
            ->assertOk()
            ->assertJsonPath('suggestions.0.hours', '3.00')
            ->assertJsonPath('eligible_count', 1);
    }
}
