<?php

namespace Tests\Feature\Payroll;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\Employee;
use App\Models\Schedule;
use App\Services\ZktecoSyncService;
use Tests\FeatureTestCase;

/**
 * El retardo no se come el TE de la tarde (Luis 2026-08-26, caso Diana
 * Olague): entró tarde (el retardo ya se castiga como retardo) y salió
 * después de su hora, con el TE 17:30–18:00 aprobado y respaldado por la
 * checada — pero el total del día no superaba la jornada y la nómina pagaba
 * 0. El tope del pago ahora también mide por HORARIO (entrada temprana +
 * salida tardía con escalera, la misma vara del reporte y la aprobación) y
 * toma la mayor de las dos medidas, siempre topada a lo autorizado.
 */
class OvertimeLateArrivalBackedWindowTest extends FeatureTestCase
{
    private const DATE = '2026-06-03'; // miércoles

    private function employeeWithDianaSchedule(): Employee
    {
        $schedule = Schedule::factory()->create([
            'entry_time' => '08:00',
            'exit_time' => '17:30',
            'daily_work_hours' => 9,
            'break_minutes' => 30,
            'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
        ]);

        return Employee::factory()->create(['status' => 'active', 'schedule_id' => $schedule->id]);
    }

    private function lateDayRecord(Employee $employee, string $checkIn, string $checkOut): AttendanceRecord
    {
        return AttendanceRecord::factory()->for($employee)->create([
            'work_date' => self::DATE,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'lunch_out' => null,
            'lunch_in' => null,
            'actual_break_minutes' => 0,
            'status' => 'late',
        ]);
    }

    private function approvedOvertime(Employee $employee, float $hours, string $start, string $end): Authorization
    {
        return Authorization::factory()->create([
            'employee_id' => $employee->id,
            'date' => self::DATE,
            'type' => Authorization::TYPE_OVERTIME,
            'hours' => $hours,
            'start_time' => $start,
            'end_time' => $end,
            'status' => Authorization::STATUS_APPROVED,
        ]);
    }

    public function test_backed_window_pays_even_when_daily_total_below_schedule(): void
    {
        // Caso Diana 4/8: entró 08:36 (26 min tarde), salió 18:01. Total
        // 8.92 h < jornada 9 → el tope por total daba 0; la salida respalda
        // la ventana 17:30–18:00 → la escalera detecta 31 min = 0.5 h.
        $employee = $this->employeeWithDianaSchedule();
        $record = $this->lateDayRecord($employee, '08:36:55', '18:01:51');
        $this->approvedOvertime($employee, 0.5, '17:30', '18:00');

        app(ZktecoSyncService::class)->recalculateAttendanceRecord($record);
        $record->refresh();

        $this->assertSame('late', $record->status, 'el retardo se sigue castigando como retardo');
        $this->assertEqualsWithDelta(0.5, (float) $record->overtime_authorized_hours, 0.01, 'el TE respaldado por la ventana se paga aunque el total no supere la jornada');
    }

    public function test_cap_still_limits_inflated_authorization_to_backed_window(): void
    {
        // Autorización inflada (2 h) con salida 18:01: lo respaldado por
        // horario son 31 min → 0.5. El tope sigue vivo — nunca se paga más
        // de lo que la checada respalda.
        $employee = $this->employeeWithDianaSchedule();
        $record = $this->lateDayRecord($employee, '08:36:00', '18:01:00');
        $this->approvedOvertime($employee, 2.0, '17:30', '19:30');

        app(ZktecoSyncService::class)->recalculateAttendanceRecord($record);
        $record->refresh();

        $this->assertEqualsWithDelta(0.5, (float) $record->overtime_authorized_hours, 0.01, 'el tope al respaldo por horario sigue aplicando');
    }

    public function test_no_authorization_still_pays_zero(): void
    {
        // Sin TE autorizado, el día con retardo y salida tardía no paga nada
        // por sí solo (el pago del TE siempre nace de una autorización).
        $employee = $this->employeeWithDianaSchedule();
        $record = $this->lateDayRecord($employee, '08:36:00', '18:01:00');

        app(ZktecoSyncService::class)->recalculateAttendanceRecord($record);
        $record->refresh();

        $this->assertEqualsWithDelta(0.0, (float) $record->overtime_authorized_hours, 0.01);
    }
}
