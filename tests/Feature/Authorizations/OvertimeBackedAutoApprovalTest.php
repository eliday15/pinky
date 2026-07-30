<?php

namespace Tests\Feature\Authorizations;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\Employee;
use App\Models\Schedule;
use Tests\FeatureTestCase;

/**
 * Auto-aprobación de TIEMPO EXTRA respaldado por la checada (Luis 2026-07-30).
 *
 * Antes solo se autoaprobaba el TE que coincidía EXACTO con el segmento
 * detectado (fila cargada desde checadas sin tocar). Las post-autorizaciones
 * tecleadas en números redondos (16:30–19:00 = 2.5h) no cuadraban con la salida
 * real (19:06) y quedaban pendientes. Ahora se autoaprueban cuando la checada
 * RESPALDA la ventana (fin ≤ salida real, horas ≤ detectadas); lo que reclama
 * más de lo que la checada demuestra sigue a revisión. Seguro para nómina: el
 * pago ya se topa a mín(autorizado, detectado).
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

    public function test_overtime_claiming_more_than_backed_stays_pending(): void
    {
        $this->actingAsSupervisor();
        $emp = $this->corteEmployee();
        // Salida 19:06 → respalda hasta 19:06 (2.5h).
        $this->recordWithCheckout($emp, '19:06:00', 2.6);

        // Reclama 16:30–20:00 (3.5h): el fin 20:00 excede la salida real → a revisión.
        $this->from(route('authorizations.create'))->post(route('authorizations.store'), [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-08',
            'start_time' => '16:30',
            'end_time' => '20:00',
            'hours' => 3.5,
            'reason' => 'reclama de más',
        ])->assertRedirect(route('authorizations.index'));

        $this->assertDatabaseHas('authorizations', [
            'employee_id' => $emp->id,
            'status' => Authorization::STATUS_PENDING,
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

    public function test_command_leaves_unbacked_pending(): void
    {
        $this->adminUser();
        $emp = $this->corteEmployee();
        // Salida 17:30 → solo 1.0h respaldada.
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

        $this->assertSame(Authorization::STATUS_PENDING, $auth->fresh()->status);
    }
}
