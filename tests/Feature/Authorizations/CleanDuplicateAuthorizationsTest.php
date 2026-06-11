<?php

namespace Tests\Feature\Authorizations;

use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Employee;
use App\Models\User;
use Tests\FeatureTestCase;

/**
 * Feature tests for authorizations:clean-duplicate-concepts — removes per-day
 * concept rows duplicated for the same employee/day/concept (which payroll
 * would otherwise pay twice), keeping the strongest/earliest and never touching
 * a PAID row.
 */
class CleanDuplicateAuthorizationsTest extends FeatureTestCase
{
    private function perDayType(): CompensationType
    {
        return CompensationType::factory()->create([
            'name' => 'Cena',
            'application_mode' => CompensationType::APPLICATION_PER_DAY,
            'authorization_type' => 'special',
            'attendance_pull_rule' => CompensationType::PULL_RULE_MEAL,
        ]);
    }

    private function concept(Employee $employee, CompensationType $type, string $status, User $approver): Authorization
    {
        return Authorization::factory()->special()->create([
            'employee_id' => $employee->id,
            'requested_by' => User::factory()->create()->id,
            'approved_by' => in_array($status, [Authorization::STATUS_APPROVED, Authorization::STATUS_PAID], true) ? $approver->id : null,
            'compensation_type_id' => $type->id,
            'date' => '2026-06-06',
            'status' => $status,
            'hours' => 1,
        ]);
    }

    public function test_fix_rejects_the_duplicate_and_keeps_the_earliest(): void
    {
        $admin = $this->adminUser();
        $type = $this->perDayType();
        $employee = Employee::factory()->create();
        $keep = $this->concept($employee, $type, Authorization::STATUS_APPROVED, $admin);
        $dupe = $this->concept($employee, $type, Authorization::STATUS_APPROVED, $admin);

        $this->artisan('authorizations:clean-duplicate-concepts', ['--fix' => true])->assertSuccessful();

        $this->assertSame(Authorization::STATUS_APPROVED, $keep->refresh()->status);
        $this->assertSame(Authorization::STATUS_REJECTED, $dupe->refresh()->status);
    }

    public function test_report_only_does_not_change_anything(): void
    {
        $admin = $this->adminUser();
        $type = $this->perDayType();
        $employee = Employee::factory()->create();
        $a = $this->concept($employee, $type, Authorization::STATUS_APPROVED, $admin);
        $b = $this->concept($employee, $type, Authorization::STATUS_APPROVED, $admin);

        $this->artisan('authorizations:clean-duplicate-concepts')->assertSuccessful();

        $this->assertSame(Authorization::STATUS_APPROVED, $a->refresh()->status);
        $this->assertSame(Authorization::STATUS_APPROVED, $b->refresh()->status);
    }

    public function test_a_paid_duplicate_is_never_rejected(): void
    {
        $admin = $this->adminUser();
        $type = $this->perDayType();
        $employee = Employee::factory()->create();
        $paidA = $this->concept($employee, $type, Authorization::STATUS_PAID, $admin);
        $paidB = $this->concept($employee, $type, Authorization::STATUS_PAID, $admin);

        $this->artisan('authorizations:clean-duplicate-concepts', ['--fix' => true])->assertSuccessful();

        $this->assertSame(Authorization::STATUS_PAID, $paidA->refresh()->status);
        $this->assertSame(Authorization::STATUS_PAID, $paidB->refresh()->status);
    }

    public function test_keeps_paid_and_rejects_approved_duplicate(): void
    {
        $admin = $this->adminUser();
        $type = $this->perDayType();
        $employee = Employee::factory()->create();
        $paid = $this->concept($employee, $type, Authorization::STATUS_PAID, $admin);
        $approved = $this->concept($employee, $type, Authorization::STATUS_APPROVED, $admin);

        $this->artisan('authorizations:clean-duplicate-concepts', ['--fix' => true])->assertSuccessful();

        $this->assertSame(Authorization::STATUS_PAID, $paid->refresh()->status);
        $this->assertSame(Authorization::STATUS_REJECTED, $approved->refresh()->status);
    }

    public function test_no_duplicates_succeeds_cleanly(): void
    {
        $admin = $this->adminUser();
        $type = $this->perDayType();
        $employee = Employee::factory()->create();
        $this->concept($employee, $type, Authorization::STATUS_APPROVED, $admin);

        $this->artisan('authorizations:clean-duplicate-concepts', ['--fix' => true])->assertSuccessful();
    }
}
