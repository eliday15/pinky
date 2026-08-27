<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\Employee;
use App\Models\Schedule;
use App\Services\ZktecoSyncService;
use Tests\FeatureTestCase;

/**
 * Cruce de medianoche por FECHA REAL de la huella de salida (Luis 2026-08-27,
 * caso Miguel Peralta vie 14/08): entró 05:00 por su TE matutino, trabajó la
 * tarde autorizada (huellas 16:30 y 22:00) y su velada terminó 05:08 del día
 * SIGUIENTE. Como 05:08 > 05:00, la comparación de horas no detectaba el
 * cruce: el día quedaba de 7 minutos, marcado ausente, y solo la ventana
 * matutina del TE se pagaba (3 de 6 h). La fecha real que guardan las
 * huellas crudas es la autoridad.
 */
class MidnightOutPunchTest extends FeatureTestCase
{
    private const DATE = '2026-06-03'; // miércoles

    private function employee(): Employee
    {
        $schedule = Schedule::factory()->create([
            'entry_time' => '08:00',
            'exit_time' => '17:30',
            'daily_work_hours' => 9,
            'break_minutes' => 60,
            'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
        ]);

        return Employee::factory()->create(['status' => 'active', 'schedule_id' => $schedule->id]);
    }

    private function miguelRecord(Employee $employee): AttendanceRecord
    {
        return AttendanceRecord::factory()->for($employee)->create([
            'work_date' => self::DATE,
            'check_in' => '05:00:52',
            'check_out' => '05:08:14', // hora del día SIGUIENTE — solo raw_punches lo sabe
            'lunch_out' => null,
            'lunch_in' => null,
            'actual_break_minutes' => 0,
            'status' => 'absent',
            'raw_punches' => [
                ['time' => '05:00:52', 'date' => self::DATE, 'type' => 'in'],
                ['time' => '16:30:47', 'date' => self::DATE, 'type' => 'punch'],
                ['time' => '22:00:16', 'date' => self::DATE, 'type' => 'punch'],
                ['time' => '05:08:14', 'date' => '2026-06-04', 'type' => 'out'],
            ],
        ]);
    }

    public function test_next_day_out_punch_makes_the_day_present_with_full_backed_overtime(): void
    {
        $employee = $this->employee();
        $record = $this->miguelRecord($employee);
        foreach ([['05:00', '08:00'], ['16:30', '22:00']] as [$start, $end]) {
            Authorization::factory()->create([
                'employee_id' => $employee->id,
                'date' => self::DATE,
                'type' => Authorization::TYPE_OVERTIME,
                'hours' => 3.0,
                'start_time' => $start,
                'end_time' => $end,
                'status' => Authorization::STATUS_APPROVED,
            ]);
        }

        app(ZktecoSyncService::class)->recalculateAttendanceRecord($record);
        $record->refresh();

        $this->assertSame('present', $record->status, 'el día trabajado hasta la madrugada no es falta');
        $this->assertSame(0, (int) $record->early_departure_minutes, 'salir de madrugada no es salida temprana');
        $this->assertEqualsWithDelta(6.0, (float) $record->overtime_authorized_hours, 0.01, 'las dos ventanas de TE respaldadas pagan completas');
    }

    public function test_same_day_out_punch_keeps_short_day_semantics(): void
    {
        // Sin huella de salida del día siguiente, un día corto sigue siendo
        // corto (la salida temprana escala a falta como siempre).
        $employee = $this->employee();
        $record = AttendanceRecord::factory()->for($employee)->create([
            'work_date' => self::DATE,
            'check_in' => '05:00:52',
            'check_out' => '05:08:14',
            'lunch_out' => null,
            'lunch_in' => null,
            'actual_break_minutes' => 0,
            'status' => 'present',
            'raw_punches' => [
                ['time' => '05:00:52', 'date' => self::DATE, 'type' => 'in'],
                ['time' => '05:08:14', 'date' => self::DATE, 'type' => 'out'],
            ],
        ]);

        app(ZktecoSyncService::class)->recalculateAttendanceRecord($record);
        $record->refresh();

        $this->assertSame('absent', $record->status, 'un día de 7 minutos reales sigue siendo falta');
    }
}
