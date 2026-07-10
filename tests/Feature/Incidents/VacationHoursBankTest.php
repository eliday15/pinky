<?php

namespace Tests\Feature\Incidents;

use App\Models\Employee;
use App\Models\IncidentType;
use App\Models\Schedule;
use Tests\FeatureTestCase;

/**
 * Bolsa explícita de "horas a cuenta de vacaciones" (Dani 2026-07-09).
 *
 * RRHH convierte N días → bolsa de N×8 h (opt-in). El colaborador gasta horas
 * parcialmente en distintas fechas hasta agotarlas; el descuento del saldo de
 * vacaciones es proporcional a lo gastado (8 h = 1 día).
 */
class VacationHoursBankTest extends FeatureTestCase
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

    private function employee(int $entitled = 10, int $usedDays = 0, float $usedHours = 0, float $credited = 0): Employee
    {
        return Employee::factory()->create([
            'status' => 'active',
            'vacation_days_entitled' => $entitled,
            'vacation_days_used' => $usedDays,
            'vacation_hours_used' => $usedHours,
            'vacation_hours_credited' => $credited,
            'schedule_id' => Schedule::factory()->create(['entry_time' => '07:00', 'exit_time' => '15:00'])->id,
        ]);
    }

    public function test_converting_days_credits_hours_to_the_bank(): void
    {
        $this->actingAsAdmin();
        $emp = $this->employee(entitled: 6);

        $this->post(route('vacation-hours.convert'), [
            'employee_id' => $emp->id,
            'days' => 2,
        ])->assertRedirect();

        $emp->refresh();
        $this->assertEqualsWithDelta(16.0, (float) $emp->vacation_hours_credited, 0.01);
        $this->assertEqualsWithDelta(16.0, $emp->vacation_hours_bank_remaining, 0.01);
        $this->assertTrue($emp->usesVacationHoursBank());
    }

    public function test_cannot_convert_more_days_than_available(): void
    {
        $this->actingAsAdmin();
        $emp = $this->employee(entitled: 3, usedDays: 2); // 1 día disponible

        $this->post(route('vacation-hours.convert'), [
            'employee_id' => $emp->id,
            'days' => 2,
        ])->assertSessionHasErrors('days');

        $this->assertEqualsWithDelta(0.0, (float) $emp->fresh()->vacation_hours_credited, 0.01);
    }

    public function test_permit_without_bank_is_rejected(): void
    {
        // No inscrito (credited = 0) → no puede gastar horas aunque tenga días.
        $this->actingAsAdmin();
        $type = $this->hoursType();
        $emp = $this->employee(entitled: 10, credited: 0);

        $this->post(route('incidents.store'), [
            'employee_id' => $emp->id,
            'incident_type_id' => $type->id,
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-15',
            'hours' => 2,
        ])->assertSessionHasErrors('saldo');

        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_permit_beyond_bank_is_rejected(): void
    {
        $this->actingAsAdmin();
        $type = $this->hoursType();
        $emp = $this->employee(entitled: 10, credited: 8); // bolsa de 8 h

        $this->post(route('incidents.store'), [
            'employee_id' => $emp->id,
            'incident_type_id' => $type->id,
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-15',
            'hours' => 10,
        ])->assertSessionHasErrors('saldo');

        $this->assertEqualsWithDelta(0.0, (float) $emp->fresh()->vacation_hours_used, 0.01);
    }

    public function test_spending_hours_reduces_whole_day_availability_proportionally(): void
    {
        $this->actingAsAdmin();
        $type = $this->hoursType();
        $emp = $this->employee(entitled: 6, credited: 16); // bolsa de 16 h

        // Gasta 8 h = 1 día completo.
        $this->post(route('incidents.store'), [
            'employee_id' => $emp->id,
            'incident_type_id' => $type->id,
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-15',
            'hours' => 8,
        ])->assertRedirect(route('incidents.index'));

        $emp->refresh();
        $this->assertEqualsWithDelta(8.0, (float) $emp->vacation_hours_used, 0.01);
        $this->assertEqualsWithDelta(8.0, $emp->vacation_hours_bank_remaining, 0.01);
        // 6 derecho − 8h/8 = 5 días disponibles para vacación completa.
        $this->assertEqualsWithDelta(5.0, $emp->vacation_days_available_for_request, 0.01);
    }

    public function test_revert_returns_unspent_hours(): void
    {
        $this->actingAsAdmin();
        $emp = $this->employee(entitled: 6, usedHours: 2, credited: 16);

        // Puede revertir hasta 14 h no gastadas (16 − 2).
        $this->post(route('vacation-hours.revert'), [
            'employee_id' => $emp->id,
            'hours' => 10,
        ])->assertRedirect();

        $this->assertEqualsWithDelta(6.0, (float) $emp->fresh()->vacation_hours_credited, 0.01);
    }

    public function test_cannot_revert_spent_hours(): void
    {
        $this->actingAsAdmin();
        $emp = $this->employee(entitled: 6, usedHours: 12, credited: 16);

        // Solo 4 h sin gastar; revertir 10 debe fallar.
        $this->post(route('vacation-hours.revert'), [
            'employee_id' => $emp->id,
            'hours' => 10,
        ])->assertSessionHasErrors('hours');

        $this->assertEqualsWithDelta(16.0, (float) $emp->fresh()->vacation_hours_credited, 0.01);
    }
}
