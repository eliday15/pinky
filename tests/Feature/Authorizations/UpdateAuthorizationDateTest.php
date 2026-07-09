<?php

namespace Tests\Feature\Authorizations;

use App\Models\Authorization;
use App\Models\Employee;
use App\Models\User;
use Tests\FeatureTestCase;

/**
 * Editar la FECHA de una autorización — SOLO el admin (Luis 2026-07-09).
 *
 * Mover la fecha cambia en qué semana de nómina cae la autorización (lo que
 * antes se hacía por base de datos). El botón/endpoint queda restringido al
 * rol admin.
 */
class UpdateAuthorizationDateTest extends FeatureTestCase
{
    private function approvedAuth(string $date = '2026-07-09'): Authorization
    {
        return Authorization::factory()->approved()->create([
            'employee_id' => Employee::factory()->create()->id,
            'requested_by' => User::factory()->create()->id,
            'date' => $date,
        ]);
    }

    public function test_admin_can_change_the_date(): void
    {
        $this->actingAsAdmin();
        $auth = $this->approvedAuth('2026-07-09');

        $this->post(route('authorizations.updateDate', $auth), ['date' => '2026-07-06'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('2026-07-06', $auth->fresh()->date->toDateString());
    }

    public function test_index_exposes_edit_date_only_to_admin(): void
    {
        $this->actingAsAdmin();
        $this->approvedAuth();

        $this->get(route('authorizations.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('can.edit_date', true));
    }

    public function test_rrhh_cannot_change_the_date(): void
    {
        $this->actingAsRrhh();
        $auth = $this->approvedAuth('2026-07-09');

        $this->post(route('authorizations.updateDate', $auth), ['date' => '2026-07-06'])
            ->assertForbidden();

        $this->assertSame('2026-07-09', $auth->fresh()->date->toDateString(), 'la fecha no cambia');
    }

    public function test_supervisor_cannot_change_the_date(): void
    {
        $user = $this->actingAsSupervisor();
        $this->attachEmployee($user);
        $auth = $this->approvedAuth('2026-07-09');

        $this->post(route('authorizations.updateDate', $auth), ['date' => '2026-07-06'])
            ->assertForbidden();
    }

    public function test_index_hides_edit_date_from_non_admin(): void
    {
        $user = $this->actingAsSupervisor();
        $this->attachEmployee($user);

        $this->get(route('authorizations.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('can.edit_date', false));
    }

    public function test_cannot_change_date_of_paid_authorization(): void
    {
        $this->actingAsAdmin();
        $auth = $this->approvedAuth('2026-07-09');
        $auth->update(['status' => Authorization::STATUS_PAID]);

        $this->post(route('authorizations.updateDate', $auth), ['date' => '2026-07-06'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('2026-07-09', $auth->fresh()->date->toDateString());
    }

    public function test_date_is_required_and_valid(): void
    {
        $this->actingAsAdmin();
        $auth = $this->approvedAuth('2026-07-09');

        $this->post(route('authorizations.updateDate', $auth), ['date' => 'no-es-fecha'])
            ->assertSessionHasErrors('date');

        $this->assertSame('2026-07-09', $auth->fresh()->date->toDateString());
    }
}
