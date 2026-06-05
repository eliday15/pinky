<?php

namespace Tests\Feature\Incidents;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\SystemSetting;
use App\Models\TwoFactorDevice;
use App\Models\User;
use App\Services\ZktecoSyncService;
use Illuminate\Support\Facades\Crypt;
use PragmaRX\Google2FA\Google2FA;
use Tests\FeatureTestCase;

/**
 * Fase B (auditoría C2 / DECISIONES §8): aprobar, crear (auto-aprobada) o
 * eliminar una incidencia recalcula los attendance_records cubiertos, igual
 * que ya hacía AuthorizationController::approve. Un permiso aprobado tarde
 * revierte la falta/retardo ya marcada por el sync; eliminarlo la restaura.
 *
 * También: la salida temprana solo escala a 'absent' cuando el flag
 * early_departure_is_absence está activo (el sync ahora respeta el mismo
 * setting que los reportes).
 */
class IncidentAttendanceRecalcTest extends FeatureTestCase
{
    /**
     * 2FA real (secreto cifrado) + código TOTP vigente, para el approve.
     *
     * @return string código vigente
     */
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
        // Schedule default: L-V 08:00-17:00, tolerancia 10 min.
        return Employee::factory()->create(['status' => 'active']);
    }

    /**
     * Los tipos PSA/PEN pueden existir ya (los crea la migración
     * migrate_admin_authorizations_to_incidents): actualizar o crear.
     */
    private function typeWithCode(string $code, array $attributes): IncidentType
    {
        $existing = IncidentType::where('code', $code)->first();

        if ($existing) {
            $existing->update($attributes);

            return $existing->fresh();
        }

        return IncidentType::factory()->create(array_merge(['code' => $code], $attributes));
    }

    private function penType(bool $requiresApproval = true): IncidentType
    {
        return $this->typeWithCode('PEN', [
            'category' => 'permission',
            'affects_attendance' => true,
            'has_time_range' => true,
            'is_paid' => true,
            'requires_approval' => $requiresApproval,
            'is_active' => true,
        ]);
    }

    private function psaType(): IncidentType
    {
        return $this->typeWithCode('PSA', [
            'category' => 'permission',
            'affects_attendance' => true,
            'has_time_range' => true,
            'is_paid' => true,
            'requires_approval' => true,
            'is_active' => true,
        ]);
    }

    /**
     * Retardo real: entrada 08:40 con horario 08:00 y tolerancia 10 → 30 min
     * tarde → status 'late' tal como lo dejó el sync.
     */
    private function lateRecord(Employee $employee, string $date = '2026-06-03'): AttendanceRecord
    {
        return AttendanceRecord::factory()->for($employee)->create([
            'work_date' => $date,
            'check_in' => '08:40:00',
            'check_out' => '17:00:00',
            'status' => 'late',
            'late_minutes' => 30,
        ]);
    }

    public function test_approving_entry_permission_reverts_late_status(): void
    {
        $employee = $this->employee();
        $record = $this->lateRecord($employee);
        $type = $this->penType();

        $incident = Incident::factory()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'start_date' => '2026-06-03',
            'end_date' => '2026-06-03',
            'days_count' => 1,
            'hours' => 1.0,
            'status' => 'pending',
        ]);

        $admin = $this->actingAsAdmin();
        $code = $this->attachRealTwoFactor($admin);

        $this->post(route('incidents.approve', $incident), ['two_factor_code' => $code])
            ->assertRedirect();

        $record->refresh();

        $this->assertSame('approved', $incident->fresh()->status);
        $this->assertSame('present', $record->status, 'el permiso de entrada aprobado revierte el retardo');
        $this->assertEqualsWithDelta(1.0, (float) $record->permission_hours, 0.01, 'las horas del permiso se suman al registro');
    }

    public function test_auto_approved_incident_recalculates_on_store(): void
    {
        $employee = $this->employee();
        $record = $this->lateRecord($employee);
        $type = $this->penType(requiresApproval: false);

        $this->actingAsAdmin();

        $this->post(route('incidents.store'), [
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'start_date' => '2026-06-03',
            'end_date' => '2026-06-03',
            'hours' => 1.0,
            'reason' => 'llegada autorizada',
        ])->assertRedirect(route('incidents.index'));

        $record->refresh();

        $this->assertSame('present', $record->status, 'la incidencia auto-aprobada surte efecto de inmediato');
    }

    public function test_deleting_approved_incident_is_forbidden_by_policy(): void
    {
        // IncidentPolicy::delete solo permite borrar PENDIENTES: una aprobada
        // ya surtió efecto (saldo de vacaciones, asistencia) y no se elimina
        // por la UI. El recálculo defensivo en destroy() queda para el caso
        // de que la policy cambie.
        $employee = $this->employee();
        $incident = Incident::factory()->approved()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $this->psaType()->id,
            'start_date' => '2026-06-03',
            'end_date' => '2026-06-03',
            'days_count' => 1,
        ]);

        $this->actingAsAdmin();

        $this->delete(route('incidents.destroy', $incident))->assertForbidden();
        $this->assertNull($incident->fresh()->deleted_at);
    }

    public function test_removing_exit_permission_restores_absence_on_recalc(): void
    {
        $employee = $this->employee();

        // Salida 15:00 con horario hasta 17:00 → 120 min temprano (> umbral 30).
        $record = AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-06-03',
            'check_in' => '08:00:00',
            'check_out' => '15:00:00',
            'status' => 'present', // el PSA aprobado lo mantenía presente
            'early_departure_minutes' => 120,
        ]);

        $incident = Incident::factory()->approved()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $this->psaType()->id,
            'start_date' => '2026-06-03',
            'end_date' => '2026-06-03',
            'days_count' => 1,
            'hours' => 2.0,
        ]);

        // Con el PSA vigente, el recálculo respeta el permiso.
        app(ZktecoSyncService::class)->recalculateAttendanceRecord($record);
        $this->assertNotSame('absent', $record->fresh()->status, 'con PSA aprobado no es falta');

        // Sin el PSA (soft-delete a nivel de datos), el recálculo restaura la falta.
        $incident->delete();
        app(ZktecoSyncService::class)->recalculateAttendanceRecord($record->fresh());

        $this->assertSame('absent', $record->fresh()->status, 'sin el permiso, la salida temprana excesiva vuelve a ser falta');
    }

    public function test_early_departure_only_escalates_to_absence_when_flag_enabled(): void
    {
        // Flag apagado: salir temprano NUNCA es falta (el sync ahora respeta
        // el mismo setting que los reportes).
        SystemSetting::factory()->create([
            'key' => 'early_departure_is_absence',
            'value' => '0',
            'type' => 'boolean',
            'group' => 'attendance',
        ]);

        $employee = $this->employee();
        $record = AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-06-03',
            'check_in' => '08:00:00',
            'check_out' => '15:00:00',
            'status' => 'absent', // como lo habría dejado el sync viejo
        ]);

        app(ZktecoSyncService::class)->recalculateAttendanceRecord($record);

        $record->refresh();

        $this->assertNotSame('absent', $record->status, 'con el flag apagado la salida temprana no es falta');
        $this->assertGreaterThan(30, (int) $record->early_departure_minutes, 'los minutos sí se registran');
    }

    public function test_early_departure_escalates_to_absence_by_default(): void
    {
        $employee = $this->employee();
        $record = AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-06-03',
            'check_in' => '08:00:00',
            'check_out' => '15:00:00',
            'status' => 'present',
        ]);

        app(ZktecoSyncService::class)->recalculateAttendanceRecord($record);

        $this->assertSame('absent', $record->fresh()->status, 'default: salida temprana excesiva sin permiso es falta');
    }

    public function test_early_departure_exactly_at_threshold_is_absence(): void
    {
        // Auditoría #79: un registro EXACTAMENTE en el umbral (30 min default)
        // era falta para los reportes (>=) pero no para el sync (>). El sync
        // ahora usa >= — el mismo criterio en todos los módulos.
        $employee = $this->employee();
        $record = AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-06-03',
            'check_in' => '08:00:00',
            'check_out' => '16:30:00', // 30 min antes de la salida de las 17:00
            'status' => 'present',
        ]);

        app(ZktecoSyncService::class)->recalculateAttendanceRecord($record);

        $record->refresh();

        $this->assertSame(30, (int) $record->early_departure_minutes);
        $this->assertSame('absent', $record->status, 'el umbral exacto ya cuenta como falta (>=), igual que en los reportes');
    }
}
