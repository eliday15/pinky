<?php

namespace Tests\Feature\Authorizations;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\Employee;
use App\Models\Schedule;
use Illuminate\Support\Facades\Crypt;
use PragmaRX\Google2FA\Google2FA;
use Tests\FeatureTestCase;

/**
 * Captura de TE partida en parte aprobada + excedente pendiente (Dani
 * 2026-11-11, Luis Ortega #11994): quien capturó borró el excedente pensando
 * que retiraba la captura y la parte aprobada siguió viva. Al borrar o
 * rechazar el excedente se avisa y se puede retirar también la parte aprobada.
 */
class OvertimeSplitWithdrawalTest extends FeatureTestCase
{
    /** Captura 16:30–20:00 con salida 19:06: aprueba 16:30–19:06 y deja 19:06–20:00 pendiente. */
    private function splitCapture(): array
    {
        $emp = Employee::factory()->create([
            'schedule_id' => Schedule::factory()->create(['entry_time' => '08:00', 'exit_time' => '16:30'])->id,
        ]);
        AttendanceRecord::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => '2026-06-08',
            'check_in' => '08:00:00',
            'check_out' => '19:06:00',
            'overtime_hours' => 2.6,
        ]);

        $this->post(route('authorizations.store'), [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-08',
            'start_time' => '16:30',
            'end_time' => '20:00',
            'hours' => 3.5,
            'reason' => 'captura con fecha equivocada',
        ]);

        $backed = Authorization::where('employee_id', $emp->id)->where('status', Authorization::STATUS_APPROVED)->firstOrFail();
        $excess = Authorization::where('employee_id', $emp->id)->where('is_unbacked_extra', true)->firstOrFail();
        $this->assertSame($backed->id, $excess->generated_from_authorization_id);

        return [$backed, $excess];
    }

    /** Admin con 2FA real (rechazar exige código). Devuelve [usuario, código]. */
    private function adminWithCode(): array
    {
        $user = $this->createUser('admin', [], withTwoFactor: false);
        $secret = (new Google2FA)->generateSecretKey();
        $user->twoFactorDevices()->create([
            'name' => 'TestDevice',
            'secret' => Crypt::encryptString($secret),
            'confirmed_at' => now(),
        ]);

        return [$user, (new Google2FA)->getCurrentOtp($secret)];
    }

    public function test_deleting_excess_warns_that_backed_part_stays_approved(): void
    {
        $this->actingAsSupervisor();
        [$backed, $excess] = $this->splitCapture();

        $this->delete(route('authorizations.destroy', $excess))
            ->assertRedirect(route('authorizations.index'))
            ->assertSessionHas('warning', fn ($msg) => str_contains($msg, '#'.$backed->id) && str_contains($msg, 'sigue aprobada'));

        $this->assertDatabaseMissing('authorizations', ['id' => $excess->id]);
        $this->assertSame(Authorization::STATUS_APPROVED, $backed->fresh()->status);
    }

    public function test_capturer_can_withdraw_backed_part_when_deleting_excess(): void
    {
        $this->actingAsSupervisor();
        [$backed, $excess] = $this->splitCapture();

        $this->delete(route('authorizations.destroy', $excess), ['withdraw_backed_part' => true])
            ->assertSessionHas('success', fn ($msg) => str_contains($msg, 'También se rechazó'));

        $this->assertSame(Authorization::STATUS_REJECTED, $backed->fresh()->status);
        $this->assertStringContainsString('Captura retirada', $backed->fresh()->rejection_reason);
    }

    public function test_another_supervisor_cannot_withdraw_someone_elses_backed_part(): void
    {
        $this->actingAsSupervisor();
        [$backed, $excess] = $this->splitCapture();

        // Otro capturista sin permiso de rechazar lo aprobado: el excedente se
        // puede rechazar solo si la política lo deja; la parte aprobada no.
        $other = $this->actingAsSupervisor();
        $service = app(\App\Services\OvertimeSplitWithdrawalService::class);
        $this->assertFalse($service->canWithdraw($other, $backed));
    }

    public function test_admin_rejecting_excess_can_reject_backed_part_too(): void
    {
        $this->actingAsSupervisor();
        [$backed, $excess] = $this->splitCapture();

        [$admin, $code] = $this->adminWithCode();
        $this->actingAs($admin);
        $this->post(route('authorizations.reject', $excess), [
            'two_factor_code' => $code,
            'rejection_reason' => 'fecha equivocada',
            'withdraw_backed_part' => true,
        ])->assertSessionHas('success');

        $this->assertSame(Authorization::STATUS_REJECTED, $excess->fresh()->status);
        $this->assertSame(Authorization::STATUS_REJECTED, $backed->fresh()->status);
    }

    public function test_admin_rejecting_only_excess_keeps_backed_part_and_warns(): void
    {
        $this->actingAsSupervisor();
        [$backed, $excess] = $this->splitCapture();

        [$admin, $code] = $this->adminWithCode();
        $this->actingAs($admin);
        $this->post(route('authorizations.reject', $excess), ['two_factor_code' => $code, 'rejection_reason' => 'no se hizo'])
            ->assertSessionHas('warning');

        $this->assertSame(Authorization::STATUS_APPROVED, $backed->fresh()->status);
    }

    public function test_show_page_exposes_backed_part_of_excess(): void
    {
        $this->actingAsSupervisor();
        [$backed, $excess] = $this->splitCapture();

        $this->actingAsAdmin();
        $this->get(route('authorizations.show', $excess))
            ->assertInertia(fn ($page) => $page
                ->where('splitBackedPart.id', $backed->id)
                ->where('splitBackedPart.can_withdraw', true));
    }
}
