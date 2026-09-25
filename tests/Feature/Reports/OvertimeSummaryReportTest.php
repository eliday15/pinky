<?php

namespace Tests\Feature\Reports;

use App\Exports\OvertimeSummaryExport;
use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Employee;
use App\Services\Reports\OvertimeSummaryReportService;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\FeatureTestCase;

class OvertimeSummaryReportTest extends FeatureTestCase
{
    private function concept(string $code, float $amount, string $mode = 'per_day', string $type = 'special', ?string $pull = null): CompensationType
    {
        return CompensationType::updateOrCreate(['code' => $code], [
            'name' => $code, 'calculation_type' => 'fixed', 'fixed_amount' => $amount,
            'application_mode' => $mode, 'authorization_type' => $type,
            'attendance_pull_rule' => $pull, 'is_active' => true,
        ]);
    }

    private function authorize(Employee $employee, CompensationType $concept, string $date, float $hours = 1, string $status = 'approved', ?string $type = null): Authorization
    {
        return Authorization::factory()->create([
            'employee_id' => $employee->id, 'compensation_type_id' => $concept->id,
            'date' => $date, 'hours' => $hours, 'type' => $type ?? $concept->authorization_type,
            'status' => $status,
        ]);
    }

    private function report(Employee ...$employees): array
    {
        return app(OvertimeSummaryReportService::class)->build(collect($employees)->pluck('id'), Carbon::parse('2026-08-24'), Carbon::parse('2026-09-30'));
    }

    public function test_all_authorized_concepts_across_weeks_include_employees_without_overtime_and_match_export(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->create();
        $dinnerOnly = Employee::factory()->create();
        $he = $this->concept('HE', 100, 'per_hour', 'overtime');
        $vel = $this->concept('VEL', 200, 'per_day', 'night_shift');
        $cena = $this->concept('CENA', 60);
        $com = $this->concept('COM', 50);
        $fin = $this->concept('FIN', 300, 'per_day', 'special', CompensationType::PULL_RULE_WEEKEND);
        $bono = $this->concept('BONO', 1, 'one_time');
        $this->authorize($employee, $he, '2026-08-25', 2);
        $this->authorize($employee, $he, '2026-09-15', 3, 'paid');
        $this->authorize($employee, $vel, '2026-09-16', 8);
        $this->authorize($employee, $com, '2026-09-17');
        $this->authorize($employee, $fin, '2026-09-19');
        $this->authorize($employee, $bono, '2026-09-20', 600);
        $this->authorize($employee, $bono, '2026-09-21', -100);
        $this->authorize($dinnerOnly, $cena, '2026-09-01');
        $this->authorize($dinnerOnly, $cena, '2026-09-08');
        $this->authorize($dinnerOnly, $cena, '2026-09-09', 1, 'pending');
        $this->authorize($dinnerOnly, $cena, '2026-09-10', 1, 'rejected');
        $this->authorize($dinnerOnly, $cena, '2026-10-01');
        $report = $this->report($employee, $dinnerOnly);
        $this->assertSame(1670.0, $report['summary']['total_estimated_cost']);
        $this->assertCount(6, $report['summary']['concepts']);
        $rows = collect($report['byEmployee'])->keyBy('employee.id');
        $this->assertSame(120.0, $rows[$dinnerOnly->id]['estimated_cost']);
        $this->assertSame(0.0, $rows[$dinnerOnly->id]['total_authorized']);
        $export = (new OvertimeSummaryExport($report))->array();
        $this->assertSame(1670.0, end($export)[9]);
        $this->assertSame(1550.0, $rows[$employee->id]['estimated_cost']);
        $this->get(route('reports.overtime', ['start_date' => '2026-08-24', 'end_date' => '2026-09-30']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('summary.total_estimated_cost', 1670)->has('byEmployee', 2));
        $this->get(route('reports.overtime', ['start_date' => '2026-08-24', 'end_date' => '2026-09-30', 'export' => 'xlsx']))
            ->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_overlapping_approved_hours_are_not_counted_twice_and_attendance_is_not_added(): void
    {
        $employee = Employee::factory()->create();
        $he = $this->concept('HE', 100, 'per_hour', 'overtime');
        $this->authorize($employee, $he, '2026-09-01', 2);
        $this->authorize($employee, $he, '2026-09-01', 2);
        AttendanceRecord::factory()->create(['employee_id' => $employee->id, 'work_date' => '2026-09-01', 'overtime_authorized_hours' => 10]);
        $report = $this->report($employee);
        $this->assertSame(2.0, $report['summary']['total_authorized_hours']);
        $this->assertSame(200.0, $report['summary']['total_estimated_cost']);
    }

    public function test_rejected_modern_authorization_blocks_stale_attendance_over_entire_range(): void
    {
        $employee = Employee::factory()->create();
        $he = $this->concept('HE', 100, 'per_hour', 'overtime');
        $employee->compensationTypes()->attach($he->id, ['is_active' => true]);
        $this->authorize($employee, $he, '2026-09-01', 2, 'rejected');
        AttendanceRecord::factory()->create(['employee_id' => $employee->id, 'work_date' => '2026-09-08', 'overtime_authorized_hours' => 5]);
        $report = $this->report($employee);
        $this->assertSame(0.0, $report['summary']['total_authorized_hours']);
        $this->assertSame(0.0, $report['summary']['total_estimated_cost']);
    }

    public function test_night_shift_hourly_commitment_is_not_recapped_by_timecard(): void
    {
        $employee = Employee::factory()->create();
        $vel = $this->concept('VEL', 100, 'per_hour', 'night_shift');
        $this->authorize($employee, $vel, '2026-09-01', 8);
        AttendanceRecord::factory()->create(['employee_id' => $employee->id, 'work_date' => '2026-09-01', 'velada_hours' => 1, 'overtime_hours' => 0]);
        $this->assertSame(800.0, $this->report($employee)['summary']['total_estimated_cost']);
    }

    public function test_weekend_authorization_using_overtime_type_does_not_consume_he_or_pay_twice(): void
    {
        $employee = Employee::factory()->create();
        $fin = $this->concept('FIN', 300, 'per_day', 'overtime', CompensationType::PULL_RULE_WEEKEND);
        $he = $this->concept('HE', 100, 'per_hour', 'overtime');
        $this->authorize($employee, $fin, '2026-09-05');
        $this->authorize($employee, $he, '2026-09-07', 2);
        $report = $this->report($employee);
        $this->assertSame(2.0, $report['summary']['total_authorized_hours']);
        $this->assertSame(500.0, $report['summary']['total_estimated_cost']);
    }

    public function test_month_export_preserves_own_employee_scope(): void
    {
        $user = $this->actingAsEmployee();
        $own = $this->attachEmployee($user);
        $other = Employee::factory()->create();
        $cena = $this->concept('CENA', 60);
        $this->authorize($own, $cena, '2026-09-01');
        $this->authorize($other, $cena, '2026-09-01');
        $this->get(route('reports.overtime', ['start_date' => '2026-09-01', 'end_date' => '2026-09-30']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('byEmployee', 1)->where('byEmployee.0.employee.id', $own->id)->missing('summary.total_estimated_cost')->missing('byEmployee.0.estimated_cost')->missing('byEmployee.0.concepts.0.amount'));
        $this->get(route('reports.overtime', ['start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'export' => 'xlsx']))->assertForbidden();
    }

    public function test_recurring_assignments_are_not_invented_and_missing_prices_are_visible(): void
    {
        $employee = Employee::factory()->create();
        $recurring = $this->concept('REC', 500, 'one_time');
        $recurring->update(['is_recurring' => true]);
        $employee->compensationTypes()->attach($recurring->id, ['is_active' => true]);
        $bono = $this->concept('BONO', 0, 'one_time');
        $this->authorize($employee, $bono, '2026-09-01', 600);
        $report = $this->report($employee);
        $this->assertSame(0.0, $report['summary']['total_estimated_cost']);
        $this->assertTrue($report['summary']['estimate_incomplete']);
        $this->assertCount(1, $report['summary']['concepts']);
        $this->assertSame('BONO', $report['summary']['concepts'][0]['code']);
        $this->assertSame(600.0, $report['summary']['concepts'][0]['quantity']);
        $this->assertTrue($report['summary']['concepts'][0]['missing_rate']);
    }

    public function test_zero_weekend_rate_and_unpriced_overtime_cannot_look_complete(): void
    {
        $employee = Employee::factory()->create();
        $fin = $this->concept('FIN', 0, 'per_day', 'special', CompensationType::PULL_RULE_WEEKEND);
        $this->authorize($employee, $fin, '2026-09-05');
        $this->assertTrue($this->report($employee)['summary']['estimate_incomplete']);
        $employee = Employee::factory()->create();
        $vel = $this->concept('VEL', 100, 'per_hour', 'night_shift');
        $this->authorize($employee, $vel, '2026-09-01', 8);
        Authorization::factory()->approved()->create([
            'employee_id' => $employee->id, 'compensation_type_id' => null,
            'type' => 'overtime', 'date' => '2026-09-02', 'hours' => 4,
        ]);
        $this->assertTrue($this->report($employee)['summary']['estimate_incomplete']);
    }

    public function test_reversed_range_is_rejected(): void
    {
        $this->actingAsAdmin();
        $this->get(route('reports.overtime', ['start_date' => '2026-09-30', 'end_date' => '2026-09-01']))->assertSessionHasErrors('end_date');
    }
}
