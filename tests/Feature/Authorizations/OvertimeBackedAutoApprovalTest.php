<?php

namespace Tests\Feature\Authorizations;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\Employee;
use App\Models\Schedule;
use Tests\FeatureTestCase;

/**
 * Auto-aprobación de TIEMPO EXTRA respaldado por la checada (Luis 2026-07-30)
 * y PARTICIÓN del que reclama de más (Elias 2026-08-05).
 *
 * Antes solo se autoaprobaba el TE que coincidía EXACTO con el segmento
 * detectado (fila cargada desde checadas sin tocar). Las post-autorizaciones
 * tecleadas en números redondos (16:30–19:00 = 2.5h) no cuadraban con la salida
 * real (19:06) y quedaban pendientes. Ahora se autoaprueban cuando la checada
 * RESPALDA la ventana (fin ≤ salida real, horas ≤ detectadas). Lo que reclama
 * MÁS de lo que la checada demuestra se PARTE: la porción respaldada se aprueba
 * sola y el excedente queda pendiente marcado `is_unbacked_extra` — aprobarlo
 * es la decisión consciente de pagar extra no hecho en el reloj.
 */
class OvertimeBackedAutoApprovalTest extends FeatureTestCase
{
    /** Empleado con jornada 08:00–16:30 (como el caso real de Corte: "sale a las 4:30"). */
    private function corteEmployee(): Employee
    {
        return Employee::factory()->create([
            'schedule_id' => Schedule::factory()->create([
                'entry_time' => '08:00',
                'exit_time' => '16:30',
            ])->id,
        ]);
    }

    /** Registro que replica el caso real: entra 08:00, sale tarde → TE late 16:30→salida. */
    private function recordWithCheckout(Employee $emp, string $checkOut, float $overtimeHours): AttendanceRecord
    {
        return AttendanceRecord::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => '2026-06-08', // lunes
            'check_in' => '08:00:00',
            'check_out' => $checkOut,
            'overtime_hours' => $overtimeHours,
        ]);
    }

    public function test_backed_overtime_auto_approves_when_captured_by_non_approver(): void
    {
        // Supervisor (captura, NO aprueba) → cae a la auto-aprobación por checadas.
        $this->actingAsSupervisor();
        $emp = $this->corteEmployee();
        // Salida 19:06 (jornada 08:00–16:30) → detectado 16:30–19:06 = 2.5h.
        $this->recordWithCheckout($emp, '19:06:00', 2.6);

        // Capturado a mano en redondo: 16:30–19:00 (2.5h). NO es match exacto
        // (fin 19:00 ≠ 19:06) pero la checada lo respalda.
        $this->from(route('authorizations.create'))->post(route('authorizations.store'), [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-08',
            'start_time' => '16:30',
            'end_time' => '19:00',
            'hours' => 2.5,
            'reason' => 'AUTORIZADO POR EDUARDO',
        ])->assertRedirect(route('authorizations.index'));

        $this->assertDatabaseHas('authorizations', [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'status' => Authorization::STATUS_APPROVED,
        ]);
    }

    public function test_overtime_claiming_more_than_backed_splits_into_approved_and_flagged_excess(): void
    {
        $this->actingAsSupervisor();
        $emp = $this->corteEmployee();
        // Salida 19:06 → respalda 16:30–19:06 (2.5h).
        $this->recordWithCheckout($emp, '19:06:00', 2.6);

        // Reclama 16:30–20:00 (3.5h): el fin 20:00 excede la salida real →
        // se parte: 2.5h aprobadas (lo que sí hizo) + 1.0h pendiente marcada
        // como extra fuera de checada.
        $this->from(route('authorizations.create'))->post(route('authorizations.store'), [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-08',
            'start_time' => '16:30',
            'end_time' => '20:00',
            'hours' => 3.5,
            'reason' => 'reclama de más',
        ])->assertRedirect(route('authorizations.index'));

        $approved = Authorization::where('employee_id', $emp->id)
            ->where('status', Authorization::STATUS_APPROVED)
            ->first();
        $this->assertNotNull($approved, 'la porción respaldada se aprueba sola');
        $this->assertEqualsWithDelta(2.5, (float) $approved->hours, 0.01);
        $this->assertFalse((bool) $approved->is_unbacked_extra);
        $this->assertSame('19:06', $approved->end_time->format('H:i'), 'encogida a la salida real');

        $excess = Authorization::where('employee_id', $emp->id)
            ->where('status', Authorization::STATUS_PENDING)
            ->first();
        $this->assertNotNull($excess, 'el excedente queda pendiente');
        $this->assertTrue((bool) $excess->is_unbacked_extra, 'marcado como extra fuera de checada');
        $this->assertEqualsWithDelta(1.0, (float) $excess->hours, 0.01);
        $this->assertSame('19:06', $excess->start_time->format('H:i'));
        $this->assertSame('20:00', $excess->end_time->format('H:i'));
        $this->assertSame($approved->id, $excess->generated_from_authorization_id, 'ligado a la porción aprobada');
    }

    public function test_fully_unbacked_overtime_stays_pending_without_split(): void
    {
        $this->actingAsSupervisor();
        $emp = $this->corteEmployee();
        // Salida 16:40 → solo 10 min tras la jornada → redondea a 0: nada respaldado.
        $this->recordWithCheckout($emp, '16:40:00', 0.0);

        $this->from(route('authorizations.create'))->post(route('authorizations.store'), [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-08',
            'start_time' => '17:00',
            'end_time' => '19:00',
            'hours' => 2.0,
            'reason' => 'nada en el reloj',
        ])->assertRedirect(route('authorizations.index'));

        $this->assertSame(1, Authorization::where('employee_id', $emp->id)->count(), 'sin split: una sola autorización');
        $this->assertDatabaseHas('authorizations', [
            'employee_id' => $emp->id,
            'status' => Authorization::STATUS_PENDING,
            'is_unbacked_extra' => false,
        ]);
    }

    public function test_command_auto_approves_backed_pending_overtime(): void
    {
        $this->adminUser(); // el barrido firma con un admin del sistema
        $emp = $this->corteEmployee();
        $this->recordWithCheckout($emp, '19:06:00', 2.6);

        $auth = Authorization::factory()->create([
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-08',
            'start_time' => '16:30',
            'end_time' => '19:00',
            'hours' => 2.5,
            'status' => Authorization::STATUS_PENDING,
        ]);

        $this->artisan('authorizations:auto-approve-overtime')->assertSuccessful();

        $this->assertSame(Authorization::STATUS_APPROVED, $auth->fresh()->status);
    }

    public function test_command_dry_run_does_not_approve(): void
    {
        $this->adminUser();
        $emp = $this->corteEmployee();
        $this->recordWithCheckout($emp, '19:06:00', 2.6);

        $auth = Authorization::factory()->create([
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-08',
            'start_time' => '16:30',
            'end_time' => '19:00',
            'hours' => 2.5,
            'status' => Authorization::STATUS_PENDING,
        ]);

        $this->artisan('authorizations:auto-approve-overtime --dry-run')->assertSuccessful();

        $this->assertSame(Authorization::STATUS_PENDING, $auth->fresh()->status, 'dry-run no aprueba');
    }

    public function test_command_splits_overclaimed_pending_overtime(): void
    {
        $this->adminUser();
        $emp = $this->corteEmployee();
        // Salida 17:30 → solo 1.0h respaldada (16:30–17:30).
        $this->recordWithCheckout($emp, '17:30:00', 1.0);

        $auth = Authorization::factory()->create([
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-08',
            'start_time' => '16:30',
            'end_time' => '19:00',
            'hours' => 2.5, // reclama 2.5 pero la checada solo respalda 1.0
            'status' => Authorization::STATUS_PENDING,
        ]);

        $this->artisan('authorizations:auto-approve-overtime')->assertSuccessful();

        // El barrido la parte: 1.0h aprobada (encogida a 17:30) + 1.5h
        // pendiente marcada como extra fuera de checada.
        $auth->refresh();
        $this->assertSame(Authorization::STATUS_APPROVED, $auth->status);
        $this->assertEqualsWithDelta(1.0, (float) $auth->hours, 0.01);
        $this->assertSame('17:30', $auth->end_time->format('H:i'));

        $excess = Authorization::where('generated_from_authorization_id', $auth->id)->first();
        $this->assertNotNull($excess);
        $this->assertTrue((bool) $excess->is_unbacked_extra);
        $this->assertSame(Authorization::STATUS_PENDING, $excess->status);
        $this->assertEqualsWithDelta(1.5, (float) $excess->hours, 0.01);
    }

    public function test_command_never_touches_flagged_excess(): void
    {
        $this->adminUser();
        $emp = $this->corteEmployee();
        $this->recordWithCheckout($emp, '19:06:00', 2.6);

        // Un excedente ya partido (dentro de la ventana detectada, para probar
        // que la bandera manda): el barrido NO lo aprueba ni lo vuelve a partir.
        $excess = Authorization::factory()->create([
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-08',
            'start_time' => '16:30',
            'end_time' => '19:00',
            'hours' => 2.5,
            'status' => Authorization::STATUS_PENDING,
            'is_unbacked_extra' => true,
        ]);

        $this->artisan('authorizations:auto-approve-overtime')->assertSuccessful();

        $this->assertSame(Authorization::STATUS_PENDING, $excess->fresh()->status, 'el excedente espera decisión humana');
        $this->assertSame(1, Authorization::where('employee_id', $emp->id)->count(), 'no se re-parte');
    }
}
