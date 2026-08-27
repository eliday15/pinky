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
 * Reglas de respaldo por VENTANAS (Luis 2026-08-27, casos Policarpo, Pamela,
 * Eva, Martin):
 *  - Una velada PURA (entrar solo a velar) se mide por su ventana aunque el
 *    neto del día no supere la jornada — la noche aprobada cuenta y paga.
 *  - El TE aprobado se respalda por la UNIÓN de sus ventanas contra el span
 *    checado; la parte en ventana de velada se descuenta SOLO si hay velada
 *    aprobada ese día (sin velada no hay doble pago).
 *  - Dos capturas encimadas no suman doble respaldo.
 */
class VeladaWindowBackedTest extends FeatureTestCase
{
    private const DATE = '2026-06-07'; // domingo

    private const WEEKDAY = '2026-06-03'; // miércoles

    private function employee(bool $unitDept = false): Employee
    {
        $schedule = Schedule::factory()->create([
            'entry_time' => '08:00',
            'exit_time' => '17:30',
            'daily_work_hours' => 9,
            'break_minutes' => 60,
            'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
        ]);
        $dept = Department::factory()->create($unitDept
            ? ['name' => 'Almacén PT', 'code' => 'ALM-'.uniqid(), 'weekend_unit_hours' => 6]
            : ['name' => 'Telas', 'code' => 'TEL-'.uniqid()]);

        return Employee::factory()->create([
            'status' => 'active',
            'schedule_id' => $schedule->id,
            'department_id' => $dept->id,
        ]);
    }

    private function record(Employee $e, string $date, string $in, string $out, bool $nextDayOut = false, bool $weekend = false): AttendanceRecord
    {
        return AttendanceRecord::factory()->for($e)->create([
            'work_date' => $date,
            'check_in' => $in,
            'check_out' => $out,
            'lunch_out' => null,
            'lunch_in' => null,
            'actual_break_minutes' => 0,
            'status' => 'present',
            'is_weekend_work' => $weekend,
            'raw_punches' => [
                ['time' => $in, 'date' => $date, 'type' => 'in'],
                ['time' => $out, 'date' => $nextDayOut ? date('Y-m-d', strtotime($date.' +1 day')) : $date, 'type' => 'out'],
            ],
        ]);
    }

    private function ot(Employee $e, string $date, float $hours, string $start, string $end): void
    {
        Authorization::factory()->create([
            'employee_id' => $e->id,
            'date' => $date,
            'type' => Authorization::TYPE_OVERTIME,
            'hours' => $hours,
            'start_time' => $start,
            'end_time' => $end,
            'status' => Authorization::STATUS_APPROVED,
        ]);
    }

    private function vel(Employee $e, string $date): void
    {
        Authorization::factory()->create([
            'employee_id' => $e->id,
            'date' => $date,
            'type' => Authorization::TYPE_NIGHT_SHIFT,
            'hours' => 1,
            'status' => Authorization::STATUS_APPROVED,
        ]);
    }

    public function test_pure_velada_counts_by_window_even_below_daily_hours(): void
    {
        // Caso Policarpo dom 16/08: entró 22:00 solo a velar, salió 05:02 del
        // día siguiente. Neto (7 h) < jornada (9) → antes velada_hours quedaba
        // en 0 y la noche aprobada no contaba.
        $e = $this->employee(unitDept: true);
        $r = $this->record($e, self::DATE, '22:00:11', '05:02:23', nextDayOut: true, weekend: true);
        $this->vel($e, self::DATE);

        $split = app(VeladaCalculatorService::class)->calculate($r, $e);

        $this->assertGreaterThan(6.5, $split['velada_hours'], 'la velada pura se mide por su ventana');
    }

    public function test_pre_velada_overtime_backed_on_unit_dept_sunday(): void
    {
        // Caso Policarpo dom 09/08 (Almacén PT): TE 16:01–22:00 aprobado (6 h,
        // respaldado por huellas) + velada aprobada. El split se llevaba todo a
        // velada y el TE pagaba 0; la ventana pre-velada no solapa la velada.
        $e = $this->employee(unitDept: true);
        $r = $this->record($e, self::DATE, '16:01:43', '05:02:15', nextDayOut: true, weekend: true);
        $this->ot($e, self::DATE, 6.0, '16:01', '22:00');
        $this->vel($e, self::DATE);

        $split = app(VeladaCalculatorService::class)->calculate($r, $e);

        $this->assertEqualsWithDelta(5.97, $split['overtime_authorized'], 0.05, 'la ventana pre-velada respaldada paga');
    }

    public function test_night_overtime_without_approved_velada_pays_full(): void
    {
        // Caso Pamela 11/08: salió 23:12 con TE 18:30–22:00 aprobado y SIN
        // velada aprobada — pagaba 3 de 3.5 (el split regalaba la punta a una
        // velada que nadie aprobó). Sin velada aprobada no hay doble pago.
        $e = $this->employee();
        $schedule = $e->schedule;
        $schedule->update(['entry_time' => '09:00', 'exit_time' => '18:30', 'daily_work_hours' => 8.5]);
        $r = $this->record($e, self::WEEKDAY, '09:08:57', '23:12:50');
        $this->ot($e, self::WEEKDAY, 3.5, '18:30', '22:00');

        $split = app(VeladaCalculatorService::class)->calculate($r->fresh(), $e->fresh());

        $this->assertEqualsWithDelta(3.5, $split['overtime_authorized'], 0.01);
    }

    public function test_overtime_overlapping_approved_velada_is_trimmed(): void
    {
        // TE aprobado hasta dentro de la ventana de velada (22:00+) CON velada
        // aprobada: la parte en ventana paga como velada, no dos veces.
        $e = $this->employee();
        $r = $this->record($e, self::WEEKDAY, '08:00:00', '23:30:00');
        $this->ot($e, self::WEEKDAY, 6.0, '17:30', '23:30');
        $this->vel($e, self::WEEKDAY);

        $split = app(VeladaCalculatorService::class)->calculate($r, $e);

        // Ventana 17:30–23:30 = 6 h, menos 22:00–23:30 (1.5 en velada) = 4.5.
        $this->assertLessThan(5.0, $split['overtime_authorized'], 'la parte en velada aprobada no paga doble como TE');
    }

    public function test_overlapping_captures_do_not_double_the_backing(): void
    {
        // Caso Martin 07/08: dos capturas encimadas (17:30–19:00 y 17:30–19:02).
        // La unión respalda ~1.5 h, no 3.
        $e = $this->employee();
        $r = $this->record($e, self::WEEKDAY, '07:58:15', '19:02:02');
        $this->ot($e, self::WEEKDAY, 1.5, '17:30', '19:02');
        $this->ot($e, self::WEEKDAY, 1.5, '17:30', '19:00');

        $split = app(VeladaCalculatorService::class)->calculate($r, $e);

        $this->assertLessThan(2.0, $split['overtime_authorized'], 'las ventanas encimadas no suman doble');
    }
}
