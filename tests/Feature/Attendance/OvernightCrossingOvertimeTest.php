<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Schedule;
use App\Services\OvertimeRoundingService;
use App\Services\ZktecoSyncService;
use Carbon\Carbon;
use Tests\FeatureTestCase;

/**
 * Salida que cruza la medianoche sobre un horario de DÍA (Elias 2026-08-03).
 *
 * En Almacén PT el mismo colaborador puede tener horas extra en la tarde/noche
 * Y una velada que arranca ~22:00 y termina de madrugada. Su salida queda a las
 * 05:12. Antes, el cálculo de salida temprana y el de tiempo extra leían esa
 * salida como del MISMO día (05:12 < 17:30 de su horario), así que el día salía
 * "falta" y el tiempo extra detectado era 0 → la aprobación se bloqueaba con
 * "las checadas no respaldan tiempo extra". Ahora ambos cálculos entienden que
 * la salida anterior a la entrada es del día siguiente.
 */
class OvernightCrossingOvertimeTest extends FeatureTestCase
{
    private const DATE = '2026-07-23';

    private function dayEmployee(): Employee
    {
        return Employee::factory()->create([
            'status' => 'active',
            'schedule_id' => Schedule::factory()->create([
                'entry_time' => '08:00',
                'exit_time' => '17:30',
                'daily_work_hours' => 8,
                'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            ])->id,
        ]);
    }

    private function schedule(Employee $e): object
    {
        return $e->getEffectiveScheduleForDay(Carbon::parse(self::DATE)->format('l'));
    }

    public function test_detect_overtime_counts_hours_when_checkout_crosses_midnight(): void
    {
        $e = $this->dayEmployee();
        $rec = AttendanceRecord::factory()->for($e)->create([
            'work_date' => self::DATE,
            'check_in' => '08:00:00',
            'check_out' => '05:12:00', // madrugada del día siguiente
        ]);

        $cap = app(OvertimeRoundingService::class)->detectOvertimeHours($rec, $this->schedule($e), self::DATE);

        $this->assertGreaterThan(4.0, $cap, 'la salida de madrugada respalda tiempo extra (cruza medianoche)');
    }

    public function test_recalc_marks_present_not_absent_for_overnight_day_shift(): void
    {
        $e = $this->dayEmployee();
        $punches = [
            ['time' => '08:00:00', 'date' => self::DATE, 'type' => 'in', 'device' => 7, 'method' => 'fingerprint'],
            ['time' => '17:56:00', 'date' => self::DATE, 'type' => 'punch', 'device' => 7, 'method' => 'fingerprint'],
            ['time' => '22:01:00', 'date' => self::DATE, 'type' => 'punch', 'device' => 7, 'method' => 'fingerprint'],
            ['time' => '05:12:00', 'date' => '2026-07-24', 'type' => 'out', 'device' => 7, 'method' => 'fingerprint'],
        ];
        $rec = AttendanceRecord::factory()->for($e)->create([
            'work_date' => self::DATE,
            'check_in' => '08:00:00',
            'check_out' => '05:12:00',
            'raw_punches' => $punches,
            'status' => 'absent',
        ]);

        app(ZktecoSyncService::class)->recalculateAttendanceRecord($rec);

        $this->assertNotSame('absent', $rec->fresh()->status, 'una salida de madrugada no es salida temprana');
    }

    public function test_a_genuine_early_departure_still_marks_absent(): void
    {
        // Control: salir a las 14:00 (antes de las 17:30, sin cruzar medianoche)
        // sigue siendo salida temprana → falta. No se rompió esa detección.
        $e = $this->dayEmployee();
        $rec = AttendanceRecord::factory()->for($e)->create([
            'work_date' => self::DATE,
            'check_in' => '08:00:00',
            'check_out' => '14:00:00',
            'raw_punches' => [
                ['time' => '08:00:00', 'date' => self::DATE, 'type' => 'in', 'device' => 7, 'method' => 'fingerprint'],
                ['time' => '14:00:00', 'date' => self::DATE, 'type' => 'out', 'device' => 7, 'method' => 'fingerprint'],
            ],
            'status' => 'present',
        ]);

        app(ZktecoSyncService::class)->recalculateAttendanceRecord($rec);

        $this->assertSame('absent', $rec->fresh()->status, 'salir temprano de verdad sigue siendo falta');
    }
}
