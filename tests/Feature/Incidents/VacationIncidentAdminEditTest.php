<?php

namespace Tests\Feature\Incidents;

use App\Models\Employee;
use App\Models\Incident;
use App\Models\IncidentType;
use Tests\FeatureTestCase;

/**
 * Hojas de vacaciones: SOLO Admin/RRHH edita/elimina, y una APROBADA se
 * puede corregir con ajuste de saldo (Dani 2026-08-12: hoja de 1 día que el
 * encargado capturó como 4).
 *
 * Reglas: los tipos que descuentan vacaciones son intocables para el
 * encargado (ni pendientes); el admin corrige fechas/horas/motivo de una
 * aprobada y el saldo se ajusta por la diferencia (devolver al encoger,
 * validar saldo al ampliar); cambiar empleado o tipo exige eliminar (que
 * devuelve los días) y recapturar.
 */
class VacationIncidentAdminEditTest extends FeatureTestCase
{
    private function vacationType(): IncidentType
    {
        return IncidentType::factory()->vacation()->create();
    }

    /** Empleado con 12 días de derecho y 4 usados (la hoja aprobada de 4). */
    private function employeeWithApprovedSheet(int $entitled = 12): array
    {
        $employee = Employee::factory()->create([
            'vacation_days_entitled' => $entitled,
            'vacation_days_used' => 4,
            'vacation_days_reserved' => 0,
            'vacation_days_advanced' => 0,
        ]);

        // Miércoles 5 a sábado 8 de agosto (sin domingo) = 4 días, como la
        // hoja real de Veronica.
        $incident = Incident::factory()->approved()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $this->vacationType()->id,
            'start_date' => '2026-08-05',
            'end_date' => '2026-08-08',
            'days_count' => 4,
        ]);

        return [$employee, $incident];
    }

    public function test_supervisor_cannot_edit_or_delete_vacation_incident(): void
    {
        $this->actingAsSupervisor();
        [$employee, $incident] = $this->employeeWithApprovedSheet();
        $pending = Incident::factory()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $incident->incident_type_id,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-01',
            'days_count' => 1,
        ]);

        // Ni la pendiente: las hojas de vacaciones son territorio del admin.
        $this->put(route('incidents.update', $pending), [
            'employee_id' => $employee->id,
            'incident_type_id' => $pending->incident_type_id,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-02',
        ])->assertForbidden();

        $this->delete(route('incidents.destroy', $pending))->assertForbidden();
    }

    public function test_admin_corrects_approved_vacation_days_and_balance_adjusts(): void
    {
        $this->actingAsAdmin();
        [$employee, $incident] = $this->employeeWithApprovedSheet();

        // Corrección: la hoja real era de 1 día (el 5), no 4.
        $this->put(route('incidents.update', $incident), [
            'employee_id' => $employee->id,
            'incident_type_id' => $incident->incident_type_id,
            'start_date' => '2026-08-05',
            'end_date' => '2026-08-05',
        ])->assertRedirect(route('incidents.index'));

        $incident->refresh();
        $employee->refresh();
        $this->assertSame('approved', $incident->status, 'sigue aprobada');
        $this->assertEqualsWithDelta(1.0, (float) $incident->days_count, 0.01);
        $this->assertSame('2026-08-05', $incident->end_date->format('Y-m-d'));
        $this->assertEqualsWithDelta(1.0, (float) $employee->vacation_days_used, 0.01, 'devolvió los 3 días de más');
    }

    public function test_admin_cannot_change_employee_or_type_on_approved(): void
    {
        $this->actingAsAdmin();
        [$employee, $incident] = $this->employeeWithApprovedSheet();
        $otro = Employee::factory()->create();

        $this->from(route('incidents.edit', $incident))->put(route('incidents.update', $incident), [
            'employee_id' => $otro->id,
            'incident_type_id' => $incident->incident_type_id,
            'start_date' => '2026-08-05',
            'end_date' => '2026-08-08',
        ])->assertRedirect(route('incidents.edit', $incident));

        $incident->refresh();
        $this->assertSame($employee->id, $incident->employee_id, 'el empleado no cambió');
        $this->assertEqualsWithDelta(4.0, (float) $incident->days_count, 0.01, 'la hoja quedó intacta');
    }

    public function test_expanding_approved_vacation_requires_balance(): void
    {
        $this->actingAsAdmin();
        // Derecho 5, usados 4 → solo 1 disponible; ampliar de 4 a 7 días pide 3.
        [$employee, $incident] = $this->employeeWithApprovedSheet(entitled: 5);

        $this->from(route('incidents.edit', $incident))->put(route('incidents.update', $incident), [
            'employee_id' => $employee->id,
            'incident_type_id' => $incident->incident_type_id,
            'start_date' => '2026-08-05',
            'end_date' => '2026-08-12', // mié 5 → mié 12, sin domingo = 7 días
        ])->assertSessionHasErrors('saldo');

        $incident->refresh();
        $this->assertEqualsWithDelta(4.0, (float) $incident->days_count, 0.01, 'sin saldo no se amplía');
    }

    public function test_admin_deletes_approved_vacation_and_days_are_refunded(): void
    {
        $this->actingAsAdmin();
        [$employee, $incident] = $this->employeeWithApprovedSheet();

        $this->delete(route('incidents.destroy', $incident))->assertRedirect();

        $employee->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $employee->vacation_days_used, 0.01, 'el borrado devolvió los 4 días');
        $this->assertSoftDeleted('incidents', ['id' => $incident->id]);
    }
}
