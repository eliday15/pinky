<?php

namespace Tests\Feature\Payroll;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Schedule;
use App\Models\User;
use App\Services\Reports\WeeklyOvertimeReportService;
use App\Services\VeladaCalculatorService;
use Carbon\Carbon;
use Tests\FeatureTestCase;

/**
 * Fase C (DECISIONES §10): la escalera de redondeo de horas extra
 * (<30min→0, 30-49→0.5h, 50-59→1h) aplica también al PAGO: el split de
 * VeladaCalculatorService redondea las horas extra con la regla de la
 * empresa ANTES de topar a lo autorizado, así nómina y reporte semanal usan
 * la misma cifra. Y el reporte semanal topa lo autorizado al timecard
 * (auditoría #20): horas aprobadas de más no se muestran como pagables.
 */
class OvertimePaymentRoundingTest extends FeatureTestCase
{
    private function calculator(): VeladaCalculatorService
    {
        return app(VeladaCalculatorService::class);
    }

    private function employee(): Employee
    {
        // Schedule default: 08:00-17:00, 8h, break 60, L-V.
        return Employee::factory()->create(['status' => 'active']);
    }

    /**
     * Checada con salida tardía: 08:00 → check_out, break real de 60 min.
     * Con jornada de 8h, los minutos extra = (total - 60break - 480).
     */
    private function recordWithExit(Employee $employee, string $checkOut): AttendanceRecord
    {
        return AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-06-03', // miércoles
            'check_in' => '08:00:00',
            'check_out' => $checkOut,
            'actual_break_minutes' => 60,
            'status' => 'present',
        ]);
    }

    private function approveOvertime(Employee $employee, float $hours): Authorization
    {
        $user = User::factory()->create();

        return Authorization::create([
            'employee_id' => $employee->id,
            'requested_by' => $user->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-03',
            'hours' => $hours,
            'reason' => 'horas extra',
            'status' => Authorization::STATUS_APPROVED,
        ]);
    }

    public function test_under_thirty_minutes_rounds_to_zero_even_if_authorized(): void
    {
        $employee = $this->employee();
        // 08:00-17:25 − 60 break = 8h25m → 25 min extra → escalera: 0.
        $record = $this->recordWithExit($employee, '17:25:00');
        $this->approveOvertime($employee, 1.0);

        $split = $this->calculator()->calculate($record, $employee);

        $this->assertEqualsWithDelta(0.0, $split['overtime_authorized'], 0.01, '<30 min no es hora extra aunque esté autorizada');
    }

    public function test_thirty_to_fortynine_minutes_pays_half_hour(): void
    {
        $employee = $this->employee();
        // 08:00-17:40 − 60 = 8h40m → 40 min extra → escalera: 0.5h.
        $record = $this->recordWithExit($employee, '17:40:00');
        $this->approveOvertime($employee, 2.0);

        $split = $this->calculator()->calculate($record, $employee);

        $this->assertEqualsWithDelta(0.5, $split['overtime_authorized'], 0.01, '30-49 min → media hora');
    }

    public function test_fifty_to_fiftynine_minutes_pays_full_hour(): void
    {
        $employee = $this->employee();
        // 08:00-17:55 − 60 = 8h55m → 55 min extra → escalera: 1.0h.
        $record = $this->recordWithExit($employee, '17:55:00');
        $this->approveOvertime($employee, 2.0);

        $split = $this->calculator()->calculate($record, $employee);

        $this->assertEqualsWithDelta(1.0, $split['overtime_authorized'], 0.01, '50-59 min → hora completa');
    }

    public function test_bies_afternoon_velada_window_splits_extra_into_velada(): void
    {
        // BIES trabaja 06:00–15:30; su velada va de 15:30 a 22:30 (regla de Dani
        // 2026-06-28). Las horas extra tras su salida y dentro de esa franja son
        // velada, no hora extra normal.
        $bies = Department::factory()->create([
            'name' => 'Bies',
            'code' => 'BIES',
            'velada_start' => '15:30:00',
            'velada_end' => '22:30:00',
        ]);
        $schedule = Schedule::factory()->create([
            'entry_time' => '06:00',
            'exit_time' => '15:30',
            'daily_work_hours' => 8.5,
            'break_minutes' => 60,
        ]);
        $employee = Employee::factory()->create([
            'status' => 'active',
            'department_id' => $bies->id,
            'schedule_id' => $schedule->id,
        ]);
        // 06:00–20:00 − 60 break = 13h; jornada 8.5h → 4.5h extra, todas dentro
        // de 15:30–22:30 → 4.5h velada, 0 hora extra.
        $record = AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-06-03',
            'check_in' => '06:00:00',
            'check_out' => '20:00:00',
            'actual_break_minutes' => 60,
            'status' => 'present',
        ]);

        $split = $this->calculator()->calculate($record, $employee);

        $this->assertEqualsWithDelta(4.5, $split['velada_hours'], 0.01, 'extra en la franja 15:30–22:30 es velada');
        $this->assertEqualsWithDelta(0.0, $split['overtime_hours'], 0.01, 'no queda hora extra fuera de la velada');
    }

    public function test_without_department_window_afternoon_extra_is_overtime(): void
    {
        // Mismo turno pero sin ventana de velada del depto: con la ventana global
        // (22:00–05:00) las horas de la tarde son hora extra, no velada.
        $dept = Department::factory()->create(['name' => 'Otro', 'code' => 'OTRO']);
        $schedule = Schedule::factory()->create([
            'entry_time' => '06:00',
            'exit_time' => '15:30',
            'daily_work_hours' => 8.5,
            'break_minutes' => 60,
        ]);
        $employee = Employee::factory()->create([
            'status' => 'active',
            'department_id' => $dept->id,
            'schedule_id' => $schedule->id,
        ]);
        $record = AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-06-03',
            'check_in' => '06:00:00',
            'check_out' => '20:00:00',
            'actual_break_minutes' => 60,
            'status' => 'present',
        ]);

        $split = $this->calculator()->calculate($record, $employee);

        $this->assertEqualsWithDelta(0.0, $split['velada_hours'], 0.01, 'sin ventana del depto no hay velada en la tarde');
        $this->assertGreaterThan(0.0, $split['overtime_hours'], 'la tarde cuenta como hora extra');
    }

    public function test_rounded_hours_are_still_capped_by_authorization(): void
    {
        $employee = $this->employee();
        // 08:00-18:35 − 60 = 9h35m → 95 min extra → escalera: 1.5h.
        $record = $this->recordWithExit($employee, '18:35:00');
        $this->approveOvertime($employee, 1.0); // solo 1h autorizada

        $split = $this->calculator()->calculate($record, $employee);

        $this->assertEqualsWithDelta(1.58, $split['overtime_hours'], 0.02, 'detectadas exactas: 95 min ≈ 1.58h');
        $this->assertEqualsWithDelta(1.0, $split['overtime_authorized'], 0.01, 'escalera da 1.5h pero la autorización (1h) sigue siendo el tope');
    }

    public function test_weekly_report_caps_authorized_hours_at_timecard(): void
    {
        $department = Department::factory()->create(['name' => 'Corte', 'code' => 'CORTE']);
        $employee = Employee::factory()->create([
            'status' => 'active',
            'department_id' => $department->id,
        ]);

        // Salida 19:00 con horario hasta 17:00 → 120 min detectados → 2.0h.
        AttendanceRecord::factory()->for($employee)->create([
            'work_date' => '2026-06-03',
            'check_in' => '08:00:00',
            'check_out' => '19:00:00',
            'status' => 'present',
        ]);

        // Pero el supervisor aprobó 5h (autorización inflada).
        $heType = CompensationType::factory()->fixed(50.00)->create([
            'code' => 'HE',
            'application_mode' => CompensationType::APPLICATION_PER_HOUR,
            'authorization_type' => Authorization::TYPE_OVERTIME,
        ]);
        $user = User::factory()->create();
        Authorization::create([
            'employee_id' => $employee->id,
            'requested_by' => $user->id,
            'type' => Authorization::TYPE_OVERTIME,
            'compensation_type_id' => $heType->id,
            'date' => '2026-06-03',
            'hours' => 5.0,
            'reason' => 'aprobación inflada',
            'status' => Authorization::STATUS_APPROVED,
        ]);

        $report = app(WeeklyOvertimeReportService::class)->buildReport($department, Carbon::parse('2026-06-01'));

        $day = $report['rows'][0]['days']['2026-06-03'];

        $this->assertEqualsWithDelta(2.0, $day['detected_overtime_hours'], 0.01, 'detectadas por escalera: 120 min → 2h');
        $this->assertEqualsWithDelta(2.0, $day['overtime_hours'], 0.01, 'las 5h aprobadas se topan a las 2h del timecard');
        $this->assertEqualsWithDelta(0.0, $day['pending_overtime_hours'], 0.01, 'nada pendiente: lo aprobado cubre lo detectado');
        $this->assertEqualsWithDelta(2.0, $report['rows'][0]['totals']['total_hours'], 0.01);
    }
}
