<?php

namespace Tests\Feature\Authorizations;

use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\CompanionConceptService;
use Tests\FeatureTestCase;

/**
 * Cena automática por tiempo extra (Dani 2026-07-01: "es en automático que se
 * apruebe, sin capturarla aparte"). Al aprobar tiempo extra en un departamento
 * con umbral (cena_min_overtime_hours; CORTE/TELAS = 2.5), si el total de tiempo
 * extra aprobado del día alcanza el umbral, se crea y aprueba sola la Cena. Es
 * dept-gated: no exige que el empleado tenga el concepto inscrito.
 */
class OvertimeCenaCompanionTest extends FeatureTestCase
{
    private function service(): CompanionConceptService
    {
        return app(CompanionConceptService::class);
    }

    private function cenaConcept(): CompensationType
    {
        return CompensationType::factory()->create([
            'name' => 'Cena',
            'application_mode' => 'per_day',
            'authorization_type' => 'special',
            'attendance_pull_rule' => CompensationType::PULL_RULE_MEAL,
        ]);
    }

    private function approvedOvertime(Employee $employee, User $approver, float $hours, string $date = '2026-06-25'): Authorization
    {
        return Authorization::factory()->create([
            'employee_id' => $employee->id,
            'requested_by' => User::factory()->create()->id,
            'approved_by' => $approver->id,
            'status' => Authorization::STATUS_APPROVED,
            'type' => Authorization::TYPE_OVERTIME,
            'compensation_type_id' => null,
            'date' => $date,
            'hours' => $hours,
        ]);
    }

    public function test_overtime_at_or_over_threshold_generates_an_approved_cena_dept_gated(): void
    {
        $approver = $this->adminUser();
        $cena = $this->cenaConcept();
        $dept = Department::factory()->create(['code' => 'CORTE', 'cena_min_overtime_hours' => 2.5]);
        // Empleado NO inscrito en la cena: el umbral del depto es el gate.
        $employee = Employee::factory()->create(['department_id' => $dept->id]);
        $overtime = $this->approvedOvertime($employee, $approver, 3.0);

        $companion = $this->service()->captureForApproved($overtime);

        $this->assertNotNull($companion);
        $this->assertSame($cena->id, $companion->compensation_type_id);
        $this->assertSame(Authorization::STATUS_APPROVED, $companion->status);
        $this->assertSame($overtime->id, $companion->generated_from_authorization_id);
        $this->assertSame($approver->id, $companion->approved_by);
    }

    public function test_overtime_below_threshold_does_not_generate_cena(): void
    {
        $approver = $this->adminUser();
        $this->cenaConcept();
        $dept = Department::factory()->create(['code' => 'CORTE', 'cena_min_overtime_hours' => 2.5]);
        $employee = Employee::factory()->create(['department_id' => $dept->id]);
        $overtime = $this->approvedOvertime($employee, $approver, 2.0);

        $this->assertNull($this->service()->captureForApproved($overtime));
    }

    public function test_overtime_in_department_without_threshold_does_not_generate_cena(): void
    {
        $approver = $this->adminUser();
        $this->cenaConcept();
        $dept = Department::factory()->create(['code' => 'EMPAQUE', 'cena_min_overtime_hours' => null]);
        $employee = Employee::factory()->create(['department_id' => $dept->id]);
        $overtime = $this->approvedOvertime($employee, $approver, 5.0);

        $this->assertNull($this->service()->captureForApproved($overtime));
    }

    public function test_two_overtime_authorizations_that_sum_the_threshold_generate_one_cena(): void
    {
        $approver = $this->adminUser();
        $this->cenaConcept();
        $dept = Department::factory()->create(['code' => 'CORTE', 'cena_min_overtime_hours' => 2.5]);
        $employee = Employee::factory()->create(['department_id' => $dept->id]);

        // Secuencia real: se aprueba y captura el primer TE (1.5h, aún < 2.5 → sin
        // cena); luego el segundo (acumulado 3h >= 2.5 → una sola cena).
        $first = $this->approvedOvertime($employee, $approver, 1.5);
        $this->assertNull($this->service()->captureForApproved($first), 'aún no llega al umbral con 1.5h');

        $second = $this->approvedOvertime($employee, $approver, 1.5);
        $companion = $this->service()->captureForApproved($second);
        $this->assertNotNull($companion, 'con 3h acumuladas se genera la cena');

        // Volver a capturar no duplica (dedup por categoría de concepto).
        $this->assertNull($this->service()->captureForApproved($second));
    }
}
