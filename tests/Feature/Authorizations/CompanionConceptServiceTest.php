<?php

namespace Tests\Feature\Authorizations;

use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Employee;
use App\Models\User;
use App\Services\CompanionConceptService;
use Tests\FeatureTestCase;

/**
 * Feature tests for the companion concept auto-capture (Luis, 2026-06-10):
 * approving a velada generates a Cena, approving a fin de semana generates a
 * Comida — only when the employee has that concept active in their catalog.
 */
class CompanionConceptServiceTest extends FeatureTestCase
{
    private function service(): CompanionConceptService
    {
        return app(CompanionConceptService::class);
    }

    private function compType(string $pullRule, string $name): CompensationType
    {
        return CompensationType::factory()->create([
            'name' => $name,
            'application_mode' => 'per_day',
            'authorization_type' => 'special',
            'attendance_pull_rule' => $pullRule,
        ]);
    }

    private function enrolledEmployee(CompensationType $type): Employee
    {
        $employee = Employee::factory()->create();
        $employee->compensationTypes()->attach($type->id, ['is_active' => true]);

        return $employee;
    }

    private function approvedVelada(Employee $employee, User $approver): Authorization
    {
        return Authorization::factory()->nightShift()->create([
            'employee_id' => $employee->id,
            'requested_by' => User::factory()->create()->id,
            'approved_by' => $approver->id,
            'status' => Authorization::STATUS_APPROVED,
            'date' => '2026-06-05',
            'hours' => 3,
        ]);
    }

    private function approvedWeekend(Employee $employee, User $approver, CompensationType $weekendType): Authorization
    {
        return Authorization::factory()->special()->create([
            'employee_id' => $employee->id,
            'requested_by' => User::factory()->create()->id,
            'approved_by' => $approver->id,
            'status' => Authorization::STATUS_APPROVED,
            'compensation_type_id' => $weekendType->id,
            'date' => '2026-06-06',
            'start_time' => null,
            'end_time' => null,
            'hours' => 1,
        ]);
    }

    public function test_velada_generates_an_approved_cena_for_enrolled_employee(): void
    {
        $approver = $this->adminUser();
        $cena = $this->compType(CompensationType::PULL_RULE_MEAL, 'Cena');
        $employee = $this->enrolledEmployee($cena);
        $velada = $this->approvedVelada($employee, $approver);

        $companion = $this->service()->captureForApproved($velada);

        $this->assertNotNull($companion);
        $this->assertSame($cena->id, $companion->compensation_type_id);
        $this->assertSame(Authorization::STATUS_APPROVED, $companion->status);
        $this->assertSame($velada->id, $companion->generated_from_authorization_id);
        $this->assertSame($approver->id, $companion->approved_by);
        $this->assertEquals('2026-06-05', $companion->date->toDateString());
    }

    public function test_weekend_generates_an_approved_comida_for_enrolled_employee(): void
    {
        $approver = $this->adminUser();
        $weekendType = $this->compType(CompensationType::PULL_RULE_WEEKEND, 'Fin de Semana');
        // El concepto "Comida" (code COM) ya viene de migraciones con pull rule
        // 'comida' — es el que el servicio resuelve como acompañante del fin de
        // semana (orderBy priority). Usamos ese mismo en vez de crear un duplicado.
        $comida = CompensationType::active()
            ->where('attendance_pull_rule', CompensationType::PULL_RULE_COMIDA)
            ->orderBy('priority')
            ->firstOrFail();
        $employee = $this->enrolledEmployee($comida);
        $weekend = $this->approvedWeekend($employee, $approver, $weekendType);

        $companion = $this->service()->captureForApproved($weekend);

        $this->assertNotNull($companion);
        $this->assertSame($comida->id, $companion->compensation_type_id);
        $this->assertSame(Authorization::STATUS_APPROVED, $companion->status);
        $this->assertSame($weekend->id, $companion->generated_from_authorization_id);
    }

    public function test_no_companion_when_employee_not_enrolled(): void
    {
        $approver = $this->adminUser();
        $this->compType(CompensationType::PULL_RULE_MEAL, 'Cena');
        $employee = Employee::factory()->create(); // not enrolled
        $velada = $this->approvedVelada($employee, $approver);

        $this->assertNull($this->service()->captureForApproved($velada));
    }

    public function test_no_companion_for_a_plain_overtime_authorization(): void
    {
        $approver = $this->adminUser();
        $this->compType(CompensationType::PULL_RULE_MEAL, 'Cena');
        $employee = Employee::factory()->create();
        $overtime = Authorization::factory()->overtime()->create([
            'employee_id' => $employee->id,
            'requested_by' => User::factory()->create()->id,
            'approved_by' => $approver->id,
            'status' => Authorization::STATUS_APPROVED,
        ]);

        $this->assertNull($this->service()->captureForApproved($overtime));
    }

    public function test_companion_is_not_duplicated(): void
    {
        $approver = $this->adminUser();
        $cena = $this->compType(CompensationType::PULL_RULE_MEAL, 'Cena');
        $employee = $this->enrolledEmployee($cena);
        $velada = $this->approvedVelada($employee, $approver);

        $this->assertNotNull($this->service()->captureForApproved($velada));
        $this->assertNull($this->service()->captureForApproved($velada));
        $this->assertSame(1, Authorization::where('generated_from_authorization_id', $velada->id)->count());
    }

    public function test_no_companion_when_a_meal_already_exists_for_the_day(): void
    {
        $approver = $this->adminUser();
        $cena = $this->compType(CompensationType::PULL_RULE_MEAL, 'Cena');
        $employee = $this->enrolledEmployee($cena);
        $velada = $this->approvedVelada($employee, $approver);

        // Ya existe una Cena capturada por otra vía ese mismo día.
        Authorization::factory()->special()->create([
            'employee_id' => $employee->id,
            'requested_by' => User::factory()->create()->id,
            'compensation_type_id' => $cena->id,
            'date' => '2026-06-05',
            'status' => Authorization::STATUS_PENDING,
        ]);

        $this->assertNull($this->service()->captureForApproved($velada));
        $this->assertSame(1, Authorization::where('compensation_type_id', $cena->id)->count());
    }

    public function test_reject_companions_of_rejects_the_generated_companion(): void
    {
        $approver = $this->adminUser();
        $cena = $this->compType(CompensationType::PULL_RULE_MEAL, 'Cena');
        $employee = $this->enrolledEmployee($cena);
        $velada = $this->approvedVelada($employee, $approver);
        $companion = $this->service()->captureForApproved($velada);

        $count = $this->service()->rejectCompanionsOf($velada, $approver, 'Revertida');

        $this->assertSame(1, $count);
        $this->assertSame(Authorization::STATUS_REJECTED, $companion->refresh()->status);
    }

    public function test_backfill_command_creates_missing_companion(): void
    {
        $approver = $this->adminUser();
        $cena = $this->compType(CompensationType::PULL_RULE_MEAL, 'Cena');
        $employee = $this->enrolledEmployee($cena);
        $velada = $this->approvedVelada($employee, $approver);

        $this->artisan('authorizations:backfill-companion-concepts')->assertSuccessful();

        $this->assertDatabaseHas('authorizations', [
            'generated_from_authorization_id' => $velada->id,
            'compensation_type_id' => $cena->id,
            'status' => Authorization::STATUS_APPROVED,
        ]);
    }

    public function test_backfill_dry_run_creates_nothing(): void
    {
        $approver = $this->adminUser();
        $cena = $this->compType(CompensationType::PULL_RULE_MEAL, 'Cena');
        $employee = $this->enrolledEmployee($cena);
        $velada = $this->approvedVelada($employee, $approver);

        $this->artisan('authorizations:backfill-companion-concepts', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, Authorization::where('generated_from_authorization_id', $velada->id)->count());
    }
}
