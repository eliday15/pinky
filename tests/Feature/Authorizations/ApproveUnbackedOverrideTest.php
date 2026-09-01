<?php

namespace Tests\Feature\Authorizations;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\Employee;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use PragmaRX\Google2FA\Google2FA;
use Tests\FeatureTestCase;

/**
 * Aprobar TE como "extra fuera de checada" desde la UI (Elias 2026-08-12,
 * "soluciones auto-servibles").
 *
 * Pagar tiempo extra que el reloj no respalda (trabajo real sin marca: la
 * entrada de madrugada que la regla de las 3 h descartó — casos Elsa #4488 y
 * Miriam #4525) exigía intervención por consola. Ahora el ADMIN lo decide
 * caso por caso con una casilla en el modal de aprobación: marca
 * is_unbacked_extra (paga completa sobre el tope y queda señalada en el
 * reporte) y el tope de checadas no aplica. Nadie más puede usarla, y un
 * excedente de split ya marcado tampoco se topa al aprobarse.
 */
class ApproveUnbackedOverrideTest extends FeatureTestCase
{
    public function test_detail_approval_form_submits_the_unbacked_override_field(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Authorizations/Show.vue'));

        $this->assertIsString($source);
        $this->assertMatchesRegularExpression(
            '/useForm\(\{.*?as_unbacked_extra:\s*false,.*?\}\)/s',
            $source,
            'El campo debe pertenecer al useForm que Inertia envía al endpoint de aprobación.'
        );
        $this->assertStringContainsString('v-model="approveForm.as_unbacked_extra"', $source);
        $this->assertStringContainsString('can?.approve_unbacked', $source);
    }

    /** Aprobador admin con 2FA usable. Devuelve [User, codigoTotp]. */
    private function approver(): array
    {
        $user = $this->createUser('admin', [], withTwoFactor: false);
        $secret = (new Google2FA)->generateSecretKey();
        $user->twoFactorDevices()->create([
            'name' => 'TestDevice',
            'secret' => Crypt::encryptString($secret),
            'confirmed_at' => now(),
        ]);
        $code = (new Google2FA)->getCurrentOtp($secret);

        return [$user, $code];
    }

    /** Empleado 08:00–17:00 con día SIN tiempo extra respaldado (tope = 0). */
    private function employeeWithFlatDay(): Employee
    {
        $employee = Employee::factory()->create([
            'status' => 'active',
            'schedule_id' => Schedule::factory()->create([
                'entry_time' => '08:00',
                'exit_time' => '17:00',
            ])->id,
        ]);
        AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-06-03', // miércoles
            'check_in' => '08:00:00',
            'check_out' => '17:00:00',
            'overtime_hours' => 0,
        ]);

        return $employee;
    }

    private function pendingMorningOvertime(Employee $employee): Authorization
    {
        return Authorization::create([
            'employee_id' => $employee->id,
            'requested_by' => User::factory()->create()->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-03',
            'start_time' => '05:00',
            'end_time' => '07:00',
            'hours' => 2.0,
            'reason' => 'madrugada real sin marca',
            'status' => Authorization::STATUS_PENDING,
        ]);
    }

    public function test_admin_approves_cap_blocked_overtime_as_unbacked_extra(): void
    {
        [$approver, $code] = $this->approver();
        $this->actingAs($approver);
        $emp = $this->employeeWithFlatDay();
        $auth = $this->pendingMorningOvertime($emp);

        $this->post(route('authorizations.approve', $auth), [
            'as_unbacked_extra' => '1',
            'two_factor_code' => $code,
        ])->assertSessionHas('success');

        $auth->refresh();
        $this->assertSame(Authorization::STATUS_APPROVED, $auth->status);
        $this->assertTrue((bool) $auth->is_unbacked_extra, 'queda señalada como extra fuera de checada');
        $this->assertEqualsWithDelta(2.0, (float) $auth->hours, 0.01, 'sin tope: pagan las 2 h completas');
    }

    public function test_without_the_flag_cap_blocked_approval_still_errors(): void
    {
        [$approver, $code] = $this->approver();
        $this->actingAs($approver);
        $emp = $this->employeeWithFlatDay();
        $auth = $this->pendingMorningOvertime($emp);

        $this->post(route('authorizations.approve', $auth), [
            'two_factor_code' => $code,
        ])->assertSessionHas('error');

        $auth->refresh();
        $this->assertSame(Authorization::STATUS_PENDING, $auth->status, 'sin casilla, el tope sigue bloqueando');
    }

    public function test_non_admin_cannot_use_the_flag(): void
    {
        $supervisor = $this->createUser('supervisor', [], withTwoFactor: false);
        $supervisor->givePermissionTo('authorizations.approve');
        $secret = (new Google2FA)->generateSecretKey();
        $supervisor->twoFactorDevices()->create([
            'name' => 'TestDevice',
            'secret' => Crypt::encryptString($secret),
            'confirmed_at' => now(),
        ]);
        $supEmployee = $this->attachEmployee($supervisor);
        $this->actingAs($supervisor);

        $emp = $this->employeeWithFlatDay();
        $emp->update(['supervisor_id' => $supEmployee->id]);
        $auth = $this->pendingMorningOvertime($emp);

        $this->post(route('authorizations.approve', $auth), [
            'as_unbacked_extra' => '1',
            'two_factor_code' => (new Google2FA)->getCurrentOtp($secret),
        ])->assertSessionHas('error');

        $auth->refresh();
        $this->assertSame(Authorization::STATUS_PENDING, $auth->status);
        $this->assertFalse((bool) $auth->is_unbacked_extra, 'el supervisor no puede marcarla');
    }

    public function test_split_excess_already_flagged_is_not_clamped_on_approval(): void
    {
        // Un excedente de split (is_unbacked_extra) existe PARA pagar sin
        // respaldo: el tope de checadas no debe recortarlo al aprobarse.
        [$approver, $code] = $this->approver();
        $this->actingAs($approver);
        $emp = $this->employeeWithFlatDay();

        $excess = Authorization::create([
            'employee_id' => $emp->id,
            'requested_by' => User::factory()->create()->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-03',
            'start_time' => '18:00',
            'end_time' => '19:30',
            'hours' => 1.5,
            'reason' => 'excedente de split',
            'status' => Authorization::STATUS_PENDING,
            'is_unbacked_extra' => true,
        ]);

        $this->post(route('authorizations.approve', $excess), [
            'two_factor_code' => $code,
        ])->assertSessionHas('success');

        $excess->refresh();
        $this->assertSame(Authorization::STATUS_APPROVED, $excess->status);
        $this->assertEqualsWithDelta(1.5, (float) $excess->hours, 0.01, 'el excedente no se recorta al tope');
    }
}
