<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\Employee;
use App\Models\Schedule;
use App\Services\ZktecoSyncService;
use Tests\FeatureTestCase;

/**
 * Madrugada RESPALDADA por TE matutino = entrada (Luis 2026-08-27, Almacén
 * PT: "su horario es de 8 a 5:30 y ella está entrando a las 5:03"). La regla
 * de madrugada (Dani 2026-07-17) descarta checadas ≥ 3 h antes del turno
 * para que un badge sin respaldo no oculte el retardo — pero en Almacén PT
 * la gente entra a las 5 am a hacer TE de verdad, y con la huella descartada
 * la aprobación se bloqueaba ("no me deja"), el TE no se pagaba y el día
 * aparecía con retardo. Con una captura de TE cuya ventana arranca antes del
 * turno, la huella que cae en esa ventana SÍ es la entrada. Sin captura, la
 * regla de madrugada sigue intacta.
 */
class BackedMadrugadaEntryTest extends FeatureTestCase
{
    private const DATE = '2026-06-17'; // miércoles

    private function almacenEmployee(): Employee
    {
        $schedule = Schedule::factory()->create([
            'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'entry_time' => '08:00',
            'exit_time' => '17:30',
            'daily_work_hours' => 9,
            'break_minutes' => 60,
            'late_tolerance_minutes' => 10,
        ]);

        return Employee::factory()->create(['schedule_id' => $schedule->id, 'status' => 'active']);
    }

    /** @param list<string> $times */
    private function reprocess(Employee $e, array $times): AttendanceRecord
    {
        $raw = array_map(fn (string $t) => [
            'time' => $t,
            'date' => self::DATE,
            'type' => 'punch',
            'device' => 7,
            'method' => 'fingerprint',
        ], $times);

        $rec = AttendanceRecord::factory()->for($e)->create([
            'work_date' => self::DATE,
            'check_in' => $raw[0]['time'],
            'check_out' => end($raw)['time'],
            'lunch_out' => null,
            'lunch_in' => null,
            'actual_break_minutes' => 0,
            'manually_edited_at' => null,
            'raw_punches' => $raw,
        ]);

        app(ZktecoSyncService::class)->reprocessAttendanceRecord($rec, $raw);

        return $rec->fresh();
    }

    public function test_madrugada_punch_inside_captured_overtime_window_is_the_entry(): void
    {
        // Caso Elsa 14/08: huellas 04:48 y 08:37, TE 04:48–09:00 capturado.
        // 04:48 está a más de 3 h del turno (08:00): la regla de madrugada la
        // descartaba y la aprobación se bloqueaba.
        $e = $this->almacenEmployee();
        Authorization::factory()->create([
            'employee_id' => $e->id,
            'date' => self::DATE,
            'type' => Authorization::TYPE_OVERTIME,
            'hours' => 4.0,
            'start_time' => '04:48',
            'end_time' => '09:00',
            'status' => Authorization::STATUS_PENDING,
        ]);

        $rec = $this->reprocess($e, ['04:48:10', '08:37:56', '18:37:01']);

        $this->assertSame('04:48:10', $rec->check_in, 'la huella de madrugada respaldada por el TE es la entrada');
        $this->assertSame('present', $rec->status, 'sin retardo: entró a las 4:48, no a las 8:37');
        $this->assertSame(0, (int) $rec->late_minutes);
    }

    public function test_madrugada_punch_without_capture_keeps_the_rule(): void
    {
        // Mismas huellas SIN captura de TE: badge de madrugada → la entrada es
        // 08:37 y el retardo se conserva (regla de Dani intacta).
        $e = $this->almacenEmployee();

        $rec = $this->reprocess($e, ['04:48:10', '08:37:56', '18:37:01']);

        $this->assertSame('08:37:56', $rec->check_in);
        $this->assertGreaterThan(0, (int) $rec->late_minutes);
    }

    public function test_madrugada_punch_far_from_captured_window_is_still_a_badge(): void
    {
        // Captura 05:00–09:00 pero la huella es de la 01:30: no cae en la
        // ventana (±30 min) → sigue siendo badge; la entrada es la de las 5.
        $e = $this->almacenEmployee();
        Authorization::factory()->create([
            'employee_id' => $e->id,
            'date' => self::DATE,
            'type' => Authorization::TYPE_OVERTIME,
            'hours' => 4.0,
            'start_time' => '05:00',
            'end_time' => '09:00',
            'status' => Authorization::STATUS_APPROVED,
        ]);

        $rec = $this->reprocess($e, ['01:30:28', '05:03:18', '08:37:56', '18:37:01']);

        $this->assertSame('05:03:18', $rec->check_in, 'la de la 1:30 sigue siendo badge; la de las 5 es la entrada');
    }

    public function test_approval_cap_backs_the_full_morning_window(): void
    {
        // Con la entrada en 05:03, el tope de aprobación (detección por
        // horario) respalda las ~3 h previas al turno — ya no bloquea.
        $e = $this->almacenEmployee();
        $auth = Authorization::factory()->create([
            'employee_id' => $e->id,
            'date' => self::DATE,
            'type' => Authorization::TYPE_OVERTIME,
            'hours' => 3.0,
            'start_time' => '05:00',
            'end_time' => '08:00',
            'status' => Authorization::STATUS_PENDING,
        ]);
        $rec = $this->reprocess($e, ['05:03:18', '08:37:56', '18:37:01']);

        $detected = app(\App\Services\OvertimeRoundingService::class)
            ->detectOvertimeHours($rec, $e->getEffectiveScheduleForDay('wednesday'), self::DATE);

        $this->assertGreaterThanOrEqual(3.0, $detected, 'la mañana respaldada cubre las 3 h capturadas');
    }
}
