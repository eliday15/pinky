<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Schedule;
use App\Services\ZktecoSyncService;
use Tests\FeatureTestCase;

/**
 * Cada checada guarda su FECHA propia (Dani 2026-07-15).
 *
 * Una velada arrastra la madrugada del día siguiente al registro del día que la
 * originó. Antes sólo se guardaba 'HH:MM:SS', así que al re-sincronizar la
 * madrugada se reinterpretaba como del mismo día: la salida de las 02:00 pasaba
 * a ordenarse ANTES de la entrada de las 22:00 y el registro terminaba con
 * check_in = check_out. Es lo que le pasó a Pedro Vazquez (EMP-0473) el 08/07:
 * quedó "01:21 – 01:21, Ausente, 0.00 h".
 */
class PunchOwnDateTest extends FeatureTestCase
{
    private const WORK_DATE = '2026-06-17';

    private function nightEmployee(): Employee
    {
        $schedule = Schedule::factory()->create([
            'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'entry_time' => '22:00',
            'exit_time' => '06:00',
            'daily_work_hours' => 8,
            'break_minutes' => 0,
        ]);

        return Employee::factory()->create([
            'schedule_id' => $schedule->id,
            'status' => 'active',
        ]);
    }

    /** Velada: entra 22:00 del día y sale 02:00 de la madrugada siguiente. */
    private function veladaPunches(): array
    {
        return [
            ['time' => '22:00:00', 'date' => self::WORK_DATE, 'type' => 'in', 'device' => 7, 'method' => 'fingerprint'],
            ['time' => '02:00:00', 'date' => '2026-06-18', 'type' => 'out', 'device' => 7, 'method' => 'fingerprint'],
        ];
    }

    private function veladaRecord(Employee $e, array $punches): AttendanceRecord
    {
        return AttendanceRecord::factory()->for($e)->create([
            'work_date' => self::WORK_DATE,
            'check_in' => '22:00:00',
            'check_out' => '02:00:00',
            'lunch_out' => null,
            'lunch_in' => null,
            'is_night_shift' => true,
            'manually_edited_at' => null,
            'raw_punches' => $punches,
        ]);
    }

    public function test_reprocessing_a_velada_keeps_entry_and_exit_in_order(): void
    {
        $e = $this->nightEmployee();
        $rec = $this->veladaRecord($e, $this->veladaPunches());

        app(ZktecoSyncService::class)->reprocessAttendanceRecord($rec, $this->veladaPunches());

        $fresh = $rec->fresh();
        $this->assertSame('22:00:00', $fresh->check_in, 'la entrada sigue siendo la de las 22:00');
        $this->assertSame('02:00:00', $fresh->check_out, 'la salida sigue siendo la madrugada siguiente');
        $this->assertNotSame(
            $fresh->check_in,
            $fresh->check_out,
            'entrada y salida nunca pueden colapsar en la misma checada',
        );
    }

    public function test_reprocessing_twice_does_not_create_a_phantom_punch(): void
    {
        $e = $this->nightEmployee();
        $rec = $this->veladaRecord($e, $this->veladaPunches());

        $sync = app(ZktecoSyncService::class);
        $sync->reprocessAttendanceRecord($rec, $this->veladaPunches());
        $sync->reprocessAttendanceRecord($rec->fresh(), $rec->fresh()->raw_punches);

        $fresh = $rec->fresh();
        $this->assertCount(2, $fresh->raw_punches, 're-sincronizar no debe duplicar checadas');
        $this->assertSame('22:00:00', $fresh->check_in);
        $this->assertSame('02:00:00', $fresh->check_out);
    }

    public function test_each_stored_punch_keeps_its_own_date(): void
    {
        $e = $this->nightEmployee();
        $rec = $this->veladaRecord($e, $this->veladaPunches());

        app(ZktecoSyncService::class)->reprocessAttendanceRecord($rec, $this->veladaPunches());

        $dates = collect($rec->fresh()->raw_punches)->pluck('date', 'time')->all();
        $this->assertSame(self::WORK_DATE, $dates['22:00:00'] ?? null);
        $this->assertSame('2026-06-18', $dates['02:00:00'] ?? null, 'la madrugada conserva su día real');
    }

    public function test_entry_and_exit_at_the_same_clock_time_are_not_both_in(): void
    {
        $e = $this->nightEmployee();
        // Mismo reloj, días distintos: sólo una puede ser la entrada.
        $punches = [
            ['time' => '01:21:13', 'date' => self::WORK_DATE, 'type' => 'in', 'device' => 7, 'method' => 'fingerprint'],
            ['time' => '01:21:13', 'date' => '2026-06-18', 'type' => 'punch', 'device' => 7, 'method' => 'fingerprint'],
        ];
        $rec = $this->veladaRecord($e, $punches);

        app(ZktecoSyncService::class)->reprocessAttendanceRecord($rec, $punches);

        $types = collect($rec->fresh()->raw_punches)->pluck('type')->all();
        $this->assertSame(1, collect($types)->filter(fn ($t) => $t === 'in')->count(), 'sólo una checada es la entrada');
    }
}
