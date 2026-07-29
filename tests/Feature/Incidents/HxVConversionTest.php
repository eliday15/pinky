<?php

namespace Tests\Feature\Incidents;

use App\Models\Employee;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\PayrollPeriod;
use App\Models\TwoFactorDevice;
use App\Models\User;
use App\Services\PayrollCalculatorService;
use Illuminate\Support\Facades\Crypt;
use PragmaRX\Google2FA\Google2FA;
use Tests\FeatureTestCase;

/**
 * Casilla "A cuenta de horas (HxV)" al capturar Vacaciones (Dani 2026-07-29).
 *
 * Al aprobar una Vacación marcada HxV, los días NO se consumen como vacación:
 * se acreditan a la bolsa de horas (1 día = 8 h) para gastarse luego por horas
 * (el permiso `uses_vacation_hours` ya debita la bolsa solo). El vale de
 * conversión es invisible para la nómina (no es tiempo tomado). Así RRHH ya no
 * hace la conversión manual: la bolsa queda solo de consulta.
 */
class HxVConversionTest extends FeatureTestCase
{
    private function calculator(): PayrollCalculatorService
    {
        return app(PayrollCalculatorService::class);
    }

    private function vacType(): IncidentType
    {
        return IncidentType::firstOrCreate(
            ['code' => 'VAC'],
            [
                'name' => 'Vacaciones',
                'category' => 'vacation',
                'is_paid' => true,
                'deducts_vacation' => true,
                'requires_approval' => true,
            ],
        );
    }

    private function hvType(): IncidentType
    {
        return IncidentType::factory()->create([
            'name' => 'Horas a cuenta de vacaciones',
            'code' => 'hv',
            'category' => 'permission',
            'requires_approval' => false,
            'deducts_vacation' => false,
            'uses_vacation_hours' => true,
            'has_time_range' => true,
            'affects_attendance' => true,
        ]);
    }

    private function employee(int $entitled = 6, int $usedDays = 0, float $credited = 0, float $usedHours = 0): Employee
    {
        return Employee::factory()->create([
            'status' => 'active',
            'vacation_days_entitled' => $entitled,
            'vacation_days_used' => $usedDays,
            'vacation_days_reserved' => 0,
            'vacation_days_advanced' => 0,
            'vacation_hours_credited' => $credited,
            'vacation_hours_used' => $usedHours,
        ]);
    }

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

    public function test_approving_a_vacation_with_hxv_credits_the_bank_not_the_days(): void
    {
        $admin = $this->actingAsAdmin();
        $emp = $this->employee(entitled: 6);
        $type = $this->vacType();

        // Captura de 1 día (lunes) marcada como HxV → queda pendiente.
        $this->post(route('incidents.store'), [
            'employee_id' => $emp->id,
            'incident_type_id' => $type->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-01',
            'converts_to_vacation_hours' => true,
        ])->assertRedirect(route('incidents.index'));

        $incident = Incident::firstWhere('employee_id', $emp->id);
        $this->assertTrue((bool) $incident->converts_to_vacation_hours);
        $this->assertSame('pending', $incident->status);

        $code = $this->attachRealTwoFactor($admin);
        $this->post(route('incidents.approve', $incident), ['two_factor_code' => $code])
            ->assertSessionHasNoErrors();

        $emp->refresh();
        $this->assertEqualsWithDelta(8.0, (float) $emp->vacation_hours_credited, 0.01, '1 día = 8 h a la bolsa');
        $this->assertSame(0, (int) $emp->vacation_days_used, 'el día NO se consume como vacación');
        $this->assertEqualsWithDelta(8.0, $emp->vacation_hours_bank_remaining, 0.01);
        $this->assertTrue($emp->usesVacationHoursBank(), 'inscrito en la bolsa automáticamente');
    }

    public function test_conversion_day_count_ignores_the_saturday_rule(): void
    {
        $this->actingAsAdmin();
        $emp = $this->employee(entitled: 10);
        $type = $this->vacType();

        // Lun–Vie: 5 hábiles. Una vacación normal sumaría el sábado (regla de
        // 3+ días) = 6; el vale de conversión NO, cuenta 5 (no toma la semana).
        $this->post(route('incidents.store'), [
            'employee_id' => $emp->id,
            'incident_type_id' => $type->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-05',
            'converts_to_vacation_hours' => true,
        ])->assertRedirect(route('incidents.index'));

        $incident = Incident::firstWhere('employee_id', $emp->id);
        $this->assertSame(5, (int) $incident->days_count, 'sin regla del sábado en conversiones');
    }

    public function test_approve_rejects_converting_more_days_than_available(): void
    {
        $admin = $this->actingAsAdmin();
        $emp = $this->employee(entitled: 1); // solo 1 día convertible
        $type = $this->vacType();

        // Lun–Mar: 2 días hábiles > 1 disponible.
        $this->post(route('incidents.store'), [
            'employee_id' => $emp->id,
            'incident_type_id' => $type->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
            'converts_to_vacation_hours' => true,
        ])->assertRedirect(route('incidents.index'));

        $incident = Incident::firstWhere('employee_id', $emp->id);
        $code = $this->attachRealTwoFactor($admin);
        $this->post(route('incidents.approve', $incident), ['two_factor_code' => $code])
            ->assertSessionHasErrors('saldo');

        $this->assertSame('pending', $incident->fresh()->status);
        $this->assertEqualsWithDelta(0.0, (float) $emp->fresh()->vacation_hours_credited, 0.01);
    }

    public function test_deleting_a_converting_incident_refunds_the_bank(): void
    {
        $admin = $this->actingAsAdmin();
        $emp = $this->employee(entitled: 6);
        $type = $this->vacType();

        $this->post(route('incidents.store'), [
            'employee_id' => $emp->id,
            'incident_type_id' => $type->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-01',
            'converts_to_vacation_hours' => true,
        ]);
        $incident = Incident::firstWhere('employee_id', $emp->id);
        $code = $this->attachRealTwoFactor($admin);
        $this->post(route('incidents.approve', $incident), ['two_factor_code' => $code]);

        $this->assertEqualsWithDelta(8.0, (float) $emp->fresh()->vacation_hours_credited, 0.01);

        $this->delete(route('incidents.destroy', $incident))->assertRedirect();

        $this->assertEqualsWithDelta(0.0, (float) $emp->fresh()->vacation_hours_credited, 0.01, 'se devuelven las horas a la bolsa');
    }

    public function test_a_converting_incident_is_invisible_to_payroll(): void
    {
        $emp = $this->employee(entitled: 10);
        $type = $this->vacType();
        $weekly = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
        ]);

        // Vale de conversión aprobado cubriendo la semana.
        Incident::factory()->create([
            'employee_id' => $emp->id,
            'incident_type_id' => $type->id,
            'status' => 'approved',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-05',
            'days_count' => 5,
            'converts_to_vacation_hours' => true,
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($weekly, $emp);
        $this->assertSame(0, (int) $entry->vacation_days_paid, 'un vale de conversión no paga días de vacación');
    }

    public function test_a_normal_vacation_still_counts_in_payroll(): void
    {
        // Control: sin la casilla, la vacación se cuenta como siempre.
        $emp = $this->employee(entitled: 10);
        $type = $this->vacType();
        $weekly = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
        ]);

        Incident::factory()->create([
            'employee_id' => $emp->id,
            'incident_type_id' => $type->id,
            'status' => 'approved',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-05',
            'days_count' => 5,
            'converts_to_vacation_hours' => false,
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($weekly, $emp);
        // La nómina recuenta con la regla del sábado (5 hábiles + sábado) = 6.
        $this->assertSame(6, (int) $entry->vacation_days_paid, 'la vacación normal sí cuenta');
    }

    public function test_permit_spends_the_bank_credited_by_conversion(): void
    {
        // End-to-end: convertir 1 día (bolsa 8 h) y luego gastar 2 h con el
        // permiso hv → la bolsa se debita sola (queda 6 h). Ya no hay captura
        // manual de bolsa de por medio.
        $admin = $this->actingAsAdmin();
        $emp = $this->employee(entitled: 6);
        $vac = $this->vacType();
        $hv = $this->hvType();

        $this->post(route('incidents.store'), [
            'employee_id' => $emp->id,
            'incident_type_id' => $vac->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-01',
            'converts_to_vacation_hours' => true,
        ]);
        $conversion = Incident::firstWhere('employee_id', $emp->id);
        $code = $this->attachRealTwoFactor($admin);
        $this->post(route('incidents.approve', $conversion), ['two_factor_code' => $code]);

        $this->assertEqualsWithDelta(8.0, $emp->fresh()->vacation_hours_bank_remaining, 0.01);

        // El permiso hv (auto-aprobado) toma de la bolsa en automático.
        $this->post(route('incidents.store'), [
            'employee_id' => $emp->id,
            'incident_type_id' => $hv->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-10',
            'hours' => 2,
        ])->assertRedirect(route('incidents.index'));

        $emp->refresh();
        $this->assertEqualsWithDelta(2.0, (float) $emp->vacation_hours_used, 0.01);
        $this->assertEqualsWithDelta(6.0, $emp->vacation_hours_bank_remaining, 0.01);
    }
}
