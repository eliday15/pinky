<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\PayrollPeriod;
use App\Models\Schedule;
use Tests\FeatureTestCase;

/**
 * holidays:reapply (auditoría #11 / DECISIONES, derivadas): la conversión
 * absent→holiday ya no es a ciegas — solo días donde el empleado tenía
 * jornada, sin checada alguna, y cuyo periodo de nómina no esté pagado.
 * El backfill del flag is_holiday sí aplica a toda fila en fecha festiva
 * (es metadato de la fecha, no altera nómina precalculada).
 */
class ReapplyHolidaysCommandTest extends FeatureTestCase
{
    private const HOLIDAY_MONDAY = '2026-03-09';

    private const HOLIDAY_SATURDAY = '2026-03-14';

    /**
     * Active employee on the default Mon-Fri schedule.
     */
    private function weekdayEmployee(): Employee
    {
        return Employee::factory()->create([
            'schedule_id' => Schedule::factory()->create()->id,
            'status' => 'active',
        ]);
    }

    private function absentRecord(Employee $employee, string $date, array $attrs = []): AttendanceRecord
    {
        return AttendanceRecord::factory()->for($employee)->create(array_merge([
            'work_date' => $date,
            'status' => 'absent',
            'check_in' => null,
            'check_out' => null,
            'is_holiday' => false,
            'worked_hours' => 0,
        ], $attrs));
    }

    public function test_converts_absent_without_punches_on_scheduled_workday(): void
    {
        Holiday::factory()->onDate(self::HOLIDAY_MONDAY)->create();
        $record = $this->absentRecord($this->weekdayEmployee(), self::HOLIDAY_MONDAY);

        $this->artisan('holidays:reapply')->assertExitCode(0);

        $record->refresh();
        $this->assertSame('holiday', $record->status, 'falta sin checadas en día con jornada se convierte a festivo');
        $this->assertTrue((bool) $record->is_holiday);
    }

    public function test_keeps_absent_with_punches(): void
    {
        // Una falta CON checadas es un evento real (salida temprana / retardo
        // extremo): trabajar el festivo no se borra convirtiéndola a 'holiday'.
        Holiday::factory()->onDate(self::HOLIDAY_MONDAY)->create();
        $record = $this->absentRecord($this->weekdayEmployee(), self::HOLIDAY_MONDAY, [
            'check_in' => '08:00:00',
            'check_out' => '12:00:00',
        ]);

        $this->artisan('holidays:reapply')->assertExitCode(0);

        $record->refresh();
        $this->assertSame('absent', $record->status, 'la falta con checadas no se convierte');
        $this->assertTrue((bool) $record->is_holiday, 'el flag de fecha festiva sí se backfillea');
    }

    public function test_keeps_absent_on_non_working_day(): void
    {
        // Sábado para un horario L-V: la fila 'absent' es ruido de datos,
        // no una falta que el festivo deba justificar.
        Holiday::factory()->onDate(self::HOLIDAY_SATURDAY)->create();
        $record = $this->absentRecord($this->weekdayEmployee(), self::HOLIDAY_SATURDAY);

        $this->artisan('holidays:reapply')->assertExitCode(0);

        $this->assertSame('absent', $record->fresh()->status, 'sin jornada ese día no hay conversión');
    }

    public function test_keeps_absent_inside_paid_period(): void
    {
        Holiday::factory()->onDate(self::HOLIDAY_MONDAY)->create();
        PayrollPeriod::factory()->create([
            'status' => 'paid',
            'start_date' => '2026-03-09',
            'end_date' => '2026-03-15',
        ]);
        $record = $this->absentRecord($this->weekdayEmployee(), self::HOLIDAY_MONDAY);

        $this->artisan('holidays:reapply')->assertExitCode(0);

        $this->assertSame('absent', $record->fresh()->status, 'un periodo pagado es inmutable: la falta no se convierte');
    }

    public function test_converts_when_overlapping_period_is_not_paid(): void
    {
        Holiday::factory()->onDate(self::HOLIDAY_MONDAY)->create();
        PayrollPeriod::factory()->create([
            'status' => 'approved',
            'start_date' => '2026-03-09',
            'end_date' => '2026-03-15',
        ]);
        $record = $this->absentRecord($this->weekdayEmployee(), self::HOLIDAY_MONDAY);

        $this->artisan('holidays:reapply')->assertExitCode(0);

        $this->assertSame('holiday', $record->fresh()->status, 'solo el status paid bloquea la conversión');
    }

    public function test_dry_run_writes_nothing(): void
    {
        Holiday::factory()->onDate(self::HOLIDAY_MONDAY)->create();
        $record = $this->absentRecord($this->weekdayEmployee(), self::HOLIDAY_MONDAY);

        $this->artisan('holidays:reapply', ['--dry-run' => true])->assertExitCode(0);

        $record->refresh();
        $this->assertSame('absent', $record->status);
        $this->assertFalse((bool) $record->is_holiday);
    }
}
