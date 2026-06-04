<?php

namespace Tests\Feature\Payroll;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
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
 * Fase E (DECISIONES_NEGOCIO_2026-06-04.md §7, "Auto + marcar según estado"):
 * cuando cambian datos que afectan el pago (incidencias, autorizaciones,
 * checadas), los periodos en DRAFT se recalculan automáticamente, los
 * periodos en REVIEW/APPROVED se marcan "requiere recálculo" (y solo un
 * recálculo explícito limpia la marca, regresando a review), y los PAID son
 * inmutables. La edición manual de checadas usa la fórmula canónica del sync
 * preservando el status del editor.
 */
class PayrollInvalidationTest extends FeatureTestCase
{
    private function calculator(): PayrollCalculatorService
    {
        return app(PayrollCalculatorService::class);
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

    private function employee(): Employee
    {
        return Employee::factory()->create([
            'status' => 'active',
            'hourly_rate' => 100.00,
            'vacation_premium_percentage' => 0,
        ]);
    }

    private function typeWithCode(string $code, array $attributes): IncidentType
    {
        $existing = IncidentType::where('code', $code)->first();

        if ($existing) {
            $existing->update($attributes);

            return $existing->fresh();
        }

        return IncidentType::factory()->create(array_merge(['code' => $code], $attributes));
    }

    private function vacType(): IncidentType
    {
        return $this->typeWithCode('VAC', [
            'category' => 'vacation',
            'is_paid' => true,
            'deducts_vacation' => false,
            'count_mode' => IncidentType::COUNT_WORKING_DAYS,
            'requires_approval' => true,
        ]);
    }

    private function pendingVacation(Employee $employee): Incident
    {
        return Incident::factory()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $this->vacType()->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-05',
            'days_count' => 5,
            'status' => 'pending',
        ]);
    }

    private function monthlyPeriod(string $state = 'draft'): PayrollPeriod
    {
        $factory = PayrollPeriod::factory()->monthly();

        $factory = match ($state) {
            'approved' => $factory->approved(),
            'paid' => $factory->paid(),
            default => $factory,
        };

        return $factory->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);
    }

    public function test_draft_period_recalculates_automatically_on_incident_approval(): void
    {
        $employee = $this->employee();
        $period = $this->monthlyPeriod();
        $entry = $this->calculator()->calculateEmployeePayroll($period, $employee);
        $this->assertEqualsWithDelta(0.00, (float) $entry->vacation_pay, 0.01);

        $incident = $this->pendingVacation($employee);

        $admin = $this->actingAsAdmin();
        $code = $this->attachRealTwoFactor($admin);

        $this->post(route('incidents.approve', $incident), ['two_factor_code' => $code])
            ->assertRedirect();

        $this->assertEqualsWithDelta(4000.00, (float) $entry->fresh()->vacation_pay, 0.01, 'el draft se recalculó solo: 5 días × 800');
        $this->assertSame('draft', $period->fresh()->status);
        $this->assertFalse((bool) $period->fresh()->requires_recalculation);
    }

    public function test_approved_period_is_flagged_not_silently_recalculated(): void
    {
        $employee = $this->employee();
        $period = $this->monthlyPeriod('approved');
        $entry = $this->calculator()->calculateEmployeePayroll($period, $employee);

        $incident = $this->pendingVacation($employee);

        $admin = $this->actingAsAdmin();
        $code = $this->attachRealTwoFactor($admin);

        $this->post(route('incidents.approve', $incident), ['two_factor_code' => $code])
            ->assertRedirect();

        $period->refresh();

        $this->assertEqualsWithDelta(0.00, (float) $entry->fresh()->vacation_pay, 0.01, 'una nómina aprobada no cambia en silencio');
        $this->assertTrue((bool) $period->requires_recalculation, 'queda marcada para que un admin decida');
        $this->assertNotNull($period->recalculation_flagged_at);
        $this->assertSame('approved', $period->status);
    }

    public function test_paid_period_is_untouched(): void
    {
        $employee = $this->employee();
        $period = $this->monthlyPeriod('paid');
        $entry = $this->calculator()->calculateEmployeePayroll($period, $employee);
        // calculateEmployeePayroll directo escribe el entry, pero el flujo de
        // invalidación jamás debe tocar un periodo pagado.

        $incident = $this->pendingVacation($employee);

        $admin = $this->actingAsAdmin();
        $code = $this->attachRealTwoFactor($admin);

        $this->post(route('incidents.approve', $incident), ['two_factor_code' => $code])
            ->assertRedirect();

        $period->refresh();

        $this->assertFalse((bool) $period->requires_recalculation, 'pagado: ni marca ni recálculo');
        $this->assertEqualsWithDelta(0.00, (float) $entry->fresh()->vacation_pay, 0.01);
    }

    public function test_explicit_recalculation_clears_flag_and_returns_to_review(): void
    {
        $employee = $this->employee();
        $period = $this->monthlyPeriod('approved');
        $this->calculator()->calculateEmployeePayroll($period, $employee);
        $period->update(['requires_recalculation' => true, 'recalculation_flagged_at' => now()]);

        $this->actingAsAdmin();

        $this->post(route('payroll.calculate', $period))->assertRedirect();

        $period->refresh();

        $this->assertSame('review', $period->status, 'recalcular invalida la aprobación previa: vuelve a revisión');
        $this->assertFalse((bool) $period->requires_recalculation);
        $this->assertNull($period->recalculation_flagged_at);
    }

    public function test_approved_period_without_flag_cannot_be_recalculated(): void
    {
        $period = $this->monthlyPeriod('approved');

        $this->actingAsAdmin();

        $this->post(route('payroll.calculate', $period))->assertRedirect();

        $this->assertSame('approved', $period->fresh()->status, 'sin marca, una aprobada no se recalcula');
    }

    public function test_authorization_approval_refreshes_draft_entry(): void
    {
        $employee = $this->employee();

        // 08:00-19:00 con break 60 → 120 min extra → escalera 2.0h.
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-06-03',
            'check_in' => '08:00:00',
            'check_out' => '19:00:00',
            'actual_break_minutes' => 60,
            'status' => 'present',
        ]);

        $period = $this->monthlyPeriod();
        $entry = $this->calculator()->calculateEmployeePayroll($period, $employee);
        $this->assertEqualsWithDelta(0.00, (float) $entry->overtime_pay, 0.01, 'sin autorización no se paga HE');

        $requester = User::factory()->create();
        $authorization = Authorization::create([
            'employee_id' => $employee->id,
            'requested_by' => $requester->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-03',
            'hours' => 2.0,
            'reason' => 'horas extra',
            'status' => Authorization::STATUS_PENDING,
        ]);

        $admin = $this->actingAsAdmin();
        $code = $this->attachRealTwoFactor($admin);

        $this->post(route('authorizations.approve', $authorization), ['two_factor_code' => $code])
            ->assertRedirect();

        // 2h autorizadas × 100 × 1.5 = 300, reflejado sin recalcular a mano.
        $this->assertEqualsWithDelta(300.00, (float) $entry->fresh()->overtime_pay, 0.01);
    }

    public function test_manual_attendance_edit_preserves_status_and_updates_derived_metrics(): void
    {
        $employee = $this->employee();

        // Retardo real (08:40 vs 08:00, tolerancia 10) que el editor decide
        // conservar como 'late' aunque corrige la salida a 19:00.
        $record = AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-06-03',
            'check_in' => '08:40:00',
            'check_out' => '17:00:00',
            'actual_break_minutes' => 60,
            'status' => 'late',
            'late_minutes' => 30,
        ]);

        $requester = User::factory()->create();
        Authorization::create([
            'employee_id' => $employee->id,
            'requested_by' => $requester->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-03',
            'hours' => 2.0,
            'reason' => 'he',
            'status' => Authorization::STATUS_APPROVED,
        ]);

        $period = $this->monthlyPeriod();
        $this->calculator()->calculateEmployeePayroll($period, $employee);

        $this->actingAsAdmin();

        $this->put(route('attendance.update', $record), [
            'check_in' => '08:40',
            'check_out' => '19:00',
            'status' => 'late',
            'manual_edit_reason' => 'olvidó checar la salida real',
        ])->assertRedirect();

        $record->refresh();

        // 08:40-19:00 − 60 break = 9h20 → 80 min extra → escalera 1.0h.
        $this->assertSame('late', $record->status, 'manual edit wins: el status del editor se preserva');
        $this->assertEqualsWithDelta(8.00, (float) $record->worked_hours, 0.01, 'horas regulares canónicas');
        $this->assertEqualsWithDelta(1.33, (float) $record->overtime_hours, 0.02, 'extra exacto detectado');
        $this->assertEqualsWithDelta(1.00, (float) $record->overtime_authorized_hours, 0.01, 'autorizadas recalculadas (escalera), ya no obsoletas');
        $this->assertSame(30, (int) $record->late_minutes, 'retardo recalculado con la fórmula canónica');

        // Y la nómina draft quedó al día: 1.0h × 100 × 1.5 = 150.
        $entry = $period->entries()->where('employee_id', $employee->id)->first();
        $this->assertEqualsWithDelta(150.00, (float) $entry->overtime_pay, 0.01);
    }
}
