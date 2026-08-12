<?php

namespace Tests\Feature\Authorizations;

use App\Models\Authorization;
use App\Models\CheckOmission;
use App\Models\Employee;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\FeatureTestCase;

/**
 * Recordatorio de omisión aprobada en las autorizaciones de TE (Dani
 * 2026-08-12).
 *
 * Un TE pendiente de un día SIN checada nunca se auto-aprueba (no hay marca
 * que lo respalde). Cuando la omisión de checada de ese día ya fue APROBADA,
 * la ausencia de marca está justificada: la lista marca la fila ("Omisión
 * aprobada") y el detalle explica el motivo, para que el aprobador decida a
 * mano con esa certeza. Una omisión solo AUTORIZADA (aún sin aprobar) no
 * marca nada.
 */
class OmissionReminderOnOvertimeTest extends FeatureTestCase
{
    private function pendingOvertime(Employee $emp, string $date): Authorization
    {
        return Authorization::create([
            'employee_id' => $emp->id,
            'requested_by' => User::factory()->create()->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => $date,
            'start_time' => '17:00',
            'end_time' => '19:00',
            'hours' => 2.0,
            'reason' => 'TE de día sin checada',
            'status' => Authorization::STATUS_PENDING,
        ]);
    }

    public function test_index_marks_pending_overtime_whose_day_has_approved_omission(): void
    {
        $this->actingAsAdmin();
        $emp = Employee::factory()->create();
        $this->pendingOvertime($emp, '2026-06-08');
        $this->pendingOvertime($emp, '2026-06-09');

        CheckOmission::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => '2026-06-08',
            'status' => CheckOmission::STATUS_APPROVED,
            'approved_by' => User::factory()->create()->id,
            'approved_at' => now(),
        ]);
        // Solo autorizada (falta el paso del admin): todavía no justifica el día.
        CheckOmission::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => '2026-06-09',
        ]);

        $this->get(route('authorizations.index', ['from_date' => '2026-06-08', 'to_date' => '2026-06-08']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('authorizations.data.0.has_approved_omission', true));

        $this->get(route('authorizations.index', ['from_date' => '2026-06-09', 'to_date' => '2026-06-09']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('authorizations.data.0.has_approved_omission', false));
    }

    public function test_show_exposes_approved_omission_details(): void
    {
        $this->actingAsAdmin();
        $emp = Employee::factory()->create();
        $conOmision = $this->pendingOvertime($emp, '2026-06-08');
        $sinOmision = $this->pendingOvertime($emp, '2026-06-09');

        CheckOmission::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => '2026-06-08',
            'reason' => CheckOmission::REASON_DELIVERY,
            'comments' => 'Salió a entregar a Walmart',
            'status' => CheckOmission::STATUS_APPROVED,
            'approved_by' => User::factory()->create()->id,
            'approved_at' => now(),
        ]);

        $this->get(route('authorizations.show', $conOmision))
            ->assertInertia(fn (Assert $page) => $page
                ->where('approvedOmission.reason_label', 'Entrega de mercancía')
                ->where('approvedOmission.comments', 'Salió a entregar a Walmart'));

        $this->get(route('authorizations.show', $sinOmision))
            ->assertInertia(fn (Assert $page) => $page
                ->where('approvedOmission', null));
    }
}
