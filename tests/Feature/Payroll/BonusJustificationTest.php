<?php

namespace Tests\Feature\Payroll;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\PayrollPeriod;
use App\Models\SystemSetting;
use App\Services\PayrollCalculatorService;
use Tests\FeatureTestCase;

/**
 * Fase B (DECISIONES §8): los bonos de asistencia perfecta (semanal y
 * mensual) respetan las incidencias aprobadas. Un día absent/late cubierto
 * por vacación, incapacidad, permiso o falta justificada (con goce) NO rompe
 * el bono; las disciplinarias (FIN/SUS, sin goce) y la FRT sí lo rompen.
 */
class BonusJustificationTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::factory()->create([
            'key' => 'weekly_bonus_amount',
            'value' => '200',
            'type' => 'float',
            'group' => 'payroll',
        ]);
        SystemSetting::factory()->create([
            'key' => 'monthly_bonus_amount',
            'value' => '300',
            'type' => 'float',
            'group' => 'payroll',
        ]);
    }

    private function calculator(): PayrollCalculatorService
    {
        return app(PayrollCalculatorService::class);
    }

    /**
     * Algunos códigos pueden existir ya por migraciones: actualizar o crear.
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

    private function employee(): Employee
    {
        return Employee::factory()->create(['status' => 'active', 'hourly_rate' => 100.00]);
    }

    /**
     * Semana del 1-5 de junio 2026 (L-V): 4 días presentes + 1 falta el
     * miércoles 3. La perfección depende de si esa falta está justificada.
     */
    private function weekWithOneAbsence(Employee $employee): void
    {
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
    }

    private function monthlyPeriod(): PayrollPeriod
    {
        return PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);
    }

    private function approvedIncidentCovering(Employee $employee, IncidentType $type, string $date = '2026-06-03'): Incident
    {
        return Incident::factory()->approved()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'start_date' => $date,
            'end_date' => $date,
            'days_count' => 1,
        ]);
    }

    public function test_justified_absence_preserves_weekly_and_monthly_bonus(): void
    {
        $employee = $this->employee();
        $this->weekWithOneAbsence($employee);

        // FJU: falta justificada con goce → justifica.
        $fju = $this->typeWithCode('FJU', [
            'category' => 'absence',
            'is_paid' => true,
            'requires_approval' => true,
        ]);
        $this->approvedIncidentCovering($employee, $fju);

        $entry = $this->calculator()->calculateEmployeePayroll($this->monthlyPeriod(), $employee);

        $this->assertEqualsWithDelta(200.00, (float) $entry->weekly_bonus, 0.01, 'la falta justificada no rompe la semana perfecta');
        $this->assertEqualsWithDelta(300.00, (float) $entry->monthly_bonus, 0.01, 'ni la asistencia perfecta del mes');
    }

    public function test_unjustified_absence_breaks_both_bonuses(): void
    {
        $employee = $this->employee();
        $this->weekWithOneAbsence($employee);

        $entry = $this->calculator()->calculateEmployeePayroll($this->monthlyPeriod(), $employee);

        $this->assertEqualsWithDelta(0.00, (float) $entry->weekly_bonus, 0.01, 'una falta sin justificar rompe la semana');
        $this->assertEqualsWithDelta(0.00, (float) $entry->monthly_bonus, 0.01);
    }

    public function test_disciplinary_absence_incident_still_breaks_bonus(): void
    {
        $employee = $this->employee();
        $this->weekWithOneAbsence($employee);

        // FIN: falta injustificada (sin goce) → es disciplinaria, NO justifica.
        $fin = $this->typeWithCode('FIN', [
            'category' => 'absence',
            'is_paid' => false,
            'requires_approval' => false,
        ]);
        $this->approvedIncidentCovering($employee, $fin);

        $entry = $this->calculator()->calculateEmployeePayroll($this->monthlyPeriod(), $employee);

        $this->assertEqualsWithDelta(0.00, (float) $entry->weekly_bonus, 0.01, 'FIN no justifica: el bono se pierde');
        $this->assertEqualsWithDelta(0.00, (float) $entry->monthly_bonus, 0.01);
    }

    public function test_justified_late_preserves_bonus(): void
    {
        $employee = $this->employee();

        foreach (['2026-06-01', '2026-06-02', '2026-06-04', '2026-06-05'] as $date) {
            AttendanceRecord::factory()->for($employee)->create([
                'work_date' => $date,
                'status' => 'present',
                'worked_hours' => 8.00,
            ]);
        }
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-06-03',
            'status' => 'late',
            'late_minutes' => 25,
            'worked_hours' => 7.50,
        ]);

        // Permiso (con o sin goce) cubriendo la fecha → justifica el retardo.
        $pcg = $this->typeWithCode('PCG', [
            'category' => 'permission',
            'is_paid' => true,
            'requires_approval' => true,
        ]);
        $this->approvedIncidentCovering($employee, $pcg);

        $entry = $this->calculator()->calculateEmployeePayroll($this->monthlyPeriod(), $employee);

        $this->assertEqualsWithDelta(200.00, (float) $entry->weekly_bonus, 0.01, 'el retardo justificado no rompe la semana');
        $this->assertEqualsWithDelta(300.00, (float) $entry->monthly_bonus, 0.01);
    }
}
