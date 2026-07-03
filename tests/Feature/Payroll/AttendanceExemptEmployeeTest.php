<?php

namespace Tests\Feature\Payroll;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\PayrollPeriod;
use App\Models\Schedule;
use App\Services\PayrollCalculatorService;
use Tests\FeatureTestCase;

/**
 * Empleados exentos de asistencia (no checan) + faltas capturadas por
 * incidencia que descuentan SD×7/6.
 *
 * - Un empleado sin checador (is_attendance_exempt) no tiene checadas, así que
 *   cobra su sueldo completo (SD×7) automáticamente.
 * - Su falta se captura como incidencia "Falta injustificada" (category
 *   'absence', is_paid=false) y descuenta SD×7/6, igual que cualquier falta.
 * - El descuento por incidencia se deduplica por FECHA contra las faltas de
 *   checada y los días trabajados, honra los días justificados, solo aplica en
 *   periodo base, y nunca deja el neto negativo.
 */
class AttendanceExemptEmployeeTest extends FeatureTestCase
{
    private function calculator(): PayrollCalculatorService
    {
        return app(PayrollCalculatorService::class);
    }

    /** Empleado con sueldo diario 800 y horario dado (por defecto L-V). */
    private function employee(array $attrs = [], ?array $workingDays = null): Employee
    {
        if ($workingDays !== null) {
            $attrs['schedule_id'] = Schedule::factory()->create(['working_days' => $workingDays])->id;
        }

        return Employee::factory()->create(array_merge([
            'status' => 'active',
            'daily_salary' => 800.00,
            'hourly_rate' => 100.00,
        ], $attrs));
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

    private function approvedIncident(Employee $employee, IncidentType $type, string $start, string $end, int $daysCount = 1): Incident
    {
        return Incident::factory()->approved()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'start_date' => $start,
            'end_date' => $end,
            'days_count' => $daysCount,
        ]);
    }

    private function weekly(): PayrollPeriod
    {
        return PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-01', // lunes
            'end_date' => '2026-06-07',   // domingo
        ]);
    }

    public function test_exempt_employee_with_no_attendance_is_paid_full_base(): void
    {
        $employee = $this->employee(['is_attendance_exempt' => true, 'zkteco_user_id' => null]);

        $entry = $this->calculator()->calculateEmployeePayroll($this->weekly(), $employee);

        $this->assertEqualsWithDelta(5600.00, (float) $entry->regular_pay, 0.01, 'SD 800 × 7');
        $this->assertEqualsWithDelta(0.00, (float) $entry->deductions, 0.01, 'sin checadas, sin faltas');
        $this->assertEqualsWithDelta(5600.00, (float) $entry->net_pay, 0.01);
        $this->assertSame(0, (int) $entry->days_absent);
    }

    public function test_exempt_employee_absence_incident_deducts_seventh(): void
    {
        $employee = $this->employee(['is_attendance_exempt' => true, 'zkteco_user_id' => null]);
        $fin = $this->typeWithCode('FIN', ['category' => 'absence', 'is_paid' => false]);
        $this->approvedIncident($employee, $fin, '2026-06-03', '2026-06-03'); // miércoles

        $entry = $this->calculator()->calculateEmployeePayroll($this->weekly(), $employee);

        $this->assertEqualsWithDelta(5600.00, (float) $entry->regular_pay, 0.01);
        $this->assertEqualsWithDelta(933.33, (float) $entry->deductions, 0.01, '1 falta × 800 × 7/6');
        $this->assertEqualsWithDelta(4666.67, (float) $entry->net_pay, 0.01);
        $this->assertSame(1, (int) $entry->days_absent, 'la falta por incidencia se refleja');
        $this->assertSame(1, (int) ($entry->calculation_breakdown['incidents']['absence_incident_deduction_days'] ?? 0));
    }

    public function test_absence_incident_is_deduped_against_attendance_absent_same_date(): void
    {
        // Empleado con checador: fila 'absent' + FIN el mismo día => UNA sola
        // deducción (no se descuenta dos veces la misma fecha).
        $employee = $this->employee();
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-06-03',
            'check_in' => null,
            'check_out' => null,
            'status' => 'absent',
            'worked_hours' => 0,
        ]);
        $fin = $this->typeWithCode('FIN', ['category' => 'absence', 'is_paid' => false]);
        $this->approvedIncident($employee, $fin, '2026-06-03', '2026-06-03');

        $entry = $this->calculator()->calculateEmployeePayroll($this->weekly(), $employee);

        $this->assertEqualsWithDelta(933.33, (float) $entry->deductions, 0.01, 'una sola falta pese al doble registro');
        $this->assertSame(1, (int) $entry->days_absent);
        $this->assertSame(0, (int) ($entry->calculation_breakdown['incidents']['absence_incident_deduction_days'] ?? -1));
    }

    public function test_worked_day_wins_over_absence_incident(): void
    {
        // Checada trabajada + FIN el mismo día: la evidencia de trabajo gana, no
        // se descuenta ese día (protege al empleado con checador).
        $employee = $this->employee();
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-06-03',
            'status' => 'present',
            'worked_hours' => 8.00,
        ]);
        $fin = $this->typeWithCode('FIN', ['category' => 'absence', 'is_paid' => false]);
        $this->approvedIncident($employee, $fin, '2026-06-03', '2026-06-03');

        $entry = $this->calculator()->calculateEmployeePayroll($this->weekly(), $employee);

        $this->assertEqualsWithDelta(0.00, (float) $entry->deductions, 0.01, 'día trabajado no descuenta');
        $this->assertSame(0, (int) $entry->days_absent);
    }

    public function test_justified_absence_incident_does_not_deduct(): void
    {
        // "Falta justificada" (absence, is_paid=true) NO descuenta.
        $employee = $this->employee(['is_attendance_exempt' => true, 'zkteco_user_id' => null]);
        $fju = $this->typeWithCode('FJU', ['category' => 'absence', 'is_paid' => true]);
        $this->approvedIncident($employee, $fju, '2026-06-03', '2026-06-03');

        $entry = $this->calculator()->calculateEmployeePayroll($this->weekly(), $employee);

        $this->assertEqualsWithDelta(0.00, (float) $entry->deductions, 0.01);
        $this->assertEqualsWithDelta(5600.00, (float) $entry->net_pay, 0.01);
        $this->assertSame(0, (int) $entry->days_absent);
    }

    public function test_monthly_period_does_not_apply_incident_absence(): void
    {
        // Un periodo mensual (paga extras, no base) nunca descuenta la falta.
        $employee = $this->employee(['is_attendance_exempt' => true, 'zkteco_user_id' => null]);
        $fin = $this->typeWithCode('FIN', ['category' => 'absence', 'is_paid' => false]);
        $this->approvedIncident($employee, $fin, '2026-06-03', '2026-06-03');

        $monthly = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($monthly, $employee);

        $this->assertEqualsWithDelta(0.00, (float) $entry->deductions, 0.01);
        $this->assertSame(0, (int) $entry->days_absent);
    }

    public function test_absence_incident_outside_working_days_is_not_deducted(): void
    {
        // Empleado L-V; FIN en domingo (día de descanso) no descuenta.
        $employee = $this->employee(['is_attendance_exempt' => true, 'zkteco_user_id' => null]);
        $fin = $this->typeWithCode('FIN', ['category' => 'absence', 'is_paid' => false]);
        $this->approvedIncident($employee, $fin, '2026-06-07', '2026-06-07'); // domingo

        $entry = $this->calculator()->calculateEmployeePayroll($this->weekly(), $employee);

        $this->assertEqualsWithDelta(0.00, (float) $entry->deductions, 0.01, 'domingo no laborable no descuenta');
        $this->assertSame(0, (int) $entry->days_absent);
    }

    public function test_absence_incident_on_holiday_does_not_deduct(): void
    {
        // FIN que cae en día festivo no descuenta (el festivo nunca es falta).
        \App\Models\Holiday::factory()->create(['date' => '2026-06-03']);
        $employee = $this->employee(['is_attendance_exempt' => true, 'zkteco_user_id' => null]);
        $fin = $this->typeWithCode('FIN', ['category' => 'absence', 'is_paid' => false]);
        $this->approvedIncident($employee, $fin, '2026-06-03', '2026-06-03');

        $entry = $this->calculator()->calculateEmployeePayroll($this->weekly(), $employee);

        $this->assertEqualsWithDelta(0.00, (float) $entry->deductions, 0.01, 'festivo no descuenta');
        $this->assertSame(0, (int) $entry->days_absent);
    }

    public function test_two_overlapping_absence_incidents_deduct_once(): void
    {
        // Dos incidencias de falta el MISMO día descuentan una sola vez
        // (el set por fecha deduplica la unión).
        $employee = $this->employee(['is_attendance_exempt' => true, 'zkteco_user_id' => null]);
        $fin = $this->typeWithCode('FIN', ['category' => 'absence', 'is_paid' => false]);
        $sus = $this->typeWithCode('SUS', ['category' => 'absence', 'is_paid' => false]);
        $this->approvedIncident($employee, $fin, '2026-06-03', '2026-06-03');
        $this->approvedIncident($employee, $sus, '2026-06-03', '2026-06-03');

        $entry = $this->calculator()->calculateEmployeePayroll($this->weekly(), $employee);

        $this->assertEqualsWithDelta(933.33, (float) $entry->deductions, 0.01, 'una sola falta pese a dos incidencias');
        $this->assertSame(1, (int) $entry->days_absent);
    }

    public function test_net_pay_is_never_negative_with_many_absences(): void
    {
        // Empleado con horario de 7 días; FIN toda la semana => la deducción
        // (7 × 800 × 7/6 = 6533.33) excede la base (5600). El neto se topa en 0.
        $employee = $this->employee(
            ['is_attendance_exempt' => true, 'zkteco_user_id' => null],
            ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
        );
        $fin = $this->typeWithCode('FIN', ['category' => 'absence', 'is_paid' => false]);
        $this->approvedIncident($employee, $fin, '2026-06-01', '2026-06-07', 7);

        $entry = $this->calculator()->calculateEmployeePayroll($this->weekly(), $employee);

        $this->assertGreaterThanOrEqual(0.0, (float) $entry->net_pay, 'el neto nunca es negativo');
        $this->assertGreaterThanOrEqual(0.0, (float) $entry->cash_amount, 'el efectivo nunca es negativo');
        $this->assertEqualsWithDelta(0.00, (float) $entry->net_pay, 0.01);
    }
}
