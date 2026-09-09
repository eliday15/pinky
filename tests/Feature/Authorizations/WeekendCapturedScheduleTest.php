<?php

namespace Tests\Feature\Authorizations;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Department;
use App\Models\Employee;
use App\Services\WeekendAuthorizationUnitService;
use Illuminate\Support\Collection;
use Tests\FeatureTestCase;

class WeekendCapturedScheduleTest extends FeatureTestCase
{
    public function test_almacen_schedule_is_persisted_as_two_weekend_units_and_reused_downstream(): void
    {
        $this->actingAsAdmin();
        $department = Department::factory()->create(['weekend_unit_hours' => 6]);
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $weekend = CompensationType::factory()->create([
            'authorization_type' => Authorization::TYPE_SPECIAL,
            'application_mode' => CompensationType::APPLICATION_PER_DAY,
            'attendance_pull_rule' => CompensationType::PULL_RULE_WEEKEND,
        ]);

        $this->post(route('authorizations.storeBulk'), [
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $weekend->id,
            'reason' => 'Trabajo de inventario',
            'entries' => [[
                'employee_id' => $employee->id,
                'date' => '2026-09-12',
                'start_time' => '08:00',
                'end_time' => '20:00',
                // El cliente puede mandar duración; el servidor deriva la
                // cantidad FIN con la regla del departamento.
                'hours' => 12,
            ]],
        ])->assertRedirect(route('authorizations.index'));

        $authorization = Authorization::where('employee_id', $employee->id)->sole();
        $this->assertSame('08:00', $authorization->start_time->format('H:i'));
        $this->assertSame('20:00', $authorization->end_time->format('H:i'));
        $this->assertSame('2.00', $authorization->hours);
        $this->assertSame(Authorization::STATUS_APPROVED, $authorization->status);

        $service = app(WeekendAuthorizationUnitService::class);
        $this->assertSame(2, $service->approvedUnits(new Collection([$authorization])));
        $this->assertSame(2, $service->materializedUnits(
            new Collection([$authorization]),
            collect(),
            $employee->load('department'),
        ));
    }

    public function test_weekend_schedule_crossing_midnight_uses_real_elapsed_hours(): void
    {
        $department = Department::factory()->create(['weekend_unit_hours' => 6]);
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $service = app(WeekendAuthorizationUnitService::class);

        $this->assertSame(2, $service->unitsForTimeRange($employee->load('department'), '20:00', '08:00'));
        $this->assertSame(0, $service->unitsForTimeRange($employee, '08:00', '08:00'));
    }

    public function test_captured_twelve_hours_cannot_auto_approve_when_punches_back_only_one_unit(): void
    {
        $this->actingAsAdmin();
        $department = Department::factory()->create(['weekend_unit_hours' => 6]);
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $weekend = CompensationType::factory()->create([
            'authorization_type' => Authorization::TYPE_SPECIAL,
            'application_mode' => CompensationType::APPLICATION_PER_DAY,
            'attendance_pull_rule' => CompensationType::PULL_RULE_WEEKEND,
        ]);
        AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-09-12',
            'check_in' => '08:00',
            'check_out' => '14:00',
            'is_weekend_work' => true,
        ]);

        $this->post(route('authorizations.storeBulk'), [
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $weekend->id,
            'reason' => 'Horario por confirmar',
            'entries' => [[
                'employee_id' => $employee->id,
                'date' => '2026-09-12',
                'start_time' => '08:00',
                'end_time' => '20:00',
                'hours' => 12,
            ]],
        ])->assertRedirect(route('authorizations.index'));

        $authorization = Authorization::where('employee_id', $employee->id)->sole();
        $this->assertSame('1.00', $authorization->hours);
        $this->assertSame(Authorization::STATUS_APPROVED, $authorization->status);
        $this->assertSame(1, app(WeekendAuthorizationUnitService::class)->backedUnits($authorization));
    }

    public function test_normal_department_keeps_threshold_rule_for_captured_schedule(): void
    {
        $department = Department::factory()->create([
            'weekend_unit_hours' => null,
            'weekend_overtime_after_hours' => 7,
        ]);
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $service = app(WeekendAuthorizationUnitService::class);

        $this->assertSame(1, $service->unitsForTimeRange($employee->load('department'), '08:00', '15:00'));
        $this->assertSame(2, $service->unitsForTimeRange($employee, '08:00', '20:00'));
    }

    public function test_legacy_bulk_shape_normalizes_arbitrary_hours_for_each_employee(): void
    {
        $this->actingAsAdmin();
        $department = Department::factory()->create(['weekend_unit_hours' => 6]);
        $employees = Employee::factory()->count(2)->create(['department_id' => $department->id]);
        $weekend = CompensationType::factory()->create([
            'authorization_type' => Authorization::TYPE_SPECIAL,
            'application_mode' => CompensationType::APPLICATION_PER_DAY,
            'attendance_pull_rule' => CompensationType::PULL_RULE_WEEKEND,
        ]);

        $this->post(route('authorizations.storeBulk'), [
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $weekend->id,
            'reason' => 'Rango legado',
            'employee_ids' => $employees->pluck('id')->all(),
            'date' => '2026-09-12',
            'start_time' => '08:00',
            'end_time' => '20:00',
            'hours' => 999,
        ])->assertRedirect(route('authorizations.index'));

        $this->assertSame(['2.00', '2.00'], Authorization::orderBy('id')->pluck('hours')->all());
    }

    public function test_invalid_later_entry_rolls_back_entire_weekend_batch_before_insert(): void
    {
        $this->actingAsAdmin();
        $department = Department::factory()->create(['weekend_unit_hours' => 6]);
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $weekend = CompensationType::factory()->create([
            'authorization_type' => Authorization::TYPE_SPECIAL,
            'application_mode' => CompensationType::APPLICATION_PER_DAY,
            'attendance_pull_rule' => CompensationType::PULL_RULE_WEEKEND,
        ]);

        $this->post(route('authorizations.storeBulk'), [
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $weekend->id,
            'reason' => 'Lote atómico',
            'entries' => [
                ['employee_id' => $employee->id, 'date' => '2026-09-12', 'start_time' => '08:00', 'end_time' => '20:00', 'hours' => 12],
                ['employee_id' => $employee->id, 'date' => '2026-09-13', 'start_time' => '08:00', 'end_time' => '08:00', 'hours' => 999],
            ],
        ])->assertSessionHasErrors('entries.1');

        $this->assertDatabaseCount('authorizations', 0);
    }

    public function test_complete_punches_win_and_subtract_velada_before_persisting_units(): void
    {
        $this->actingAsAdmin();
        $department = Department::factory()->create(['weekend_unit_hours' => 6]);
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $weekend = CompensationType::factory()->create([
            'authorization_type' => Authorization::TYPE_SPECIAL,
            'application_mode' => CompensationType::APPLICATION_PER_DAY,
            'attendance_pull_rule' => CompensationType::PULL_RULE_WEEKEND,
        ]);
        AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-09-12',
            'check_in' => '08:00',
            'check_out' => '20:00',
            'velada_hours' => 6,
            'is_weekend_work' => true,
        ]);

        $this->post(route('authorizations.storeBulk'), [
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $weekend->id,
            'reason' => 'Con velada',
            'entries' => [[
                'employee_id' => $employee->id, 'date' => '2026-09-12',
                'start_time' => '08:00', 'end_time' => '20:00', 'hours' => 999,
            ]],
        ])->assertRedirect(route('authorizations.index'));

        $this->assertSame('1.00', Authorization::sole()->hours);
    }

    public function test_correcting_punches_after_approval_does_not_inflate_snapshot(): void
    {
        $department = Department::factory()->create(['weekend_unit_hours' => 6]);
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $weekend = CompensationType::factory()->create([
            'attendance_pull_rule' => CompensationType::PULL_RULE_WEEKEND,
        ]);
        $authorization = Authorization::factory()->approved()->create([
            'employee_id' => $employee->id,
            'compensation_type_id' => $weekend->id,
            'hours' => 1,
            'date' => '2026-09-12',
        ]);
        $attendance = AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-09-12',
            'check_in' => '08:00',
            'check_out' => '20:00',
            'is_weekend_work' => true,
        ]);

        $this->assertSame(1, app(WeekendAuthorizationUnitService::class)->materializedUnits(
            collect([$authorization]), collect([$attendance]), $employee->load('department'),
        ));
    }
}
