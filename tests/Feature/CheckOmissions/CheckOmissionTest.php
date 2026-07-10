<?php

namespace Tests\Feature\CheckOmissions;

use App\Models\AttendanceRecord;
use App\Models\CheckOmission;
use App\Models\Employee;
use App\Models\Schedule;
use App\Services\ZktecoSyncService;
use Tests\FeatureTestCase;

/**
 * Autorización de omisión de checada (Dani 2026-07-09).
 *
 * - Falta automática por checada incompleta (entrada o salida) en día
 *   obligatorio (excluyendo turnos nocturnos/velada).
 * - Flujo de 2 pasos: el jefe autoriza (create), el admin aprueba (approve).
 * - Efecto al aprobar: "entrega de mercancía" → present (paga completo);
 *   "otro" → late (retardo que cuenta al acumulado mensual).
 */
class CheckOmissionTest extends FeatureTestCase
{
    private const WEDNESDAY = '2026-06-17';

    private function dayEmployee(): Employee
    {
        $schedule = Schedule::factory()->create([
            'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
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

    /** Registró entrada pero NO salida en un día obligatorio. */
    private function missingCheckoutRecord(Employee $e, bool $nightShift = false): AttendanceRecord
    {
        return AttendanceRecord::factory()->for($e)->create([
            'work_date' => self::WEDNESDAY,
            'check_in' => '08:00:00',
            'check_out' => null,
            'status' => 'present',
            'is_night_shift' => $nightShift,
        ]);
    }

    public function test_missing_checkout_on_a_weekday_is_a_falta(): void
    {
        $e = $this->dayEmployee();
        $rec = $this->missingCheckoutRecord($e);

        app(ZktecoSyncService::class)->recalculateAttendanceRecord($rec);

        $this->assertSame('absent', $rec->fresh()->status, 'sin salida en día obligatorio es falta');
    }

    public function test_missing_checkout_on_night_shift_is_not_a_falta(): void
    {
        $e = $this->dayEmployee();
        $rec = $this->missingCheckoutRecord($e, nightShift: true);

        app(ZktecoSyncService::class)->recalculateAttendanceRecord($rec);

        $this->assertNotSame('absent', $rec->fresh()->status, 'los turnos nocturnos/velada se excluyen');
    }

    public function test_approved_delivery_omission_pays_the_day_as_present(): void
    {
        $e = $this->dayEmployee();
        $rec = $this->missingCheckoutRecord($e);
        app(ZktecoSyncService::class)->recalculateAttendanceRecord($rec);
        $this->assertSame('absent', $rec->fresh()->status);

        CheckOmission::factory()->for($e)->delivery()->approved()->create([
            'work_date' => self::WEDNESDAY,
            'attendance_record_id' => $rec->id,
        ]);

        app(ZktecoSyncService::class)->recalculateAttendanceRecord($rec);

        $this->assertSame('present', $rec->fresh()->status, 'entrega de mercancía paga el día completo');
    }

    public function test_approved_other_omission_becomes_a_retardo(): void
    {
        $e = $this->dayEmployee();
        $rec = $this->missingCheckoutRecord($e);
        app(ZktecoSyncService::class)->recalculateAttendanceRecord($rec);
        $this->assertSame('absent', $rec->fresh()->status);

        CheckOmission::factory()->for($e)->other()->approved()->create([
            'work_date' => self::WEDNESDAY,
            'attendance_record_id' => $rec->id,
        ]);

        app(ZktecoSyncService::class)->recalculateAttendanceRecord($rec);

        $this->assertSame('late', $rec->fresh()->status, 'otro se convierte en retardo');
    }

    public function test_supervisor_authorizes_and_admin_approves_flips_status(): void
    {
        // Jefe (supervisor) con su empleado; el subordinado tiene la falta.
        $supervisorUser = $this->supervisorUser();
        $boss = $this->attachEmployee($supervisorUser);
        $e = $this->dayEmployee();
        $e->update(['supervisor_id' => $boss->id]);

        $rec = $this->missingCheckoutRecord($e);
        app(ZktecoSyncService::class)->recalculateAttendanceRecord($rec);
        $this->assertSame('absent', $rec->fresh()->status);

        // Paso 1: el jefe autoriza.
        $this->actingAs($supervisorUser)
            ->post(route('check-omissions.store'), [
                'employee_id' => $e->id,
                'work_date' => self::WEDNESDAY,
                'reason' => CheckOmission::REASON_DELIVERY,
                'comments' => 'Estuvo en entrega',
            ])->assertRedirect(route('check-omissions.index'));

        $omission = CheckOmission::where('employee_id', $e->id)->firstOrFail();
        $this->assertSame(CheckOmission::STATUS_AUTHORIZED, $omission->status);
        $this->assertSame($supervisorUser->id, $omission->authorized_by);
        // Aún no aplica el efecto.
        $this->assertSame('absent', $rec->fresh()->status);

        // Un supervisor NO puede aprobar.
        $this->actingAs($supervisorUser)
            ->post(route('check-omissions.approve', $omission))
            ->assertForbidden();

        // Paso 2: el administrador aprueba → aplica el efecto.
        $this->actingAsAdmin();
        $this->post(route('check-omissions.approve', $omission))
            ->assertRedirect();

        $this->assertSame(CheckOmission::STATUS_APPROVED, $omission->fresh()->status);
        $this->assertSame('present', $rec->fresh()->status);
    }

    public function test_other_reason_requires_a_comment(): void
    {
        $this->actingAsAdmin();
        $e = $this->dayEmployee();

        $this->post(route('check-omissions.store'), [
            'employee_id' => $e->id,
            'work_date' => self::WEDNESDAY,
            'reason' => CheckOmission::REASON_OTHER,
            'comments' => '',
        ])->assertSessionHasErrors('comments');

        $this->assertDatabaseCount('check_omissions', 0);
    }
}
