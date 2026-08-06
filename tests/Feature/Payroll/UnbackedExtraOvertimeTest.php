<?php

namespace Tests\Feature\Payroll;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\Schedule;
use App\Models\User;
use App\Services\PayrollCalculatorService;
use App\Services\Reports\WeeklyOvertimeReportService;
use App\Services\VeladaCalculatorService;
use Carbon\Carbon;
use Tests\FeatureTestCase;

/**
 * Pago del excedente de TE "fuera de checada" (is_unbacked_extra, split de
 * Elias 2026-08-05).
 *
 * Cuando una autorización reclama más de lo que la checada respalda, se parte:
 * la porción respaldada se aprueba sola y el excedente queda pendiente con la
 * bandera. Si un humano APRUEBA el excedente, la nómina lo paga POR ENCIMA del
 * tope al timecard (que de otro modo lo dejaría en cero) — y para no contarlo
 * dos veces, se excluye de la suma autorizada que se topa contra lo detectado.
 * El reporte semanal lo muestra igual que lo paga el recibo.
 */
class UnbackedExtraOvertimeTest extends FeatureTestCase
{
    private const DATE = '2026-06-03'; // miércoles

    private function employee(?Department $department = null): Employee
    {
        $schedule = Schedule::factory()->create([
            'entry_time' => '08:00',
            'exit_time' => '17:00',
            'daily_work_hours' => 8,
            'break_minutes' => 60,
        ]);

        return Employee::factory()->create([
            'status' => 'active',
            'department_id' => ($department ?? Department::factory()->create())->id,
            'schedule_id' => $schedule->id,
            'daily_salary' => 800.00,
            'hourly_rate' => 100.00,
            'hire_date' => '2025-01-01',
        ]);
    }

    /** Concepto HE por hora a $50, asignado al empleado. */
    private function heTypeFor(Employee $employee): CompensationType
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
            $type = $existing->fresh();
        } else {
            $type = CompensationType::factory()->create(array_merge(['code' => 'HE'], $attributes));
        }

        $employee->compensationTypes()->attach($type->id, ['is_active' => true]);

        return $type;
    }

    private function approvedOvertime(
        Employee $employee,
        float $hours,
        bool $unbackedExtra = false,
        ?CompensationType $type = null,
    ): Authorization {
        return Authorization::create([
            'employee_id' => $employee->id,
            'requested_by' => User::factory()->create()->id,
            'type' => Authorization::TYPE_OVERTIME,
            'compensation_type_id' => $type?->id,
            'date' => self::DATE,
            'hours' => $hours,
            'reason' => $unbackedExtra ? 'excedente fuera de checada' : 'TE respaldado',
            'status' => Authorization::STATUS_APPROVED,
            'is_unbacked_extra' => $unbackedExtra,
        ]);
    }

    public function test_flagged_extra_does_not_inflate_the_timecard_cap(): void
    {
        // Checada 08:00–19:30 (break 60) → 2.5 h extra detectadas y pagables.
        // Respaldado aprobado: 1.0 h. Excedente marcado aprobado: 5.0 h.
        // El mín(detectado, autorizado) debe ver SOLO la 1.0 respaldada — el
        // excedente se paga por su propia vía, no inflando el tope.
        $employee = $this->employee();
        $record = AttendanceRecord::factory()->for($employee)->create([
            'work_date' => self::DATE,
            'check_in' => '08:00:00',
            'check_out' => '19:30:00',
            'actual_break_minutes' => 60,
            'status' => 'present',
        ]);

        $this->approvedOvertime($employee, 1.0);
        $this->approvedOvertime($employee, 5.0, unbackedExtra: true);

        $split = app(VeladaCalculatorService::class)->calculate($record, $employee);

        $this->assertEqualsWithDelta(1.0, $split['overtime_authorized'], 0.01, 'el excedente marcado no entra al tope del timecard');
    }

    public function test_approved_flagged_extra_pays_beyond_timecard_cap(): void
    {
        // Día MEDIDO (checada completa): el timecard aporta las 2.5 h
        // respaldadas; el excedente aprobado de 1.0 h se suma encima →
        // 3.5 h × $50 = $175.
        $employee = $this->employee();
        $he = $this->heTypeFor($employee);

        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => self::DATE,
            'check_in' => '08:00:00',
            'check_out' => '19:30:00',
            'actual_break_minutes' => 60,
            'status' => 'present',
            'worked_hours' => 8,
            'overtime_hours' => 2.5,
            'overtime_authorized_hours' => 2.5,
        ]);

        $this->approvedOvertime($employee, 2.5, type: $he);
        $this->approvedOvertime($employee, 1.0, unbackedExtra: true, type: $he);

        $period = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);

        $entry = app(PayrollCalculatorService::class)->calculateEmployeePayroll($period, $employee);

        $this->assertEqualsWithDelta(3.5, (float) $entry->overtime_authorized_hours, 0.01, 'respaldado (timecard) + excedente aprobado');
        $this->assertEqualsWithDelta(175.00, (float) $entry->overtime_pay, 0.01);
    }

    public function test_weekly_report_shows_flagged_extra_like_the_receipt(): void
    {
        // Reporte semanal: lo respaldado se topa al timecard (2.5 detectadas)
        // y el excedente aprobado se muestra encima → 3.5, la misma cifra que
        // paga el recibo.
        $department = Department::factory()->create();
        $employee = $this->employee($department);
        $he = $this->heTypeFor($employee);

        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => self::DATE,
            'check_in' => '08:00:00',
            'check_out' => '19:30:00',
            'actual_break_minutes' => 60,
            'status' => 'present',
            'worked_hours' => 8,
            'overtime_hours' => 2.5,
        ]);

        $this->approvedOvertime($employee, 2.5, type: $he);
        $this->approvedOvertime($employee, 1.0, unbackedExtra: true, type: $he);

        $report = app(WeeklyOvertimeReportService::class)
            ->buildReport($department, Carbon::parse('2026-06-01'));

        $day = $report['rows'][0]['days'][self::DATE];

        $this->assertEqualsWithDelta(3.5, $day['overtime_hours'], 0.01, 'tope al timecard + excedente aprobado encima');
    }
}
