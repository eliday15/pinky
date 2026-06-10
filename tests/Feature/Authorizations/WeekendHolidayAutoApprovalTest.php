<?php

namespace Tests\Feature\Authorizations;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\User;
use App\Services\WeekendHolidayAutoApprovalService;
use Tests\FeatureTestCase;

/**
 * Feature tests for the weekend/holiday auto-approval rule (Luis, 2026-06-10):
 * Fin de Semana + Comida (and Día Festivo on holidays) approve automatically
 * when the day has both an entry and an exit punch. Cena (meal) does NOT.
 */
class WeekendHolidayAutoApprovalTest extends FeatureTestCase
{
    private const DATE = '2026-06-06'; // Saturday

    private function service(): WeekendHolidayAutoApprovalService
    {
        return app(WeekendHolidayAutoApprovalService::class);
    }

    /** A weekend-worked attendance record (with entry+exit punches) for $employee. */
    private function weekendRecord(Employee $employee, array $overrides = []): AttendanceRecord
    {
        return AttendanceRecord::factory()->create(array_merge([
            'employee_id' => $employee->id,
            'work_date' => self::DATE,
            'check_in' => '09:00:00',
            'check_out' => '18:00:00',
            'is_weekend_work' => true,
            'raw_punches' => [
                ['time' => '09:00:00', 'type' => 'in'],
                ['time' => '18:00:00', 'type' => 'out'],
            ],
        ], $overrides));
    }

    private function compensationType(string $pullRule): CompensationType
    {
        return CompensationType::factory()->create([
            'application_mode' => 'per_day',
            'authorization_type' => 'special',
            'attendance_pull_rule' => $pullRule,
        ]);
    }

    private function pendingAuth(Employee $employee, array $overrides = []): Authorization
    {
        return Authorization::factory()->special()->create(array_merge([
            'employee_id' => $employee->id,
            'requested_by' => User::factory()->create()->id,
            'date' => self::DATE,
            'status' => Authorization::STATUS_PENDING,
            'start_time' => null,
            'end_time' => null,
            'hours' => 1.00,
        ], $overrides));
    }

    public function test_weekend_concept_with_punches_qualifies(): void
    {
        $employee = Employee::factory()->create();
        $this->weekendRecord($employee);
        $auth = $this->pendingAuth($employee, [
            'compensation_type_id' => $this->compensationType(CompensationType::PULL_RULE_WEEKEND)->id,
        ]);

        $this->assertTrue($this->service()->qualifies($auth));
    }

    public function test_comida_concept_with_punches_qualifies(): void
    {
        $employee = Employee::factory()->create();
        $this->weekendRecord($employee);
        $auth = $this->pendingAuth($employee, [
            'compensation_type_id' => $this->compensationType(CompensationType::PULL_RULE_COMIDA)->id,
        ]);

        $this->assertTrue($this->service()->qualifies($auth));
    }

    public function test_cena_meal_concept_does_not_qualify(): void
    {
        $employee = Employee::factory()->create();
        $this->weekendRecord($employee);
        $auth = $this->pendingAuth($employee, [
            'compensation_type_id' => $this->compensationType(CompensationType::PULL_RULE_MEAL)->id,
        ]);

        $this->assertFalse($this->service()->qualifies($auth));
    }

    public function test_does_not_qualify_without_both_punches(): void
    {
        $employee = Employee::factory()->create();
        $this->weekendRecord($employee, ['check_out' => null]);
        $auth = $this->pendingAuth($employee, [
            'compensation_type_id' => $this->compensationType(CompensationType::PULL_RULE_WEEKEND)->id,
        ]);

        $this->assertFalse($this->service()->qualifies($auth));
    }

    public function test_weekend_concept_does_not_qualify_on_a_non_weekend_non_holiday_day(): void
    {
        $employee = Employee::factory()->create();
        $this->weekendRecord($employee, ['is_weekend_work' => false]);
        $auth = $this->pendingAuth($employee, [
            'compensation_type_id' => $this->compensationType(CompensationType::PULL_RULE_WEEKEND)->id,
        ]);

        $this->assertFalse($this->service()->qualifies($auth));
    }

    public function test_festivo_concept_qualifies_on_a_holiday_with_punches(): void
    {
        Holiday::create(['date' => self::DATE, 'name' => 'Prueba']);
        $employee = Employee::factory()->create();
        // Not weekend work: it qualifies purely because the day is a holiday.
        $this->weekendRecord($employee, ['is_weekend_work' => false]);
        $auth = $this->pendingAuth($employee, ['type' => Authorization::TYPE_HOLIDAY_WORKED]);

        $this->assertTrue($this->service()->qualifies($auth));
    }

    public function test_festivo_concept_does_not_qualify_without_a_holiday(): void
    {
        $employee = Employee::factory()->create();
        $this->weekendRecord($employee, ['is_weekend_work' => false]);
        $auth = $this->pendingAuth($employee, ['type' => Authorization::TYPE_HOLIDAY_WORKED]);

        $this->assertFalse($this->service()->qualifies($auth));
    }

    public function test_auto_approve_marks_authorization_approved(): void
    {
        $approver = $this->adminUser();
        $employee = Employee::factory()->create();
        $this->weekendRecord($employee);
        $auth = $this->pendingAuth($employee, [
            'compensation_type_id' => $this->compensationType(CompensationType::PULL_RULE_WEEKEND)->id,
        ]);

        $this->assertTrue($this->service()->autoApprove($auth, $approver));

        $auth->refresh();
        $this->assertSame(Authorization::STATUS_APPROVED, $auth->status);
        $this->assertSame($approver->id, $auth->approved_by);
    }

    public function test_auto_approve_returns_false_when_not_pending(): void
    {
        $approver = $this->adminUser();
        $employee = Employee::factory()->create();
        $this->weekendRecord($employee);
        $auth = $this->pendingAuth($employee, [
            'status' => Authorization::STATUS_APPROVED,
            'compensation_type_id' => $this->compensationType(CompensationType::PULL_RULE_WEEKEND)->id,
        ]);

        $this->assertFalse($this->service()->autoApprove($auth, $approver));
    }

    public function test_command_approves_qualifying_pending_authorizations(): void
    {
        $this->adminUser(); // signer for the sweep
        $employee = Employee::factory()->create();
        $this->weekendRecord($employee);
        $qualifying = $this->pendingAuth($employee, [
            'compensation_type_id' => $this->compensationType(CompensationType::PULL_RULE_WEEKEND)->id,
        ]);
        $cena = $this->pendingAuth($employee, [
            'compensation_type_id' => $this->compensationType(CompensationType::PULL_RULE_MEAL)->id,
        ]);

        $this->artisan('authorizations:auto-approve-weekend')->assertSuccessful();

        $this->assertSame(Authorization::STATUS_APPROVED, $qualifying->refresh()->status);
        $this->assertSame(Authorization::STATUS_PENDING, $cena->refresh()->status);
    }

    public function test_command_dry_run_does_not_approve(): void
    {
        $this->adminUser();
        $employee = Employee::factory()->create();
        $this->weekendRecord($employee);
        $auth = $this->pendingAuth($employee, [
            'compensation_type_id' => $this->compensationType(CompensationType::PULL_RULE_WEEKEND)->id,
        ]);

        $this->artisan('authorizations:auto-approve-weekend', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(Authorization::STATUS_PENDING, $auth->refresh()->status);
    }
}
