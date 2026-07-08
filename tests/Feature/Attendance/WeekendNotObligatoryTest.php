<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Schedule;
use App\Services\ZktecoSyncService;
use Tests\FeatureTestCase;

/**
 * Sábado y domingo dejaron de ser días obligatorios en TODOS los departamentos
 * (Dani 2026-07-08): trabajar un fin de semana y salir temprano (o no
 * presentarse) ya no genera falta ni salida temprana, aunque el horario incluya
 * ese día. Caso Karla Vianey (Calidad): sábado 09:00-18:30 en su horario, salió
 * antes y aparecía "Ausente".
 */
class WeekendNotObligatoryTest extends FeatureTestCase
{
    private const SATURDAY = '2026-06-20';
    private const WEDNESDAY = '2026-06-17';

    /** Empleado con horario L-S 08:00-17:00 (incluye sábado). */
    private function employeeWorkingSaturday(): Employee
    {
        $schedule = Schedule::factory()->create([
            'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'],
            'entry_time' => '08:00',
            'exit_time' => '17:00',
            'daily_work_hours' => 8,
            'break_minutes' => 60,
        ]);

        return Employee::factory()->create([
            'schedule_id' => $schedule->id,
            'status' => 'active',
        ]);
    }

    private function record(Employee $e, string $date): AttendanceRecord
    {
        // 08:00-15:00: salió 2 h (120 min) antes de su hora (17:00) → sobre el
        // umbral de 30 min. Entre semana sería falta.
        return AttendanceRecord::factory()->for($e)->create([
            'work_date' => $date,
            'check_in' => '08:00:00',
            'check_out' => '15:00:00',
            'status' => 'present',
        ]);
    }

    public function test_worked_saturday_leaving_early_is_not_a_falta(): void
    {
        $e = $this->employeeWorkingSaturday();
        $rec = $this->record($e, self::SATURDAY);

        app(ZktecoSyncService::class)->recalculateAttendanceRecord($rec);

        $this->assertNotSame('absent', $rec->fresh()->status, 'un sábado trabajado no es falta');
        // El fin de semana no genera salida temprana: se guarda en 0.
        $this->assertSame(0, (int) $rec->fresh()->early_departure_minutes);
    }

    public function test_same_early_exit_on_a_weekday_is_still_a_falta(): void
    {
        // Control: entre semana, la misma salida temprana excesiva SÍ es falta.
        $e = $this->employeeWorkingSaturday();
        $rec = $this->record($e, self::WEDNESDAY);

        app(ZktecoSyncService::class)->recalculateAttendanceRecord($rec);

        $this->assertSame('absent', $rec->fresh()->status, 'entre semana la salida temprana excesiva sí es falta');
        $this->assertGreaterThan(0, (int) $rec->fresh()->early_departure_minutes);
    }
}
