<?php

namespace Tests\Feature\Payroll;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\Schedule;
use App\Models\User;
use App\Services\PayrollCalculatorService;
use Tests\FeatureTestCase;

/**
 * El recibo explica el tiempo extra APROBADO que no se paga completo (Elias
 * 2026-08-26, caso Juan Carlos Ponce: "tiene aprobadas 8 horas" y el recibo
 * pagaba 6.5, sin decir por qué).
 *
 * Las dos reglas que recortan son correctas —la ventana encimada se paga una
 * sola vez y lo que la checada no respalda no se paga—, pero eran invisibles.
 */
class OvertimeShortfallExplanationTest extends FeatureTestCase
{
    private const DATE = '2026-06-03'; // miércoles

    private function calculator(): PayrollCalculatorService
    {
        return app(PayrollCalculatorService::class);
    }

    private function employee(): Employee
    {
        $schedule = Schedule::factory()->create([
            'entry_time' => '08:00',
            'exit_time' => '17:00',
            'daily_work_hours' => 8,
            'break_minutes' => 60,
        ]);

        return Employee::factory()->create([
            'status' => 'active',
            'schedule_id' => $schedule->id,
            'daily_salary' => 800.00,
            'hourly_rate' => 100.00,
            'hire_date' => '2025-01-01',
        ]);
    }

    private function overtime(Employee $employee, float $hours, ?string $start = null, ?string $end = null): Authorization
    {
        return Authorization::create([
            'employee_id' => $employee->id,
            'requested_by' => User::factory()->create()->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => self::DATE,
            'hours' => $hours,
            'start_time' => $start,
            'end_time' => $end,
            'reason' => 'TE',
            'status' => Authorization::STATUS_APPROVED,
        ]);
    }

    /** Checada completa cuyo TE respaldado es $backed horas. */
    private function record(Employee $employee, float $backed): AttendanceRecord
    {
        return AttendanceRecord::factory()->for($employee)->create([
            'work_date' => self::DATE,
            'check_in' => '08:00:00',
            'check_out' => '17:00:00',
            'status' => 'present',
            'worked_hours' => 8,
            'overtime_hours' => $backed,
            'overtime_authorized_hours' => $backed,
        ]);
    }

    private function monthly(): PayrollPeriod
    {
        return PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
            'payment_date' => '2026-07-03',
        ]);
    }

    public function test_the_receipt_explains_hours_the_punches_do_not_back(): void
    {
        $employee = $this->employee();
        $this->overtime($employee, 2.0, '17:00', '19:00');
        $this->record($employee, 0.5); // la checada solo respalda media hora

        $entry = $this->calculator()->calculateEmployeePayroll($this->monthly(), $employee);

        $shortfalls = $entry->calculation_breakdown['overtime']['shortfalls'] ?? [];
        $this->assertCount(1, $shortfalls);
        $this->assertSame(self::DATE, $shortfalls[0]['date']);
        $this->assertEqualsWithDelta(2.0, $shortfalls[0]['authorized_hours'], 0.01);
        $this->assertEqualsWithDelta(0.5, $shortfalls[0]['paid_hours'], 0.01);
        $this->assertFalse($shortfalls[0]['overlapping'], 'no hay ventanas encimadas');
    }

    public function test_the_receipt_flags_overlapping_windows_paid_once(): void
    {
        // Caso Juan Carlos: 16:30-20:00 (3.5 h) y 16:30-17:30 (1 h) el mismo día
        // — la segunda vive dentro de la primera y no se paga dos veces.
        $employee = $this->employee();
        $this->overtime($employee, 3.5, '16:30', '20:00');
        $this->overtime($employee, 1.0, '16:30', '17:30');
        $this->record($employee, 3.5);

        $entry = $this->calculator()->calculateEmployeePayroll($this->monthly(), $employee);

        $shortfalls = $entry->calculation_breakdown['overtime']['shortfalls'] ?? [];
        $this->assertCount(1, $shortfalls);
        $this->assertEqualsWithDelta(4.5, $shortfalls[0]['authorized_hours'], 0.01, 'lo capturado suma 4.5');
        $this->assertEqualsWithDelta(3.5, $shortfalls[0]['paid_hours'], 0.01, 'la ventana se paga una vez');
        $this->assertTrue($shortfalls[0]['overlapping'], 'se avisa que están encimadas');
    }

    public function test_nothing_is_flagged_when_the_punches_back_everything(): void
    {
        $employee = $this->employee();
        $this->overtime($employee, 2.0, '17:00', '19:00');
        $this->record($employee, 2.0);

        $entry = $this->calculator()->calculateEmployeePayroll($this->monthly(), $employee);

        $this->assertSame([], $entry->calculation_breakdown['overtime']['shortfalls'] ?? []);
    }

    public function test_extra_outside_the_punches_is_not_flagged(): void
    {
        // Aprobado a mano como "extra fuera de checada": se paga completo, así
        // que no hay nada que explicar.
        $employee = $this->employee();
        $auth = $this->overtime($employee, 2.0, '17:00', '19:00');
        $auth->update(['is_unbacked_extra' => true]);
        $this->record($employee, 0.5);

        $entry = $this->calculator()->calculateEmployeePayroll($this->monthly(), $employee);

        $this->assertSame([], $entry->calculation_breakdown['overtime']['shortfalls'] ?? []);
    }
}
