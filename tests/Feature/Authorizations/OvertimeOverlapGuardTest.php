<?php

namespace Tests\Feature\Authorizations;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\Employee;
use App\Models\Schedule;
use App\Models\User;
use Tests\FeatureTestCase;

/**
 * Blindaje contra el doble pago de TIEMPO EXTRA encimado (caso Corte /
 * Jesús Fabián, 2026-08-12).
 *
 * Dos hoyos reales explotados el mismo día:
 * 1. El dedup de TE solo comparaba el rango EXACTO, así que re-capturar
 *    17:30–20:00 cuando 17:30–18:47 ya estaba aprobada pasaba — y el split
 *    volvía a aprobar la misma checada (doble pago).
 * 2. El excedente de un split (`is_unbacked_extra`) era editable: le
 *    cambiaron la ventana para dejarlo idéntico a la parte ya aprobada y al
 *    aprobarlo se pagó doble.
 *
 * Reglas nuevas: rangos de TE que se ENCIMAN son duplicado (contiguos no);
 * una ventana encimada con TE ya aprobado no se auto-aprueba ni se parte; el
 * excedente de un split no se edita (se aprueba con ajuste de horas o se
 * rechaza); editar una pendiente tampoco puede dejarla encimada.
 */
class OvertimeOverlapGuardTest extends FeatureTestCase
{
    /** Empleado con jornada 08:00–16:30 (mismo perfil que el caso real de Corte). */
    private function corteEmployee(): Employee
    {
        return Employee::factory()->create([
            'schedule_id' => Schedule::factory()->create([
                'entry_time' => '08:00',
                'exit_time' => '16:30',
            ])->id,
        ]);
    }

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

    private function approvedOvertime(Employee $emp, string $start, string $end, float $hours): Authorization
    {
        return Authorization::create([
            'employee_id' => $emp->id,
            'requested_by' => User::factory()->create()->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-08',
            'start_time' => $start,
            'end_time' => $end,
            'hours' => $hours,
            'reason' => 'TE ya aprobado',
            'status' => Authorization::STATUS_APPROVED,
        ]);
    }

    public function test_capture_overlapping_approved_overtime_is_blocked_as_duplicate(): void
    {
        // Caso Jesús Fabián: 17:30–18:47 ya aprobada (split de una captura
        // anterior) y la encargada vuelve a capturar 17:30–20:00 completo.
        $this->actingAsSupervisor();
        $emp = $this->corteEmployee();
        $this->recordWithCheckout($emp, '18:47:00', 1.0);
        $this->approvedOvertime($emp, '17:30', '18:47', 1.0);

        $this->from(route('authorizations.create'))->post(route('authorizations.store'), [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-08',
            'start_time' => '17:30',
            'end_time' => '20:00',
            'hours' => 2.5,
            'reason' => 're-captura de lo mismo',
        ])->assertSessionHasErrors('compensation_type_id');

        $this->assertSame(1, Authorization::where('employee_id', $emp->id)->count(), 'la re-captura encimada no crea fila');
    }

    public function test_contiguous_overtime_block_is_not_a_duplicate(): void
    {
        // Bloques contiguos son legítimos: 16:30–18:00 aprobado y el nuevo
        // 18:00–19:00 arranca justo donde terminó el anterior (prueba
        // semiabierta). La checada hasta 19:06 respalda el bloque nuevo.
        $this->actingAsSupervisor();
        $emp = $this->corteEmployee();
        $this->recordWithCheckout($emp, '19:06:00', 2.6);
        $this->approvedOvertime($emp, '16:30', '18:00', 1.5);

        $this->from(route('authorizations.create'))->post(route('authorizations.store'), [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-08',
            'start_time' => '18:00',
            'end_time' => '19:00',
            'hours' => 1.0,
            'reason' => 'bloque contiguo',
        ])->assertSessionDoesntHaveErrors();

        $this->assertSame(2, Authorization::where('employee_id', $emp->id)->count(), 'el bloque contiguo sí se crea');
    }

    public function test_pending_overtime_overlapping_approved_is_not_auto_approved_nor_split(): void
    {
        // Una pendiente que se encima con TE ya aprobado (p.ej. quedó de antes
        // del blindaje, o la editaron a mano) NO debe auto-aprobarse ni
        // partirse en el Recalcular: ese tramo de checada ya pagó.
        $approver = $this->actingAsAdmin();
        $approver->givePermissionTo('authorizations.approve');
        $emp = $this->corteEmployee();
        $this->recordWithCheckout($emp, '19:06:00', 2.6);
        $this->approvedOvertime($emp, '16:30', '18:00', 1.5);

        $pending = Authorization::create([
            'employee_id' => $emp->id,
            'requested_by' => User::factory()->create()->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-08',
            'start_time' => '17:00',
            'end_time' => '19:00',
            'hours' => 2.0,
            'reason' => 'encimada con lo ya aprobado',
            'status' => Authorization::STATUS_PENDING,
        ]);

        $this->post(route('authorizations.autoApprovePending'))->assertRedirect();

        $pending->refresh();
        $this->assertSame(Authorization::STATUS_PENDING, $pending->status, 'sigue pendiente, a revisión humana');
        $this->assertEqualsWithDelta(2.0, (float) $pending->hours, 0.01, 'sin encoger: no hubo split');
        $this->assertSame(2, Authorization::where('employee_id', $emp->id)->count(), 'no nació ningún excedente');
    }

    public function test_unbacked_excess_cannot_be_edited_even_by_admin(): void
    {
        // El excedente de un split es evidencia del sistema: editarlo permitía
        // disfrazarlo de la parte respaldada y pagar doble. Ni el admin edita;
        // se aprueba (ajustando horas al aprobar) o se rechaza.
        $this->actingAsAdmin();
        $emp = $this->corteEmployee();

        $excess = Authorization::create([
            'employee_id' => $emp->id,
            'requested_by' => User::factory()->create()->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-08',
            'start_time' => '19:06',
            'end_time' => '20:00',
            'hours' => 1.0,
            'reason' => 'excedente de split',
            'status' => Authorization::STATUS_PENDING,
            'is_unbacked_extra' => true,
        ]);

        $this->get(route('authorizations.edit', $excess))->assertForbidden();

        $this->put(route('authorizations.update', $excess), [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-08',
            'start_time' => '16:30',
            'end_time' => '17:00',
            'hours' => 0.5,
            'reason' => 'disfrazar de la parte respaldada',
        ])->assertForbidden();

        $excess->refresh();
        $this->assertSame('19:06', $excess->start_time->format('H:i'), 'la ventana del excedente queda intacta');
    }

    public function test_editing_pending_overtime_onto_approved_window_is_blocked(): void
    {
        // Editar una pendiente normal tampoco puede dejarla encimada sobre una
        // ventana ya aprobada (el otro camino al doble pago).
        $this->actingAsAdmin();
        $emp = $this->corteEmployee();
        $this->approvedOvertime($emp, '16:30', '18:47', 1.0);

        $pending = Authorization::create([
            'employee_id' => $emp->id,
            'requested_by' => User::factory()->create()->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-08',
            'start_time' => '19:00',
            'end_time' => '20:00',
            'hours' => 1.0,
            'reason' => 'bloque aparte',
            'status' => Authorization::STATUS_PENDING,
        ]);

        $this->from(route('authorizations.edit', $pending))->put(route('authorizations.update', $pending), [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-08',
            'start_time' => '17:00',
            'end_time' => '19:30',
            'hours' => 2.5,
            'reason' => 'moverla sobre lo ya aprobado',
        ])->assertSessionHasErrors('start_time');

        $pending->refresh();
        $this->assertSame('19:00', $pending->start_time->format('H:i'), 'la pendiente no se movió');
    }
}
