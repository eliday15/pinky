<?php

namespace Tests\Feature\Incidents;

use App\Models\Employee;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\TwoFactorDevice;
use App\Models\User;
use App\Services\DecemberVacationService;
use Illuminate\Support\Facades\Crypt;
use PragmaRX\Google2FA\Google2FA;
use Tests\FeatureTestCase;

/**
 * Cierre obligatorio de diciembre (Dani 2026-07-17).
 *
 * El Administrador define N dias obligatorios para toda la empresa. Esos dias
 * quedan APARTADOS (no se pueden solicitar en otra fecha) y NO se marcan como
 * usados: RRHH los captura cuando llega diciembre. A los de nuevo ingreso, que
 * aun no generan derecho, se les ADELANTAN; esa deuda se salda sola cuando
 * generan su derecho.
 */
class DecemberVacationTest extends FeatureTestCase
{
    private function service(): DecemberVacationService
    {
        return app(DecemberVacationService::class);
    }

    private function employee(int $entitled, int $used = 0, ?string $hireDate = '2019-01-01'): Employee
    {
        return Employee::factory()->create([
            'status' => 'active',
            'hire_date' => $hireDate,
            'vacation_days_entitled' => $entitled,
            'vacation_days_used' => $used,
            'vacation_days_reserved' => 0,
            'vacation_days_advanced' => 0,
            'vacation_hours_used' => 0,
        ]);
    }

    private function vacationType(): IncidentType
    {
        return IncidentType::firstOrCreate(
            ['code' => 'VAC'],
            [
                'name' => 'Vacaciones',
                'category' => 'vacation',
                'is_paid' => true,
                'deducts_vacation' => true,
                'requires_approval' => true,
                'color' => '#10B981',
            ],
        );
    }

    // ---- Reparto ----------------------------------------------------------

    public function test_employee_with_enough_days_reserves_them_without_advance(): void
    {
        // Ejemplo de Dani: derecho 16, obligatorios 10 -> para disfrutar 6.
        $e = $this->employee(entitled: 16);

        $this->service()->apply(10);
        $e->refresh();

        $this->assertSame(10, (int) $e->vacation_days_reserved);
        $this->assertSame(0, (int) $e->vacation_days_advanced);
        $this->assertSame(6, $e->vacation_days_for_enjoyment, 'para disfrutar = derecho - obligatorios');
        $this->assertSame(0, (int) $e->vacation_days_used, 'los apartados NO se marcan como usados');
    }

    public function test_reserved_days_do_not_consume_used_and_reduce_available(): void
    {
        // Derecho 16, obligatorios 10, ya usó 2 -> disponibles 4 (ejemplo de Dani).
        $e = $this->employee(entitled: 16, used: 2);

        $this->service()->apply(10);
        $e->refresh();

        $this->assertSame(4, $e->vacation_days_remaining);
        $this->assertEqualsWithDelta(4.0, $e->vacation_days_available_for_request, 0.001);
    }

    public function test_new_hire_without_entitlement_gets_the_days_advanced(): void
    {
        // Nuevo ingreso: aun no genera derecho, se le adelantan los 10.
        $e = $this->employee(entitled: 0, hireDate: now()->subMonths(3)->toDateString());

        $this->service()->apply(10);
        $e->refresh();

        $this->assertSame(0, (int) $e->vacation_days_reserved);
        $this->assertSame(10, (int) $e->vacation_days_advanced, 'se le adelantan los 10 dias');
    }

    public function test_new_hire_with_partial_entitlement_reserves_and_advances_the_rest(): void
    {
        $e = $this->employee(entitled: 4, hireDate: now()->subMonths(8)->toDateString());

        $this->service()->apply(10);
        $e->refresh();

        $this->assertSame(4, (int) $e->vacation_days_reserved, 'aparta lo que si tiene');
        $this->assertSame(6, (int) $e->vacation_days_advanced, 'adelanta el faltante');
    }

    public function test_veteran_without_enough_days_reserves_what_is_left_without_advance(): void
    {
        // Con antiguedad pero ya gastó casi todo: no se le adelanta.
        $e = $this->employee(entitled: 12, used: 9, hireDate: '2018-01-01');

        $this->service()->apply(10);
        $e->refresh();

        $this->assertSame(3, (int) $e->vacation_days_reserved);
        $this->assertSame(0, (int) $e->vacation_days_advanced, 'el adelanto es solo para nuevo ingreso');
    }

    public function test_applying_twice_does_not_accumulate(): void
    {
        $e = $this->employee(entitled: 16);

        $this->service()->apply(10);
        $this->service()->apply(6);
        $e->refresh();

        $this->assertSame(6, (int) $e->vacation_days_reserved, 'recalcula desde cero, no acumula');
    }

    // ---- Bloqueo de solicitudes ------------------------------------------

    /** 2FA real (secreto cifrado) + codigo TOTP vigente, que exige el approve. */
    private function attachRealTwoFactor(User $user): string
    {
        TwoFactorDevice::where('user_id', $user->id)->delete();

        $google = new Google2FA();
        $secret = $google->generateSecretKey();

        TwoFactorDevice::create([
            'user_id' => $user->id,
            'name' => 'Encrypted Authenticator',
            'secret' => Crypt::encryptString($secret),
            'confirmed_at' => now(),
        ]);

        return $google->getCurrentOtp($secret);
    }

    /** Solicitud de vacaciones pendiente de aprobar. */
    private function pendingVacation(Employee $e, int $days): Incident
    {
        return Incident::factory()->create([
            'employee_id' => $e->id,
            'incident_type_id' => $this->vacationType()->id,
            'start_date' => '2026-08-03',
            'end_date' => '2026-08-14',
            'days_count' => $days,
            'status' => 'pending',
        ]);
    }

    public function test_reserved_days_cannot_be_approved(): void
    {
        // Derecho 16, obligatorios 10 -> solo puede disfrutar 6.
        $e = $this->employee(entitled: 16);
        $this->service()->apply(10);

        $incident = $this->pendingVacation($e, 10); // pide 10 > 6 disponibles

        $admin = $this->actingAsAdmin();
        $code = $this->attachRealTwoFactor($admin);

        $this->post(route('incidents.approve', $incident), ['two_factor_code' => $code])
            ->assertSessionHasErrors('saldo');

        $this->assertSame('pending', $incident->fresh()->status, 'no se aprueba con los dias apartados');
    }

    public function test_days_within_the_enjoyment_window_can_be_approved(): void
    {
        $e = $this->employee(entitled: 16);
        $this->service()->apply(10);

        $incident = $this->pendingVacation($e, 6); // 6 <= 6 disponibles

        $admin = $this->actingAsAdmin();
        $code = $this->attachRealTwoFactor($admin);

        $this->post(route('incidents.approve', $incident), ['two_factor_code' => $code])
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', $incident->fresh()->status);
    }

    public function test_without_the_december_closure_the_full_balance_is_approvable(): void
    {
        // Mismo caso pero SIN aplicar el cierre: los 10 dias si pasan. Prueba que
        // el bloqueo viene de los dias apartados y no de otra regla.
        $e = $this->employee(entitled: 16);

        $incident = $this->pendingVacation($e, 10);

        $admin = $this->actingAsAdmin();
        $code = $this->attachRealTwoFactor($admin);

        $this->post(route('incidents.approve', $incident), ['two_factor_code' => $code])
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', $incident->fresh()->status);
    }

    // ---- Deuda del adelanto ----------------------------------------------

    public function test_advanced_days_block_requests_until_settled(): void
    {
        $e = $this->employee(entitled: 0, hireDate: now()->subMonths(3)->toDateString());
        $this->service()->apply(10);
        $e->refresh();

        $this->assertEqualsWithDelta(0.0, $e->vacation_days_available_for_request, 0.001);
    }

    public function test_advance_is_settled_when_entitlement_is_generated(): void
    {
        // Le adelantaron 10; al cumplir el año genera 12 -> le quedan 2.
        $e = $this->employee(entitled: 0, hireDate: now()->subMonths(3)->toDateString());
        $this->service()->apply(10);

        $e->refresh();
        $e->update(['vacation_days_entitled' => 12, 'vacation_days_reserved' => 0]);

        $settled = $e->settleVacationAdvance();
        $e->refresh();

        $this->assertSame(10, $settled);
        $this->assertSame(0, (int) $e->vacation_days_advanced, 'la deuda queda saldada');
        $this->assertSame(10, (int) $e->vacation_days_used, 'los adelantados pasan a usados');
        $this->assertSame(2, $e->vacation_days_remaining, 'le quedan 2 disponibles');
    }

    public function test_advance_is_settled_partially_when_entitlement_is_not_enough(): void
    {
        $e = $this->employee(entitled: 0, hireDate: now()->subMonths(3)->toDateString());
        $this->service()->apply(10);

        $e->refresh();
        $e->update(['vacation_days_entitled' => 6, 'vacation_days_reserved' => 0]);

        $settled = $e->settleVacationAdvance();
        $e->refresh();

        $this->assertSame(6, $settled);
        $this->assertSame(4, (int) $e->vacation_days_advanced, 'queda debiendo el resto');
        $this->assertSame(6, (int) $e->vacation_days_used);
    }

    public function test_clear_releases_reserved_and_advanced(): void
    {
        $e = $this->employee(entitled: 16);
        $this->service()->apply(10);

        $this->service()->clear();
        $e->refresh();

        $this->assertSame(0, (int) $e->vacation_days_reserved);
        $this->assertSame(0, (int) $e->vacation_days_advanced);
        $this->assertSame(16, $e->vacation_days_remaining);
    }

    // ---- Pantalla de configuracion ---------------------------------------

    public function test_settings_page_renders_with_the_preview(): void
    {
        $this->employee(entitled: 16);
        $this->actingAsAdmin();

        $this->get(route('settings.december-vacation', ['dias' => 10]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/DecemberVacation')
                ->where('previewDays', 10)
                ->where('preview.total', 1)
                ->where('preview.con_derecho', 1));
    }

    public function test_admin_can_apply_from_the_screen(): void
    {
        $e = $this->employee(entitled: 16);
        $this->actingAsAdmin();

        $this->post(route('settings.december-vacation.apply'), ['days' => 10])
            ->assertRedirect(route('settings.december-vacation'));

        $this->assertSame(10, (int) $e->fresh()->vacation_days_reserved);
    }

    public function test_apply_rejects_invalid_day_counts(): void
    {
        $this->actingAsAdmin();

        $this->post(route('settings.december-vacation.apply'), ['days' => 0])
            ->assertSessionHasErrors('days');
    }

    public function test_a_user_without_settings_permission_cannot_apply(): void
    {
        $this->actingAs($this->supervisorUser());

        $this->get(route('settings.december-vacation'))->assertForbidden();
        $this->post(route('settings.december-vacation.apply'), ['days' => 10])->assertForbidden();
    }

    public function test_inactive_employees_are_not_touched(): void
    {
        $e = $this->employee(entitled: 16);
        $e->update(['status' => 'terminated']);

        $this->service()->apply(10);
        $e->refresh();

        $this->assertSame(0, (int) $e->vacation_days_reserved);
    }
}
