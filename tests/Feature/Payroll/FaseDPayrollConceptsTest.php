<?php

namespace Tests\Feature\Payroll;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\PayrollPeriod;
use App\Services\PayrollCalculatorService;
use Tests\FeatureTestCase;

/**
 * Fase D (DECISIONES_NEGOCIO_2026-06-04.md §3, §4, §5 revisada, §6):
 *
 * - "Solo no pagar el día": faltas e incidencias sin goce ya valen $0 vía
 *   horas trabajadas; NO generan deducción adicional (adiós doble castigo).
 *   La única deducción monetaria es la FRT (cubierta en MonthlyLateAbsenceTest).
 * - Prima vacacional: se paga con cada vacación como concepto separado.
 * - Incapacidades: con goce se pagan, sin goce ni pagan ni descuentan.
 * - count_mode por tipo: vacaciones en días hábiles, incapacidades en
 *   calendario — el mismo conteo en captura, saldo y nómina.
 */
class FaseDPayrollConceptsTest extends FeatureTestCase
{
    private function calculator(): PayrollCalculatorService
    {
        return app(PayrollCalculatorService::class);
    }

    private function employee(array $attrs = []): Employee
    {
        return Employee::factory()->create(array_merge([
            'status' => 'active',
            'hourly_rate' => 100.00, // sueldo diario 800
            'vacation_premium_percentage' => 25.00,
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

    private function approvedIncident(Employee $employee, IncidentType $type, string $start, string $end, int $daysCount): Incident
    {
        return Incident::factory()->approved()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'start_date' => $start,
            'end_date' => $end,
            'days_count' => $daysCount,
        ]);
    }

    public function test_absence_incident_no_longer_deducts_money(): void
    {
        $employee = $this->employee();

        // Semana con 4 días trabajados y falta (FIN) el miércoles.
        foreach (['2026-06-01', '2026-06-02', '2026-06-04', '2026-06-05'] as $date) {
            AttendanceRecord::factory()->for($employee)->create([
                'work_date' => $date,
                'status' => 'present',
                'worked_hours' => 8.00,
            ]);
        }
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-06-03',
            'check_in' => null,
            'check_out' => null,
            'status' => 'absent',
            'worked_hours' => 0,
        ]);

        $fin = $this->typeWithCode('FIN', ['category' => 'absence', 'is_paid' => false]);
        $this->approvedIncident($employee, $fin, '2026-06-03', '2026-06-03', 1);

        $weekly = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($weekly, $employee);

        $this->assertEqualsWithDelta(3200.00, (float) $entry->regular_pay, 0.01, 'el día ausente no se paga (4 × 800)');
        $this->assertEqualsWithDelta(0.00, (float) $entry->deductions, 0.01, 'sin deducción adicional: el castigo es no pagar el día');
        $this->assertEqualsWithDelta(3200.00, (float) $entry->net_pay, 0.01);
        $this->assertSame(1, (int) $entry->days_absent, 'la falta sigue siendo visible');
    }

    public function test_unpaid_permission_no_longer_deducts_money(): void
    {
        $employee = $this->employee();

        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-06-02',
            'status' => 'present',
            'worked_hours' => 8.00,
        ]);

        $psg = $this->typeWithCode('PSG', ['category' => 'permission', 'is_paid' => false]);
        $this->approvedIncident($employee, $psg, '2026-06-03', '2026-06-03', 1);

        $weekly = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($weekly, $employee);

        $this->assertEqualsWithDelta(800.00, (float) $entry->regular_pay, 0.01);
        $this->assertEqualsWithDelta(0.00, (float) $entry->deductions, 0.01, 'permiso sin goce: el día no se paga, no se descuenta encima');
    }

    public function test_vacation_premium_is_paid_as_separate_concept(): void
    {
        $employee = $this->employee(['vacation_premium_percentage' => 25.00]);

        // Vacaciones L-V (5 días hábiles), tipo en modo hábiles (default).
        $vac = $this->typeWithCode('VAC', [
            'category' => 'vacation',
            'is_paid' => true,
            'deducts_vacation' => true,
            'count_mode' => IncidentType::COUNT_WORKING_DAYS,
        ]);
        $this->approvedIncident($employee, $vac, '2026-06-01', '2026-06-05', 5);

        $monthly = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($monthly, $employee);

        $this->assertEqualsWithDelta(4000.00, (float) $entry->vacation_pay, 0.01, '5 días hábiles × 800');
        $this->assertEqualsWithDelta(1000.00, (float) $entry->vacation_premium_pay, 0.01, 'prima 25% sobre la vacación');
        $this->assertSame(5, (int) $entry->vacation_days_paid);
        $this->assertGreaterThanOrEqual(5000.00, (float) $entry->gross_pay);
    }

    public function test_vacation_pays_working_days_not_calendar(): void
    {
        $employee = $this->employee(['vacation_premium_percentage' => 0]);

        // Lunes a domingo: 7 días calendario pero solo 5 hábiles (L-V).
        $vac = $this->typeWithCode('VAC', [
            'category' => 'vacation',
            'is_paid' => true,
            'count_mode' => IncidentType::COUNT_WORKING_DAYS,
        ]);
        $this->approvedIncident($employee, $vac, '2026-06-01', '2026-06-07', 5);

        $monthly = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($monthly, $employee);

        $this->assertEqualsWithDelta(4000.00, (float) $entry->vacation_pay, 0.01, '5 hábiles × 800 — el fin de semana no se paga doble vía nómina');
        $this->assertSame(5, (int) $entry->vacation_days_paid, 'mismo conteo que la captura y el saldo');
    }

    public function test_paid_sick_leave_pays_calendar_days(): void
    {
        $employee = $this->employee();

        // Incapacidad con goce: viernes a lunes = 4 días CALENDARIO (estándar IMSS).
        $inc = $this->typeWithCode('INC', [
            'category' => 'sick_leave',
            'is_paid' => true,
            'count_mode' => IncidentType::COUNT_CALENDAR_DAYS,
        ]);
        $this->approvedIncident($employee, $inc, '2026-06-05', '2026-06-08', 4);

        $monthly = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($monthly, $employee);

        $this->assertEqualsWithDelta(3200.00, (float) $entry->sick_leave_pay, 0.01, '4 días calendario × 800: is_paid por fin se respeta');
        $this->assertSame(4, (int) $entry->sick_leave_days);
    }

    public function test_unpaid_sick_leave_pays_and_deducts_nothing(): void
    {
        $employee = $this->employee();

        $incSg = $this->typeWithCode('ISG', [
            'category' => 'sick_leave',
            'is_paid' => false,
            'count_mode' => IncidentType::COUNT_CALENDAR_DAYS,
        ]);
        $this->approvedIncident($employee, $incSg, '2026-06-05', '2026-06-08', 4);

        $monthly = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($monthly, $employee);

        $this->assertEqualsWithDelta(0.00, (float) $entry->sick_leave_pay, 0.01, 'sin goce: no se paga');
        $this->assertEqualsWithDelta(0.00, (float) $entry->deductions, 0.01, 'ni se descuenta (el día ya vale $0 vía horas)');
        $this->assertSame(4, (int) $entry->sick_leave_days, 'los días sí se registran');
    }

    public function test_incident_capture_counts_days_per_type_mode(): void
    {
        $employee = $this->employee();
        $this->actingAsAdmin();

        // Incapacidad (calendario): viernes 5 a lunes 8 = 4 días.
        $inc = $this->typeWithCode('INC', [
            'category' => 'sick_leave',
            'is_paid' => true,
            'count_mode' => IncidentType::COUNT_CALENDAR_DAYS,
            'requires_approval' => true,
            'requires_document' => false,
        ]);

        $this->post(route('incidents.store'), [
            'employee_id' => $employee->id,
            'incident_type_id' => $inc->id,
            'start_date' => '2026-06-05',
            'end_date' => '2026-06-08',
            'reason' => 'incapacidad',
        ])->assertRedirect(route('incidents.index'));

        $this->assertSame(4, (int) Incident::where('employee_id', $employee->id)->latest('id')->first()->days_count, 'calendario: 4 días');

        // Vacaciones (hábiles): mismo rango = 2 días (viernes y lunes).
        $employee2 = $this->employee();
        $vac = $this->typeWithCode('VAC', [
            'category' => 'vacation',
            'is_paid' => true,
            'deducts_vacation' => false,
            'count_mode' => IncidentType::COUNT_WORKING_DAYS,
            'requires_approval' => true,
            'requires_document' => false,
        ]);

        $this->post(route('incidents.store'), [
            'employee_id' => $employee2->id,
            'incident_type_id' => $vac->id,
            'start_date' => '2026-06-05',
            'end_date' => '2026-06-08',
            'reason' => 'vacaciones',
        ])->assertRedirect(route('incidents.index'));

        $this->assertSame(2, (int) Incident::where('employee_id', $employee2->id)->latest('id')->first()->days_count, 'hábiles: viernes y lunes');
    }
}
