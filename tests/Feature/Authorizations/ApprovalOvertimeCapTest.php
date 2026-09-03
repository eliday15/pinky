<?php

namespace Tests\Feature\Authorizations;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Employee;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use PragmaRX\Google2FA\Google2FA;
use Tests\FeatureTestCase;

/**
 * Tope de checadas al APROBAR tiempo extra (petición de Luis 2026-07-08:
 * "a la hora de aprobar que solo me deje lo correcto").
 *
 * Al aprobar una autorización de TE por hora, las horas se topan al máximo
 * que respaldan las checadas del día (mismo cálculo que nómina y reporte:
 * excedente del umbral en fin de semana, detección contra horario con
 * escalera en día normal). Sin TE respaldado, la aprobación se bloquea.
 * El tope NO aplica a exentos de asistencia, a días con checada incompleta
 * ni a conceptos que no son por hora.
 */
class ApprovalOvertimeCapTest extends FeatureTestCase
{
    private const WEDNESDAY = '2026-06-03';

    private const SATURDAY = '2026-06-06';

    /**
     * Privileged approver with a usable (encrypted-secret) 2FA device — the
     * shared harness device stores a plaintext secret that TwoFactorService
     * cannot decrypt. Returns [User, validTotpCode].
     *
     * @return array{0: User, 1: string}
     */
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

    /** Empleado activo con horario 08:00–17:00. */
    private function employee(array $attrs = []): Employee
    {
        $schedule = Schedule::factory()->create([
            'entry_time' => '08:00',
            'exit_time' => '17:00',
            'daily_work_hours' => 8,
            'break_minutes' => 60,
        ]);

        return Employee::factory()->create(array_merge([
            'status' => 'active',
            'schedule_id' => $schedule->id,
            'hire_date' => '2025-01-01',
        ], $attrs));
    }

    private function heType(): CompensationType
    {
        $existing = CompensationType::where('code', 'HE')->first();
        $attributes = [
            'calculation_type' => 'fixed',
            'percentage_value' => null,
            'fixed_amount' => 50.00,
            'is_active' => true,
            'application_mode' => CompensationType::APPLICATION_PER_HOUR,
            'authorization_type' => Authorization::TYPE_OVERTIME,
        ];

        if ($existing) {
            $existing->update($attributes);

            return $existing->fresh();
        }

        return CompensationType::factory()->create(array_merge(['code' => 'HE'], $attributes));
    }

    private function pendingOvertime(Employee $employee, string $date, float $hours): Authorization
    {
        return Authorization::create([
            'employee_id' => $employee->id,
            'requested_by' => User::factory()->create()->id,
            'type' => Authorization::TYPE_OVERTIME,
            'compensation_type_id' => $this->heType()->id,
            'date' => $date,
            'hours' => $hours,
            'reason' => 'TE por aprobar',
            'status' => Authorization::STATUS_PENDING,
        ]);
    }

    public function test_approve_clamps_hours_to_timecard(): void
    {
        [$approver, $code] = $this->approver();
        $employee = $this->employee();

        // Salida 19:00 con horario hasta 17:00 → 120 min → 2.0 h detectadas.
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => self::WEDNESDAY,
            'check_in' => '08:00:00',
            'check_out' => '19:00:00',
            'status' => 'present',
        ]);

        $auth = $this->pendingOvertime($employee, self::WEDNESDAY, 5.0);

        $this->actingAs($approver)->post(route('authorizations.approve', $auth), ['two_factor_code' => $code])
            ->assertRedirect()
            ->assertSessionHas('success', fn ($msg) => str_contains($msg, 'ajustadas a 2.00'));

        $auth->refresh();
        $this->assertSame(Authorization::STATUS_APPROVED, $auth->status);
        $this->assertEqualsWithDelta(2.0, (float) $auth->hours, 0.01, 'las 5 h se topan a las 2 del timecard');
    }

    public function test_approve_blocks_when_timecard_has_no_overtime(): void
    {
        [$approver, $code] = $this->approver();
        $employee = $this->employee();

        // Día exacto al horario: nada de TE respaldado.
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => self::WEDNESDAY,
            'check_in' => '08:00:00',
            'check_out' => '17:00:00',
            'status' => 'present',
        ]);

        $auth = $this->pendingOvertime($employee, self::WEDNESDAY, 1.0);

        $this->actingAs($approver)->post(route('authorizations.approve', $auth), ['two_factor_code' => $code])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(Authorization::STATUS_PENDING, $auth->fresh()->status, 'sin TE respaldado no se aprueba');
    }

    public function test_approve_weekend_clamps_to_threshold_excess(): void
    {
        // Sábado 09:07–18:02 = 8 h 55 CORRIDAS (sin descontar comida, Dani
        // 2026-07-08) − 7 de umbral = 1 h 55 → escalera → 2.0 h de tope.
        [$approver, $code] = $this->approver();
        $employee = $this->employee();

        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => self::SATURDAY,
            'check_in' => '09:07:00',
            'check_out' => '18:02:00',
            'status' => 'present',
            'is_weekend_work' => true,
            'worked_hours' => 8,
            'overtime_hours' => 0.42,
        ]);

        $auth = $this->pendingOvertime($employee, self::SATURDAY, 3.0);

        $this->actingAs($approver)->post(route('authorizations.approve', $auth), ['two_factor_code' => $code])->assertRedirect();

        $this->assertEqualsWithDelta(2.0, (float) $auth->fresh()->hours, 0.01, 'solo el excedente corrido de las 7 h del finde');
    }

    public function test_exempt_employee_is_not_capped(): void
    {
        [$approver, $code] = $this->approver();
        $employee = $this->employee(['is_attendance_exempt' => true, 'zkteco_user_id' => null]);

        $auth = $this->pendingOvertime($employee, self::WEDNESDAY, 3.0);

        $this->actingAs($approver)->post(route('authorizations.approve', $auth), ['two_factor_code' => $code])->assertRedirect();

        $auth->refresh();
        $this->assertSame(Authorization::STATUS_APPROVED, $auth->status);
        $this->assertEqualsWithDelta(3.0, (float) $auth->hours, 0.01, 'exento: su aprobación es la evidencia');
    }

    public function test_incomplete_punches_are_not_capped(): void
    {
        // Entrada sin salida: no hay timecard medible — se aprueba tal cual
        // (la nómina no lo paga hasta corregir la checada).
        [$approver, $code] = $this->approver();
        $employee = $this->employee();

        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => self::WEDNESDAY,
            'check_in' => '07:07:00',
            'check_out' => null,
            'status' => 'present',
        ]);

        $auth = $this->pendingOvertime($employee, self::WEDNESDAY, 1.5);

        $this->actingAs($approver)->post(route('authorizations.approve', $auth), ['two_factor_code' => $code])->assertRedirect();

        $auth->refresh();
        $this->assertSame(Authorization::STATUS_APPROVED, $auth->status);
        $this->assertEqualsWithDelta(1.5, (float) $auth->hours, 0.01);
    }

    public function test_per_day_concept_is_not_capped(): void
    {
        [$approver, $code] = $this->approver();
        $employee = $this->employee();

        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => self::SATURDAY,
            'check_in' => '09:00:00',
            'check_out' => '15:00:00',
            'status' => 'present',
            'is_weekend_work' => true,
            'worked_hours' => 5,
            'overtime_hours' => 0,
        ]);

        $fin = CompensationType::where('code', 'FIN')->first()
            ?? CompensationType::factory()->create(['code' => 'FIN']);
        $fin->update([
            'application_mode' => CompensationType::APPLICATION_PER_DAY,
            'authorization_type' => Authorization::TYPE_SPECIAL,
            'attendance_pull_rule' => CompensationType::PULL_RULE_WEEKEND,
            'is_active' => true,
        ]);

        $auth = Authorization::create([
            'employee_id' => $employee->id,
            'requested_by' => User::factory()->create()->id,
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $fin->id,
            'date' => self::SATURDAY,
            'hours' => 1.0,
            'reason' => 'FIN',
            'status' => Authorization::STATUS_PENDING,
        ]);

        $this->actingAs($approver)->post(route('authorizations.approve', $auth), ['two_factor_code' => $code])->assertRedirect();

        $auth->refresh();
        $this->assertSame(Authorization::STATUS_APPROVED, $auth->status, 'los conceptos por día no se topan por horas');
        $this->assertEqualsWithDelta(1.0, (float) $auth->hours, 0.01);
    }

    public function test_bulk_approve_clamps_and_reports_adjustments(): void
    {
        [$approver, $code] = $this->approver();
        $employee = $this->employee();

        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => self::WEDNESDAY,
            'check_in' => '08:00:00',
            'check_out' => '19:00:00',
            'status' => 'present',
        ]);

        $over = $this->pendingOvertime($employee, self::WEDNESDAY, 5.0);
        $exempt = $this->employee(['is_attendance_exempt' => true, 'zkteco_user_id' => null]);
        $clean = $this->pendingOvertime($exempt, self::WEDNESDAY, 1.0);

        $this->actingAs($approver)->post(route('authorizations.bulkApprove'), ['ids' => [$over->id, $clean->id], 'two_factor_code' => $code])
            ->assertRedirect()
            ->assertSessionHas('success', fn ($msg) => str_contains($msg, 'ajustadas al tope de checadas'));

        $this->assertEqualsWithDelta(2.0, (float) $over->fresh()->hours, 0.01);
        $this->assertEqualsWithDelta(1.0, (float) $clean->fresh()->hours, 0.01);
        $this->assertSame(Authorization::STATUS_APPROVED, $over->fresh()->status);
        $this->assertSame(Authorization::STATUS_APPROVED, $clean->fresh()->status);
    }

    public function test_manual_approval_accepts_morning_window_backed_by_calendar_date_punch_on_previous_velada(): void
    {
        [$approver, $code] = $this->approver();
        $employee = $this->employee();
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-08-27',
            'check_in' => '08:00:00',
            'check_out' => '05:00:16',
            'raw_punches' => [
                ['date' => '2026-08-27', 'time' => '22:01:42', 'type' => 'punch'],
                ['date' => '2026-08-28', 'time' => '05:00:16', 'type' => 'out'],
            ],
        ]);
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-08-28',
            'check_in' => '07:58:50',
            'check_out' => '17:00:00',
            'raw_punches' => [
                ['date' => '2026-08-28', 'time' => '07:58:50', 'type' => 'in'],
                ['date' => '2026-08-28', 'time' => '17:00:00', 'type' => 'out'],
            ],
        ]);
        $auth = $this->pendingOvertime($employee, '2026-08-28', 3.0);
        $auth->update(['start_time' => '05:00', 'end_time' => '08:00']);

        $this->actingAs($approver)
            ->post(route('authorizations.approve', $auth), ['two_factor_code' => $code])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(Authorization::STATUS_APPROVED, $auth->fresh()->status);
        $this->assertEqualsWithDelta(3.0, (float) $auth->fresh()->hours, 0.01);
    }

    public function test_carried_morning_hours_do_not_allow_unbacked_late_window(): void
    {
        [$approver, $code] = $this->approver();
        $employee = $this->employee();
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-08-27',
            'check_in' => '08:00:00',
            'check_out' => '05:00:16',
            'raw_punches' => [
                ['date' => '2026-08-27', 'time' => '22:01:42', 'type' => 'punch'],
                ['date' => '2026-08-28', 'time' => '05:00:16', 'type' => 'out'],
            ],
        ]);
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-08-28',
            'check_in' => '08:00:00',
            'check_out' => '17:00:00',
            'raw_punches' => [],
        ]);
        $auth = $this->pendingOvertime($employee, '2026-08-28', 3.0);
        $auth->update(['start_time' => '17:00', 'end_time' => '20:00']);

        $this->actingAs($approver)
            ->post(route('authorizations.approve', $auth), ['two_factor_code' => $code])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(Authorization::STATUS_PENDING, $auth->fresh()->status);
    }
}
