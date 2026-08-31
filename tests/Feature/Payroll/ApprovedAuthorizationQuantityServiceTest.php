<?php

namespace Tests\Feature\Payroll;

use App\Models\Authorization;
use App\Models\Employee;
use App\Services\ApprovedAuthorizationQuantityService;
use Illuminate\Support\Collection;
use Tests\FeatureTestCase;

class ApprovedAuthorizationQuantityServiceTest extends FeatureTestCase
{
    private function overtime(Employee $employee, float $hours, string $start, string $end): Authorization
    {
        return Authorization::factory()->create([
            'employee_id' => $employee->id,
            'date' => '2026-06-03',
            'type' => Authorization::TYPE_OVERTIME,
            'hours' => $hours,
            'start_time' => $start,
            'end_time' => $end,
            'status' => Authorization::STATUS_APPROVED,
        ]);
    }

    public function test_single_authorization_always_materializes_approved_hours_not_window_duration(): void
    {
        $employee = Employee::factory()->create();
        $authorization = $this->overtime($employee, 3.0, '17:00', '19:00');

        $quantity = app(ApprovedAuthorizationQuantityService::class)->quantity(
            new Collection([$authorization]),
            Authorization::TYPE_OVERTIME,
        );

        $this->assertSame(3.0, $quantity);
    }

    public function test_overlapping_authorizations_use_largest_approved_quantity_once(): void
    {
        $employee = Employee::factory()->create();
        $first = $this->overtime($employee, 1.5, '17:30', '19:00');
        $duplicate = $this->overtime($employee, 1.5, '17:30', '19:00');

        $quantity = app(ApprovedAuthorizationQuantityService::class)->quantity(
            new Collection([$first, $duplicate]),
            Authorization::TYPE_OVERTIME,
        );

        $this->assertSame(1.5, $quantity, 'ventanas solapadas no materializan dos veces el mismo trabajo');
    }

    public function test_partial_overlap_materializes_union_not_largest_row(): void
    {
        $employee = Employee::factory()->create();
        $rows = new Collection([
            $this->overtime($employee, 2.0, '17:00', '19:00'),
            $this->overtime($employee, 2.0, '18:00', '20:00'),
        ]);

        $this->assertSame(3.0, app(ApprovedAuthorizationQuantityService::class)->quantity($rows, Authorization::TYPE_OVERTIME));
    }

    public function test_transitive_overlap_materializes_full_unique_chain(): void
    {
        $employee = Employee::factory()->create();
        $rows = new Collection([
            $this->overtime($employee, 2.0, '17:00', '19:00'),
            $this->overtime($employee, 2.0, '18:00', '20:00'),
            $this->overtime($employee, 2.0, '19:00', '21:00'),
        ]);

        $this->assertSame(4.0, app(ApprovedAuthorizationQuantityService::class)->quantity($rows, Authorization::TYPE_OVERTIME));
    }

    public function test_identical_and_nested_historical_windows_do_not_duplicate_proportional_quantity(): void
    {
        $employee = Employee::factory()->create();
        $rows = new Collection([
            $this->overtime($employee, 3.0, '17:00', '19:00'),
            $this->overtime($employee, 3.0, '17:00', '19:00'),
            $this->overtime($employee, 1.5, '17:30', '18:30'),
        ]);

        $this->assertSame(3.0, app(ApprovedAuthorizationQuantityService::class)->quantity($rows, Authorization::TYPE_OVERTIME));
    }
}
