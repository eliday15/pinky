<?php

namespace Tests\Feature\Incidents;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\Schedule;
use Tests\FeatureTestCase;

/**
 * "Horas a cuenta de vacaciones" (Dani 2026-07-01): un permiso de entrada tarde
 * / salida temprano descuenta HORAS del saldo de vacaciones (1 día = 8 h) y
 * evita la falta por umbral mientras queden horas.
 */
class VacationHoursIncidentTest extends FeatureTestCase
{
    private function hoursType(): IncidentType
    {
        return IncidentType::factory()->create([
            'name' => 'Horas a cuenta de vacaciones',
            'requires_approval' => false,
            'deducts_vacation' => false,
            'uses_vacation_hours' => true,
            'has_time_range' => true,
            'affects_attendance' => true,
        ]);
    }

    private function employee(int $entitled = 10, int $usedDays = 0, float $usedHours = 0, ?int $scheduleId = null): Employee
    {
        return Employee::factory()->create([
            'status' => 'active',
            'vacation_days_entitled' => $entitled,
            'vacation_days_used' => $usedDays,
            'vacation_hours_used' => $usedHours,
            'schedule_id' => $scheduleId ?? Schedule::factory()->create(['entry_time' => '07:00', 'exit_time' => '15:00'])->id,
        ]);
    }

    public function test_auto_approved_permit_deducts_vacation_hours(): void
    {
        $this->actingAsAdmin();
        $type = $this->hoursType();
        $emp = $this->employee(entitled: 10);

        $this->post(route('incidents.store'), [
            'employee_id' => $emp->id,
            'incident_type_id' => $type->id,
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-15',
            'hours' => 2,
        ])->assertRedirect(route('incidents.index'));

        $emp->refresh();
        $this->assertEqualsWithDelta(2.0, (float) $emp->vacation_hours_used, 0.01);
        // 10 días × 8 − 2 = 78 h disponibles.
        $this->assertEqualsWithDelta(78.0, $emp->vacation_hours_remaining, 0.01);
    }

    public function test_permit_beyond_available_hours_is_rejected(): void
    {
        $this->actingAsAdmin();
        $type = $this->hoursType();
        // 0 días restantes → 0 horas disponibles.
        $emp = $this->employee(entitled: 1, usedDays: 1);

        $this->post(route('incidents.store'), [
            'employee_id' => $emp->id,
            'incident_type_id' => $type->id,
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-15',
            'hours' => 2,
        ])->assertSessionHasErrors('saldo');

        $emp->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $emp->vacation_hours_used, 0.01);
        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_permit_suppresses_the_threshold_falta(): void
    {
        $this->actingAsAdmin();
        $type = $this->hoursType();
        $schedule = Schedule::factory()->create(['entry_time' => '07:00', 'exit_time' => '15:00']);
        $emp = $this->employee(entitled: 10, scheduleId: $schedule->id);

        // Llegó 2h tarde (09:00 vs 07:00) → sin permiso sería falta por umbral.
        $record = AttendanceRecord::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => '2026-06-15',
            'check_in' => '09:00:00',
            'check_out' => '15:00:00',
            'status' => 'absent',
        ]);

        // Al crear (y auto-aprobar) el permiso de horas a cuenta de vacaciones,
        // se recalcula la asistencia y ya no debe quedar como falta.
        $this->post(route('incidents.store'), [
            'employee_id' => $emp->id,
            'incident_type_id' => $type->id,
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-15',
            'hours' => 2,
        ])->assertRedirect(route('incidents.index'));

        $this->assertNotSame('absent', $record->fresh()->status);
    }
}
