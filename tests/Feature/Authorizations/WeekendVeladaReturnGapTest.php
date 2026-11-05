<?php

namespace Tests\Feature\Authorizations;

use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Services\WeekendAuthorizationUnitService;
use Tests\FeatureTestCase;

/**
 * Si sale y regresa a velar, el tiempo fuera no cuenta como fin de semana
 * (Dani 2026-11-04, caso Policarpo dom 1/11: 7:48–15:00, regresó 21:30 a la
 * velada hasta 5:02 → 1 fin, no 2). La comida sigue contando corrida.
 */
class WeekendVeladaReturnGapTest extends FeatureTestCase
{
    private function almacenEmployee(): Employee
    {
        $department = Department::factory()->create(['weekend_unit_hours' => 6]);

        return Employee::factory()->create(['department_id' => $department->id])->load('department');
    }

    public function test_time_away_before_velada_return_does_not_count_as_weekend(): void
    {
        $employee = $this->almacenEmployee();
        $record = AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-11-01',
            'check_in' => '07:48:48',
            'check_out' => '05:02:04',
            'velada_hours' => 7,
            'is_weekend_work' => true,
            'raw_punches' => [
                ['date' => '2026-11-01', 'time' => '07:48:48', 'type' => 'in'],
                ['date' => '2026-11-01', 'time' => '15:00:12', 'type' => 'punch'],
                ['date' => '2026-11-01', 'time' => '21:30:15', 'type' => 'punch'],
                ['date' => '2026-11-02', 'time' => '05:02:04', 'type' => 'out'],
            ],
        ]);

        $this->assertSame(6.5, $record->veladaReturnGapHours());
        // 21.23 h corridas − 7 de velada − 6.5 fuera = 7.73 h → 1 unidad de 6 h.
        $this->assertEqualsWithDelta(7.7333, $record->weekendBaseHours(), 0.001);
        $this->assertSame(1, app(WeekendAuthorizationUnitService::class)->backedUnitsFor($employee, $record));
    }

    public function test_lunch_gap_still_counts_as_continuous_weekend(): void
    {
        $employee = $this->almacenEmployee();
        $record = AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-10-18',
            'check_in' => '06:55',
            'check_out' => '05:00',
            'velada_hours' => 7,
            'is_weekend_work' => true,
            'raw_punches' => [
                ['date' => '2026-10-18', 'time' => '06:55:00', 'type' => 'in'],
                ['date' => '2026-10-18', 'time' => '14:00:00', 'type' => 'punch'],
                ['date' => '2026-10-18', 'time' => '15:00:00', 'type' => 'punch'],
                ['date' => '2026-10-19', 'time' => '05:00:00', 'type' => 'out'],
            ],
        ]);

        $this->assertSame(0.0, $record->veladaReturnGapHours());
        // 22.08 h corridas − 7 de velada = 15.08 h → 2 unidades.
        $this->assertSame(2, app(WeekendAuthorizationUnitService::class)->backedUnitsFor($employee, $record));
    }

    public function test_long_gap_without_velada_is_not_discounted(): void
    {
        $employee = $this->almacenEmployee();
        $record = AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-10-18',
            'check_in' => '07:00',
            'check_out' => '19:00',
            'velada_hours' => 0,
            'is_weekend_work' => true,
            'raw_punches' => [
                ['date' => '2026-10-18', 'time' => '07:00:00', 'type' => 'in'],
                ['date' => '2026-10-18', 'time' => '10:00:00', 'type' => 'punch'],
                ['date' => '2026-10-18', 'time' => '13:00:00', 'type' => 'punch'],
                ['date' => '2026-10-18', 'time' => '19:00:00', 'type' => 'out'],
            ],
        ]);

        $this->assertSame(12.0, $record->weekendBaseHours());
        $this->assertSame(2, app(WeekendAuthorizationUnitService::class)->backedUnitsFor($employee, $record));
    }
}
