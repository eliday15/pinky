<?php

namespace Tests\Feature\Authorizations;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\Employee;
use App\Models\Schedule;
use Tests\FeatureTestCase;

/**
 * Respaldo por huella de madrugada (Elias 2026-08-12: "muchas veces hacen
 * horas extra antes de su horario que NO son veladas").
 *
 * Réplica del caso Miriam #4525: turno de 09:00, huella real de 05:01 que la
 * regla de las 3 h descartó del pareo (check_in quedó 08:44) → el TE
 * matutino capturado 05:00–09:00 no tenía respaldo y exigía consola. Ahora
 * una captura de ANTES del turno anclada a una marca cruda
 * (P ∈ [inicio−15 min, inicio+30 min]) se aprueba sola, marcada
 * is_unbacked_extra (paga completa sobre el tope del timecard, señalada en
 * reporte). Una salida de velada (≈01:00) nunca ancla una captura matutina.
 */
class RawPunchMorningBackingTest extends FeatureTestCase
{
    /** Turno 09:00–17:30; día pareado 08:44–17:48 con huellas crudas extra. */
    private function employeeWithMorningPunch(array $rawTimes): Employee
    {
        $employee = Employee::factory()->create([
            'schedule_id' => Schedule::factory()->create([
                'entry_time' => '09:00',
                'exit_time' => '17:30',
            ])->id,
        ]);

        AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-06-05', // viernes
            'check_in' => '08:44:16',
            'check_out' => '17:48:04',
            'overtime_hours' => 0,
            'raw_punches' => array_map(
                fn (string $t) => ['date' => '2026-06-05', 'time' => $t, 'type' => 'punch'],
                $rawTimes,
            ),
        ]);

        return $employee;
    }

    private function captureMorning(Employee $emp, float $hours = 4.0): void
    {
        $this->from(route('authorizations.create'))->post(route('authorizations.store'), [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-05',
            'start_time' => '05:00',
            'end_time' => '09:00',
            'hours' => $hours,
            'reason' => 'auditoría de madrugada',
        ])->assertRedirect(route('authorizations.index'));
    }

    public function test_morning_capture_anchored_to_discarded_punch_auto_approves_full(): void
    {
        $this->actingAsSupervisor();
        // 01:02 = salida de velada del día anterior (ruido); 05:01 = entrada real.
        $emp = $this->employeeWithMorningPunch(['01:02:00', '05:01:40']);

        $this->captureMorning($emp);

        $auth = Authorization::where('employee_id', $emp->id)->first();
        $this->assertSame(Authorization::STATUS_APPROVED, $auth->status, 'la huella de 05:01 la respalda');
        $this->assertTrue((bool) $auth->is_unbacked_extra, 'marcada para pagar sobre el tope del timecard');
        $this->assertEqualsWithDelta(4.0, (float) $auth->hours, 0.01, '05:01→09:00 = 3h59 → escalera 4.0');
    }

    public function test_velada_exit_punch_does_not_anchor_morning_capture(): void
    {
        $this->actingAsSupervisor();
        // Solo la salida de velada de la 01:02: nada pegado a las 05:00.
        $emp = $this->employeeWithMorningPunch(['01:02:00']);

        $this->captureMorning($emp);

        $auth = Authorization::where('employee_id', $emp->id)->first();
        $this->assertSame(Authorization::STATUS_PENDING, $auth->status, 'sin ancla se queda a revisión humana');
        $this->assertFalse((bool) $auth->is_unbacked_extra);
    }

    public function test_late_anchor_trims_hours_to_punch(): void
    {
        $this->actingAsSupervisor();
        // Llegó 05:29 pero la capturaron desde las 05:00: ancla (≤ +30 min)
        // y paga DESDE la huella, no desde la captura.
        $emp = $this->employeeWithMorningPunch(['05:29:00']);

        $this->captureMorning($emp);

        $auth = Authorization::where('employee_id', $emp->id)->first();
        $this->assertSame(Authorization::STATUS_APPROVED, $auth->status);
        $this->assertEqualsWithDelta(3.5, (float) $auth->hours, 0.01, '05:29→09:00 = 3h31 → escalera 3.5');
    }

    public function test_punch_more_than_half_hour_after_captured_start_does_not_anchor(): void
    {
        $this->actingAsSupervisor();
        // Media hora de diferencia con lo capturado ya no ancla: a revisión.
        $emp = $this->employeeWithMorningPunch(['05:31:00']);

        $this->captureMorning($emp);

        $auth = Authorization::where('employee_id', $emp->id)->first();
        $this->assertSame(Authorization::STATUS_PENDING, $auth->status);
    }

    public function test_evening_capture_is_untouched_by_the_morning_path(): void
    {
        $this->actingAsSupervisor();
        $emp = $this->employeeWithMorningPunch(['05:01:40']);

        // Vespertina sin respaldo (salida real 17:48 → 18 min → escalera 0):
        // el camino matutino no debe tocarla; sigue pendiente.
        $this->from(route('authorizations.create'))->post(route('authorizations.store'), [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-05',
            'start_time' => '17:30',
            'end_time' => '19:30',
            'hours' => 2.0,
            'reason' => 'vespertina sin respaldo',
        ])->assertRedirect(route('authorizations.index'));

        $auth = Authorization::where('employee_id', $emp->id)
            ->whereTime('start_time', '17:30:00')->first();
        $this->assertNotNull($auth);
        $this->assertSame(Authorization::STATUS_PENDING, $auth->status);
    }

    public function test_miguel_real_0500_entry_wins_over_1758_late_exit_without_split(): void
    {
        $this->actingAsSupervisor();
        $employee = Employee::factory()->create([
            'full_name' => 'Miguel Ángel Peralta Sánchez',
            'schedule_id' => Schedule::factory()->create([
                'entry_time' => '08:00',
                'exit_time' => '17:30',
            ])->id,
        ]);
        $record = AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-09-29',
            'check_in' => '07:58:00',
            'check_out' => '17:58:00',
            'raw_punches' => [
                ['date' => '2026-09-29', 'time' => '05:00:00', 'type' => 'punch'],
                ['date' => '2026-09-29', 'time' => '07:58:00', 'type' => 'in'],
                ['date' => '2026-09-29', 'time' => '17:58:00', 'type' => 'out'],
            ],
        ]);

        $this->post(route('authorizations.store'), [
            'employee_id' => $employee->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-09-29',
            'start_time' => '05:00',
            'end_time' => '08:00',
            'hours' => 3.0,
            'reason' => 'Carga temprana Almacén PT',
        ])->assertRedirect(route('authorizations.index'));

        $authorization = Authorization::where('employee_id', $employee->id)->sole();
        $this->assertSame(Authorization::STATUS_APPROVED, $authorization->status);
        $this->assertSame('05:00', $authorization->start_time->format('H:i'));
        $this->assertSame('08:00', $authorization->end_time->format('H:i'));
        $this->assertEqualsWithDelta(3.0, (float) $authorization->hours, 0.01);
        $this->assertTrue((bool) $authorization->is_unbacked_extra, 'se paga sobre el tope del timecard');
        $this->assertNull($authorization->generated_from_authorization_id);

        $payment = app(\App\Services\VeladaCalculatorService::class)
            ->calculate($record->fresh(), $employee->fresh());
        $this->assertEqualsWithDelta(3.0, $payment['overtime_authorized'], 0.01, 'nómina materializa las 3 h una sola vez');
    }

    public function test_admin_bulk_keeps_discarded_morning_punch_flagged_for_payroll(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->create([
            'schedule_id' => Schedule::factory()->create([
                'entry_time' => '08:00',
                'exit_time' => '17:30',
            ])->id,
        ]);
        AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-09-29',
            'check_in' => '07:58:00',
            'check_out' => '17:58:00',
            'raw_punches' => [
                ['date' => '2026-09-29', 'time' => '05:00:00', 'type' => 'punch'],
                ['date' => '2026-09-29', 'time' => '07:58:00', 'type' => 'in'],
                ['date' => '2026-09-29', 'time' => '17:58:00', 'type' => 'out'],
            ],
        ]);

        $this->post(route('authorizations.storeBulk'), [
            'type' => Authorization::TYPE_OVERTIME,
            'reason' => 'Carga masiva desde checadas',
            'entries' => [[
                'employee_id' => $employee->id,
                'date' => '2026-09-29',
                'start_time' => '05:00',
                'end_time' => '08:00',
                'hours' => 3.0,
            ]],
        ])->assertRedirect(route('authorizations.index'));

        $authorization = Authorization::where('employee_id', $employee->id)->sole();
        $this->assertSame(Authorization::STATUS_APPROVED, $authorization->status);
        $this->assertTrue((bool) $authorization->is_unbacked_extra);
        $this->assertEqualsWithDelta(3.0, (float) $authorization->hours, 0.01);
    }

    public function test_ordinary_early_punch_is_approved_without_unbacked_flag(): void
    {
        $this->actingAsSupervisor();
        $employee = Employee::factory()->create([
            'schedule_id' => Schedule::factory()->create([
                'entry_time' => '09:00',
                'exit_time' => '17:30',
            ])->id,
        ]);
        AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-09-29',
            'check_in' => '07:00:00',
            'check_out' => '17:30:00',
            'raw_punches' => [
                ['date' => '2026-09-29', 'time' => '07:00:00', 'type' => 'in'],
                ['date' => '2026-09-29', 'time' => '17:30:00', 'type' => 'out'],
            ],
        ]);

        $this->post(route('authorizations.store'), [
            'employee_id' => $employee->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-09-29',
            'start_time' => '07:00',
            'end_time' => '09:00',
            'hours' => 2.0,
            'reason' => 'Entrada temprana ordinaria',
        ])->assertRedirect(route('authorizations.index'));

        $authorization = Authorization::where('employee_id', $employee->id)->sole();
        $this->assertSame(Authorization::STATUS_APPROVED, $authorization->status);
        $this->assertFalse((bool) $authorization->is_unbacked_extra);
    }

    public function test_load_from_punches_includes_discarded_early_entry_and_subtracts_live_rows(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->create([
            'full_name' => 'Karen Itzel Guerrero Villafuerte',
            'schedule_id' => Schedule::factory()->create([
                'entry_time' => '08:00',
                'exit_time' => '16:00',
            ])->id,
        ]);
        AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-09-23',
            'check_in' => '08:01:00',
            'check_out' => '17:00:00',
            'raw_punches' => [
                ['date' => '2026-09-23', 'time' => '05:00:00', 'type' => 'punch'],
                ['date' => '2026-09-23', 'time' => '08:01:00', 'type' => 'in'],
                ['date' => '2026-09-23', 'time' => '17:00:00', 'type' => 'out'],
            ],
        ]);

        $params = [
            'employee_ids' => [$employee->id],
            'start_date' => '2026-09-23',
            'end_date' => '2026-09-23',
            'type' => Authorization::TYPE_OVERTIME,
        ];

        $this->getJson(route('authorizations.suggestBulk', $params))
            ->assertOk()
            ->assertJsonCount(2, 'suggestions')
            ->assertJsonPath('suggestions.0.kind', 'early')
            ->assertJsonPath('suggestions.0.start_time', '05:00')
            ->assertJsonPath('suggestions.0.end_time', '08:00')
            ->assertJsonPath('suggestions.0.hours', '3.00')
            ->assertJsonPath('suggestions.1.kind', 'late')
            ->assertJsonPath('suggestions.1.start_time', '16:00')
            ->assertJsonPath('suggestions.1.end_time', '17:00');

        Authorization::factory()->create([
            'employee_id' => $employee->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-09-23',
            'start_time' => '05:00',
            'end_time' => '08:00',
            'hours' => 3,
            'status' => Authorization::STATUS_APPROVED,
        ]);

        $this->getJson(route('authorizations.suggestBulk', $params))
            ->assertOk()
            ->assertJsonCount(1, 'suggestions')
            ->assertJsonPath('suggestions.0.kind', 'late');
    }

    public function test_pm_normalization_does_not_split_onto_approved_window_of_another_concept(): void
    {
        $this->adminUser();
        $employee = Employee::factory()->create([
            'schedule_id' => Schedule::factory()->create([
                'entry_time' => '08:00',
                'exit_time' => '17:30',
            ])->id,
        ]);
        AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-09-29',
            'check_in' => '08:00:00',
            'check_out' => '17:58:00',
        ]);
        $approvedConcept = \App\Models\CompensationType::factory()->create([
            'application_mode' => \App\Models\CompensationType::APPLICATION_PER_HOUR,
            'authorization_type' => Authorization::TYPE_OVERTIME,
        ]);
        $pendingConcept = \App\Models\CompensationType::factory()->create([
            'application_mode' => \App\Models\CompensationType::APPLICATION_PER_HOUR,
            'authorization_type' => Authorization::TYPE_OVERTIME,
        ]);
        Authorization::factory()->create([
            'employee_id' => $employee->id,
            'type' => Authorization::TYPE_OVERTIME,
            'compensation_type_id' => $approvedConcept->id,
            'date' => '2026-09-29',
            'start_time' => '17:30',
            'end_time' => '17:58',
            'hours' => 0.5,
            'status' => Authorization::STATUS_APPROVED,
        ]);
        $pending = Authorization::factory()->create([
            'employee_id' => $employee->id,
            'type' => Authorization::TYPE_OVERTIME,
            'compensation_type_id' => $pendingConcept->id,
            'date' => '2026-09-29',
            'start_time' => '05:00',
            'end_time' => '08:00',
            'hours' => 3.0,
            'status' => Authorization::STATUS_PENDING,
        ]);

        $this->artisan('authorizations:auto-approve-overtime')->assertSuccessful();

        $pending->refresh();
        $this->assertSame(Authorization::STATUS_PENDING, $pending->status);
        $this->assertSame('05:00', $pending->start_time->format('H:i'));
        $this->assertEqualsWithDelta(3.0, (float) $pending->hours, 0.01);
        $this->assertSame(2, Authorization::where('employee_id', $employee->id)->count());
    }
}
