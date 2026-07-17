<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Schedule;
use App\Services\ZktecoSyncService;
use Tests\FeatureTestCase;

/**
 * Regla de madrugada (Dani 2026-07-17): una checada de madrugada NO cuenta como
 * entrada del turno si éste empieza 3 h o más después.
 *
 * Caso real Jose Armando (EMP-0052): turno 10:00, checa 02:12 (badge de
 * madrugada), se va y regresa 11:32. El sistema tomaba 02:12 como entrada →
 * "Presente" sin retardo. Con la regla, la entrada es la checada del turno y el
 * retardo/falta se calcula desde ahí.
 */
class MadrugadaEntryRuleTest extends FeatureTestCase
{
    private const WEDNESDAY = '2026-06-17';

    private function dayEmployee(string $entry = '10:00', string $exit = '19:30'): Employee
    {
        $schedule = Schedule::factory()->create([
            'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'entry_time' => $entry,
            'exit_time' => $exit,
            'daily_work_hours' => 9,
            'break_minutes' => 30,
            'late_tolerance_minutes' => 10,
        ]);

        return Employee::factory()->create([
            'schedule_id' => $schedule->id,
            'status' => 'active',
        ]);
    }

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

    /** @param array<int, array{0:string,1:string}> $times [time, date] pares */
    private function reprocess(Employee $e, array $punches): AttendanceRecord
    {
        $raw = array_map(fn ($p) => [
            'time' => $p[0],
            'date' => $p[1] ?? self::WEDNESDAY,
            'type' => 'punch',
            'device' => 7,
            'method' => 'fingerprint',
        ], $punches);

        $rec = AttendanceRecord::factory()->for($e)->create([
            'work_date' => self::WEDNESDAY,
            'check_in' => $raw[0]['time'],
            'check_out' => end($raw)['time'],
            'lunch_out' => null,
            'lunch_in' => null,
            'manually_edited_at' => null,
            'raw_punches' => $raw,
        ]);

        app(ZktecoSyncService::class)->reprocessAttendanceRecord($rec, $raw);

        return $rec->fresh();
    }

    public function test_madrugada_punch_is_not_the_shift_entry(): void
    {
        // Turno 10:00-19:30. Badge de madrugada 02:12 + entrada real 10:40 (40 min
        // tarde). La salida 19:35 cierra completo el turno (sin salida temprana).
        $e = $this->dayEmployee(entry: '10:00');
        $rec = $this->reprocess($e, [['02:12:00'], ['10:40:00'], ['19:35:00']]);

        $this->assertSame('10:40:00', $rec->check_in, 'la entrada es la checada del turno, no la de madrugada');
        $this->assertSame('late', $rec->status, '40 min tarde = retardo (antes salía Presente)');
    }

    public function test_madrugada_entry_over_an_hour_late_is_a_falta(): void
    {
        // Turno 10:00, regresa 11:32 = 92 min → falta (>= 60).
        $e = $this->dayEmployee(entry: '10:00');
        $rec = $this->reprocess($e, [['02:12:00'], ['11:32:00'], ['19:38:00']]);

        $this->assertSame('11:32:00', $rec->check_in);
        $this->assertSame('absent', $rec->status, '92 min tarde = falta');
    }

    public function test_early_arrival_within_window_still_counts_as_entry(): void
    {
        // Turno 08:00-17:30, checa 07:03 (57 min antes, dentro de la ventana de
        // 3 h) y sale 17:35. Pedro (EMP-0473) NO debe verse afectado.
        $e = $this->dayEmployee(entry: '08:00', exit: '17:30');
        $rec = $this->reprocess($e, [['07:03:00'], ['17:35:00']]);

        $this->assertSame('07:03:00', $rec->check_in, 'llegar temprano dentro de 3 h sigue siendo la entrada');
        $this->assertSame('present', $rec->status);
    }

    public function test_night_shift_madrugada_is_still_the_entry(): void
    {
        // Turno nocturno 22:00-06:00: su entrada ES de madrugada, no se toca.
        $e = $this->nightEmployee();
        $rec = $this->reprocess($e, [['02:00:00'], ['05:30:00']]);

        $this->assertSame('02:00:00', $rec->check_in, 'los turnos nocturnos conservan su entrada de madrugada');
    }

    public function test_only_madrugada_punches_keeps_first_as_fallback(): void
    {
        // Turno 10:00 pero SOLO hay checadas de madrugada (todas antes del corte
        // 07:00): no hay entrada de turno válida → se conserva la primera.
        $e = $this->dayEmployee(entry: '10:00');
        $rec = $this->reprocess($e, [['02:12:00'], ['05:00:00']]);

        $this->assertSame('02:12:00', $rec->check_in, 'sin checada de turno, se conserva la primera (fallback)');
    }
}
