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
 * Regla de fin de semana (Dani 2026-07-07) para deptos que NO pagan por unidades
 * fijas (todos menos Almacén PT). El "fin de semana" absorbe las primeras T horas
 * (umbral, 7 por omisión):
 *   - < T horas trabajadas: NO cuenta fin de semana, TODO es tiempo extra.
 *   - = T horas: 1 fin de semana, sin tiempo extra.
 *   - > T horas: 1 fin de semana + las horas por encima de T como tiempo extra.
 * Almacén PT (weekend_unit_hours) paga por unidades y aquí no genera tiempo extra
 * (conserva la jornada normal). El pago de OT lo produce VeladaCalculatorService.
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

    /**
     * Crea un registro con horas de presencia dadas (menos 60 min de comida si
     * pasa de 5 h). check_in fijo 08:00.
     */
    private function record(Employee $e, string $date, bool $isWeekend, string $checkOut = '19:00:00', float $worked = 8, float $overtime = 2): AttendanceRecord
    {
        return AttendanceRecord::factory()->create([
            'employee_id' => $e->id,
            'work_date' => $date,
            'check_in' => '08:00:00',
            'check_out' => $checkOut,
            'is_weekend_work' => $isWeekend,
            'worked_hours' => $worked,
            'overtime_hours' => $overtime,
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

    public function test_weekend_over_threshold_pays_only_the_excess_as_overtime(): void
    {
        // 11 h CORRIDAS (08:00–19:00, la comida no se descuenta — Dani
        // 2026-07-08) en fin de semana, umbral 7 → 11 − 7 = 4 h de tiempo
        // extra (y aparte 1 fin de semana).
        $e = $this->employeeIn(null); // normal → umbral por omisión 7
        $rec = $this->record($e, self::SATURDAY, true);
        $this->approveOvertime($e, self::SATURDAY);

        $split = app(VeladaCalculatorService::class)->calculate($rec->fresh(), $e->fresh());

        $this->assertEqualsWithDelta(4.0, (float) $split['overtime_authorized'], 0.01);
    }

    public function test_weekend_below_threshold_is_all_overtime(): void
    {
        // 6 h CORRIDAS (08:00–14:00) en fin de semana, umbral 7 → no gana fin
        // de semana, las 6 h corridas son tiempo extra.
        $e = $this->employeeIn(null);
        $rec = $this->record($e, self::SATURDAY, true, checkOut: '14:00:00', worked: 5, overtime: 0);
        $this->approveOvertime($e, self::SATURDAY);

        $split = app(VeladaCalculatorService::class)->calculate($rec->fresh(), $e->fresh());

        $this->assertEqualsWithDelta(6.0, (float) $split['overtime_authorized'], 0.01);
    }

    public function test_weekend_exactly_at_threshold_pays_no_overtime(): void
    {
        // 7 h CORRIDAS (08:00–15:00) en fin de semana, umbral 7 → 1 fin de
        // semana, 0 tiempo extra.
        $e = $this->employeeIn(null);
        $rec = $this->record($e, self::SATURDAY, true, checkOut: '15:00:00', worked: 6, overtime: 0);
        $this->approveOvertime($e, self::SATURDAY);

        $split = app(VeladaCalculatorService::class)->calculate($rec->fresh(), $e->fresh());

        $this->assertEqualsWithDelta(0.0, (float) $split['overtime_authorized'], 0.01);
    }

    public function test_saldos_custom_threshold_is_respected(): void
    {
        // weekend_overtime_after_hours = 5 → 11 h corridas − 5 = 6 h de TE.
        $e = $this->employeeIn(5);
        $rec = $this->record($e, self::SATURDAY, true);
        $this->approveOvertime($e, self::SATURDAY);

        $split = app(VeladaCalculatorService::class)->calculate($rec->fresh(), $e->fresh());

        $this->assertEqualsWithDelta(6.0, (float) $split['overtime_authorized'], 0.01);
    }

    public function test_almacen_weekend_is_not_paid_as_overtime(): void
    {
        // Almacén PT (weekend_unit_hours) paga el fin de semana por UNIDADES: el
        // umbral de fin de semana es NULL, así que conserva la jornada normal
        // (8 h) → 10 − 8 = 2 h (el comportamiento entre-semana; Almacén ni cobra
        // OT porque se excluye del concepto).
        $e = $this->employeeIn(null, weekendUnitHours: 6);
        $rec = $this->record($e, self::SATURDAY, true);
        $this->approveOvertime($e, self::SATURDAY);

        $this->assertNull($e->fresh()->weekendUnitThreshold());

        $split = app(VeladaCalculatorService::class)->calculate($rec->fresh(), $e->fresh());
        $this->assertEqualsWithDelta(2.0, (float) $split['overtime_authorized'], 0.01);
    }

    public function test_weekday_uses_normal_schedule_not_weekend_threshold(): void
    {
        // Entre semana el umbral sigue siendo la jornada (8), no el de fin de
        // semana: 10 − 8 = 2 h.
        $e = $this->employeeIn(null);
        $rec = $this->record($e, self::WEDNESDAY, false);
        $this->approveOvertime($e, self::WEDNESDAY);

        $split = app(VeladaCalculatorService::class)->calculate($rec->fresh(), $e->fresh());

        $this->assertEqualsWithDelta(2.0, (float) $split['overtime_authorized'], 0.01);
    }

    public function test_weekend_unit_threshold_and_qualification_helpers(): void
    {
        $this->assertNull($this->employeeIn(null, weekendUnitHours: 6)->weekendUnitThreshold(), 'Almacén: por unidades, sin umbral');
        $this->assertEqualsWithDelta(7.0, (float) $this->employeeIn(null)->weekendUnitThreshold(), 0.01, 'Normal: 7 por omisión');
        $this->assertEqualsWithDelta(5.0, (float) $this->employeeIn(5)->weekendUnitThreshold(), 0.01, 'Config: respeta el valor');

        $normal = $this->employeeIn(null);
        $this->assertTrue($normal->qualifiesForWeekendUnit(7.0), '7 h gana fin de semana');
        $this->assertTrue($normal->qualifiesForWeekendUnit(9.5), '9.5 h gana fin de semana');
        $this->assertFalse($normal->qualifiesForWeekendUnit(6.99), '6.99 h no gana fin de semana');
        $this->assertFalse($this->employeeIn(null, weekendUnitHours: 6)->qualifiesForWeekendUnit(10.0), 'Almacén no usa este umbral');
    }

    public function test_pull_over_threshold_suggests_only_the_excess(): void
    {
        // "Cargar desde checadas" (Hora Extra) para un depto normal en fin de
        // semana sugiere el excedente CORRIDO sobre 7 h (11 − 7 = 4 h).
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
            ->assertJsonPath('suggestions.0.hours', '4.00')
            ->assertJsonPath('eligible_count', 1);
    }

    public function test_pull_below_threshold_suggests_all_hours(): void
    {
        // Menos de 7 h: todo es tiempo extra → sugiere las 5 h.
        $this->actingAsAdmin();
        $e = $this->employeeIn(null);
        $this->record($e, self::SATURDAY, true, checkOut: '13:00:00', worked: 5, overtime: 0);

        $this->getJson(route('authorizations.suggestBulk', [
            'employee_ids' => [$e->id],
            'start_date' => self::SATURDAY,
            'end_date' => self::SATURDAY,
            'type' => Authorization::TYPE_OVERTIME,
        ]))
            ->assertOk()
            ->assertJsonPath('suggestions.0.hours', '5.00')
            ->assertJsonPath('eligible_count', 1);
    }
}
