<?php

namespace Tests\Feature\Authorizations;

use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\SystemSetting;
use App\Services\WeekendAuthorizationUnitService;
use Tests\FeatureTestCase;

/**
 * Tolerancia de 10 minutos para completar las horas del fin de semana (Dani
 * 2026-11-11): antes, 11 h 59 min perdían el segundo fin del día.
 */
class WeekendHoursToleranceTest extends FeatureTestCase
{
    private function employee(?int $unitHours): Employee
    {
        $department = Department::factory()->create([
            'weekend_unit_hours' => $unitHours,
            'weekend_overtime_after_hours' => $unitHours ? null : 7,
        ]);

        return Employee::factory()->create(['department_id' => $department->id])->load('department');
    }

    private function units(Employee $employee, string $in, string $out): ?int
    {
        $record = AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-11-08',
            'check_in' => $in,
            'check_out' => $out,
            'velada_hours' => 0,
            'is_weekend_work' => true,
            'raw_punches' => [
                ['date' => '2026-11-08', 'time' => $in, 'type' => 'in'],
                ['date' => '2026-11-08', 'time' => $out, 'type' => 'out'],
            ],
        ]);

        return app(WeekendAuthorizationUnitService::class)->backedUnitsFor($employee, $record);
    }

    public function test_threshold_department_reaches_double_within_ten_minutes(): void
    {
        $this->assertSame(10, (int) SystemSetting::get('weekend_hours_tolerance_minutes'), 'sembrada en 10 min');
        $employee = $this->employee(null);

        $this->assertSame(2, $this->units($employee, '08:00:00', '19:50:00'), '11 h 50 min: dentro de la tolerancia');
    }

    public function test_threshold_department_beyond_tolerance_stays_single(): void
    {
        $employee = $this->employee(null);

        $this->assertSame(1, $this->units($employee, '08:00:00', '19:49:00'), '11 h 49 min: le faltan 11 min');
    }

    public function test_almacen_blocks_use_the_same_tolerance(): void
    {
        $this->assertSame(2, $this->units($this->employee(6), '08:00:00', '19:55:00'), '11 h 55 min en bloques de 6 h = 2');
        $this->assertSame(1, $this->units($this->employee(6), '08:00:00', '19:45:00'), '11 h 45 min = 1');
    }

    public function test_zero_tolerance_restores_exact_rule(): void
    {
        SystemSetting::set('weekend_hours_tolerance_minutes', 0);
        $employee = $this->employee(null);

        $this->assertSame(1, $this->units($employee, '08:00:00', '19:59:00'), 'sin tolerancia, 11 h 59 min = 1');
    }
}
