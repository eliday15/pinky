<?php

namespace Tests\Feature\Incidents;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Services\ZktecoSyncService;
use Tests\FeatureTestCase;

/**
 * Permiso DENTRO de jornada (PDJ, Dani 2026-08-24): el colaborador sale un
 * rato y regresa a terminar su turno (p. ej. 13:00–15:00, sale a las 18:00).
 * La ventana aprobada se descuenta de las horas trabajadas (sin TE fantasma),
 * del retardo y de la salida temprana en la parte que cubre; las horas quedan
 * en permission_hours y el día sigue 'present'. Si NO regresa, la salida
 * temprana se mide desde el fin de la ventana y puede seguir siendo falta.
 *
 * Horario default del factory: L-V 08:00-17:00, tolerancia 10, comida 60.
 */
class PermisoDentroJornadaTest extends FeatureTestCase
{
    private const DATE = '2026-06-03'; // miércoles

    private function pdjType(): IncidentType
    {
        // La migración seed_permiso_dentro_jornada_type ya lo crea; se asegura
        // la config por si el orden de tests la tocó.
        $attributes = [
            'name' => 'Permiso dentro de jornada',
            'category' => 'permission',
            'is_paid' => true,
            'affects_attendance' => true,
            'has_time_range' => true,
            'requires_approval' => true,
            'is_active' => true,
        ];
        $existing = IncidentType::where('code', 'PDJ')->first();
        if ($existing) {
            $existing->update($attributes);

            return $existing->fresh();
        }

        return IncidentType::factory()->create($attributes + ['code' => 'PDJ']);
    }

    private function approvedWindow(Employee $employee, string $start, string $end, float $hours): Incident
    {
        return Incident::factory()->approved()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $this->pdjType()->id,
            'start_date' => self::DATE,
            'end_date' => self::DATE,
            'days_count' => 1,
            'start_time' => $start,
            'end_time' => $end,
            'hours' => $hours,
        ]);
    }

    private function record(Employee $employee, string $checkIn, ?string $checkOut): AttendanceRecord
    {
        // Sin checadas de comida (el factory siembra 14:00–15:00, que solaparía
        // la ventana): aplica el descuento de comida por horario (fallback 60).
        return AttendanceRecord::factory()->for($employee)->create([
            'work_date' => self::DATE,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'lunch_out' => null,
            'lunch_in' => null,
            'actual_break_minutes' => 0,
            'status' => 'present',
        ]);
    }

    public function test_window_hours_do_not_count_as_worked_nor_overtime(): void
    {
        // Permiso 13:00–15:00, regresa y sale 18:00 (una hora después de su
        // salida de 17:00). Sin la ventana: 10 h − 1 comida = 9 → 1 h de TE
        // fantasma. Con la ventana: 9 − 2 = 7 h trabajadas, 0 TE.
        $employee = Employee::factory()->create(['status' => 'active']);
        $record = $this->record($employee, '08:00:00', '18:00:00');
        $this->approvedWindow($employee, '13:00', '15:00', 2.0);

        app(ZktecoSyncService::class)->recalculateAttendanceRecord($record);
        $record->refresh();

        $this->assertSame('present', $record->status);
        $this->assertEqualsWithDelta(7.0, (float) $record->worked_hours, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $record->overtime_hours, 0.01, 'la ventana no genera TE fantasma');
        $this->assertEqualsWithDelta(2.0, (float) $record->permission_hours, 0.01);
        $this->assertEqualsWithDelta(9.0, (float) $record->total_payroll_hours, 0.01);
    }

    public function test_lunch_punched_inside_window_is_not_deducted_twice(): void
    {
        // Comida checada 14:00–15:00 DENTRO de la ventana 13:00–15:00: la hora
        // ya restada como comida no se resta doble. 10 h de presencia − 2 de
        // ventana (1 ya contada como comida) = 8 h trabajadas.
        $employee = Employee::factory()->create(['status' => 'active']);
        $record = AttendanceRecord::factory()->for($employee)->create([
            'work_date' => self::DATE,
            'check_in' => '08:00:00',
            'check_out' => '18:00:00',
            'lunch_out' => '14:00:00',
            'lunch_in' => '15:00:00',
            'actual_break_minutes' => 60,
            'status' => 'present',
        ]);
        $this->approvedWindow($employee, '13:00', '15:00', 2.0);

        app(ZktecoSyncService::class)->recalculateAttendanceRecord($record);
        $record->refresh();

        $this->assertEqualsWithDelta(8.0, (float) $record->worked_hours, 0.01, 'la hora de comida dentro de la ventana no se descuenta dos veces');
        $this->assertEqualsWithDelta(0.0, (float) $record->overtime_hours, 0.01);
    }

    public function test_not_returning_still_counts_early_departure_from_window_end(): void
    {
        // Permiso 13:00–15:00 pero NO regresa (última checada 13:00): la salida
        // temprana se mide desde las 15:00 → 120 min ≥ umbral 30 → falta.
        $employee = Employee::factory()->create(['status' => 'active']);
        $record = $this->record($employee, '08:00:00', '13:00:00');
        $this->approvedWindow($employee, '13:00', '15:00', 2.0);

        app(ZktecoSyncService::class)->recalculateAttendanceRecord($record);
        $record->refresh();

        $this->assertSame('absent', $record->status, 'no regresar tras la ventana sigue siendo falta');
        $this->assertSame(120, (int) $record->early_departure_minutes);
    }

    public function test_window_covering_shift_start_excuses_late_arrival(): void
    {
        // Permiso 08:00–10:00 y llega 10:05: el retardo (115 min tras
        // tolerancia) queda cubierto por la ventana → sin retardo, presente.
        $employee = Employee::factory()->create(['status' => 'active']);
        $record = $this->record($employee, '10:05:00', '17:00:00');
        $this->approvedWindow($employee, '08:00', '10:00', 2.0);

        app(ZktecoSyncService::class)->recalculateAttendanceRecord($record);
        $record->refresh();

        $this->assertSame('present', $record->status);
        $this->assertSame(0, (int) $record->late_minutes);
    }

    public function test_store_requires_both_times_in_order(): void
    {
        $employee = Employee::factory()->create(['status' => 'active']);
        $type = $this->pdjType();
        $this->actingAsAdmin();

        $this->post(route('incidents.store'), [
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'start_date' => self::DATE,
            'end_date' => self::DATE,
            'start_time' => '13:00',
            'reason' => 'trámite personal',
        ])->assertSessionHasErrors('end_time');

        $this->post(route('incidents.store'), [
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'start_date' => self::DATE,
            'end_date' => self::DATE,
            'start_time' => '15:00',
            'end_time' => '13:00',
            'reason' => 'trámite personal',
        ])->assertSessionHasErrors('end_time');
    }

    public function test_store_computes_hours_from_window(): void
    {
        $employee = Employee::factory()->create(['status' => 'active']);
        $type = $this->pdjType();
        $this->actingAsAdmin();

        $this->post(route('incidents.store'), [
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'start_date' => self::DATE,
            'end_date' => self::DATE,
            'start_time' => '13:00',
            'end_time' => '15:00',
            'reason' => 'trámite personal',
        ])->assertRedirect(route('incidents.index'));

        $incident = Incident::where('employee_id', $employee->id)->latest('id')->first();
        $this->assertEqualsWithDelta(2.0, (float) $incident->hours, 0.01);
    }

    public function test_psa_single_time_permission_is_unchanged(): void
    {
        // PSA captura una sola hora (start = end): NO es ventana — sigue
        // cubriendo la salida completa como siempre, sin descontar horas.
        $employee = Employee::factory()->create(['status' => 'active']);
        $record = $this->record($employee, '08:00:00', '15:00:00'); // sale 2 h antes

        $psa = IncidentType::updateOrCreate(['code' => 'PSA'], [
            'name' => 'Permiso de Salida',
            'category' => 'permission',
            'is_paid' => true,
            'affects_attendance' => true,
            'has_time_range' => true,
            'requires_approval' => true,
            'is_active' => true,
        ]);
        Incident::factory()->approved()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $psa->id,
            'start_date' => self::DATE,
            'end_date' => self::DATE,
            'days_count' => 1,
            'start_time' => '15:00',
            'end_time' => '15:00',
            'hours' => 0,
        ]);

        app(ZktecoSyncService::class)->recalculateAttendanceRecord($record);
        $record->refresh();

        $this->assertSame('present', $record->status, 'PSA sigue cubriendo la salida temprana');
        $this->assertEqualsWithDelta(6.0, (float) $record->worked_hours, 0.01, '7 h de presencia − 1 de comida; PSA no descuenta ventana');
    }
}
