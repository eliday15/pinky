<?php

namespace Tests\Feature\Auth;

use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Schedule;
use App\Models\User;
use Tests\FeatureTestCase;

/**
 * Rol SEMIADMIN (Luis 2026-11-25, para Daniela): da de alta y de baja,
 * autoriza lo humano (TE fuera de checada incluido), pero NADA de dinero: ni
 * lo ve, ni lo captura, ni lo decide.
 */
class SemiadminRoleTest extends FeatureTestCase
{
    private function semiadmin(): User
    {
        $user = $this->createUser('semiadmin');
        $this->actingAs($user);

        return $user;
    }

    private function bonoConcept(): CompensationType
    {
        return CompensationType::factory()->create([
            'name' => 'Premio',
            'code' => 'PREMIO-'.uniqid(),
            'calculation_type' => 'fixed',
            'fixed_amount' => 1,
            'application_mode' => CompensationType::APPLICATION_ONE_TIME,
            'authorization_type' => 'special',
            'payment_period' => CompensationType::PAYMENT_PERIOD_WEEKLY,
            'is_active' => true,
        ]);
    }

    public function test_existing_roles_keep_seeing_money_and_semiadmin_does_not(): void
    {
        foreach (['superadmin', 'admin', 'rrhh', 'supervisor', 'employee'] as $role) {
            $this->assertTrue($this->createUser($role)->canSeeMoney(), "{$role} conserva money.view");
        }
        $this->assertFalse($this->createUser('semiadmin')->canSeeMoney());
    }

    public function test_employee_profile_sends_no_salary_rates_or_bank_data(): void
    {
        $employee = Employee::factory()->create([
            'daily_salary' => 987.65,
            'hourly_rate' => 123.45,
            'monthly_bonus_amount' => 4321,
            'clabe' => '012180001234567891',
        ]);
        $concept = $this->bonoConcept();
        $employee->compensationTypes()->attach($concept->id, ['is_active' => true, 'custom_fixed_amount' => 555.55]);
        $this->semiadmin();

        $response = $this->get(route('employees.show', $employee))->assertOk();
        $json = json_encode($response->viewData('page')['props']);

        foreach (['987.65', '123.45', '4321', '012180001234567891', '555.55', 'daily_salary', 'fixed_amount', 'custom_fixed_amount'] as $leak) {
            $this->assertStringNotContainsString($leak, $json, "no debe viajar: {$leak}");
        }
    }

    public function test_admin_still_receives_the_salary(): void
    {
        $employee = Employee::factory()->create(['daily_salary' => 987.65]);
        $this->actingAsAdmin();

        $this->get(route('employees.show', $employee))
            ->assertInertia(fn ($page) => $page->where('employee.daily_salary', '987.65'));
    }

    public function test_semiadmin_edit_keeps_salary_and_concepts_untouched(): void
    {
        $employee = Employee::factory()->create(['daily_salary' => 800, 'monthly_bonus_type' => 'fixed', 'monthly_bonus_amount' => 500]);
        $concept = $this->bonoConcept();
        $employee->compensationTypes()->attach($concept->id, ['is_active' => true, 'custom_fixed_amount' => 300]);
        $this->semiadmin();

        $this->put(route('employees.update', $employee), [
            'employee_number' => $employee->employee_number,
            'zkteco_user_id' => $employee->zkteco_user_id,
            'first_name' => 'Nombre',
            'last_name' => 'Cambiado',
            'hire_date' => $employee->hire_date->toDateString(),
            'termination_date' => now()->addDay()->toDateString(),
            'department_id' => $employee->department_id,
            'position_id' => $employee->position_id,
            'schedule_id' => $employee->schedule_id,
            'status' => 'terminated',
            'is_attendance_exempt' => true,
            'vacation_days_entitled' => 12,
            'vacation_days_used' => 0,
            // Intentos de tocar dinero: se ignoran.
            'daily_salary' => 99999,
            'monthly_bonus_amount' => 99999,
            'compensation_type_ids' => [],
        ])->assertSessionHasNoErrors();

        $fresh = $employee->fresh();
        $this->assertSame('terminated', $fresh->status, 'la baja sí entra');
        $this->assertSame('Cambiado', $fresh->last_name);
        $this->assertEquals(800, (float) $fresh->daily_salary);
        $this->assertEquals(500, (float) $fresh->monthly_bonus_amount);
        $this->assertSame('fixed', $fresh->monthly_bonus_type);
        $this->assertSame(1, $fresh->compensationTypes()->count(), 'los conceptos no se borran');
    }

    public function test_semiadmin_hire_enters_without_salary_for_admin_to_fill(): void
    {
        $this->semiadmin();

        $this->post(route('employees.store'), [
            'employee_number' => 'SEMI-1',
            'first_name' => 'Nueva',
            'last_name' => 'Persona',
            'hire_date' => '2026-11-25',
            'department_id' => Department::factory()->create()->id,
            'position_id' => Position::factory()->create()->id,
            'schedule_id' => Schedule::factory()->create()->id,
            'is_attendance_exempt' => true,
            'daily_salary' => 5000,
        ])->assertSessionHasNoErrors();

        $hired = Employee::where('employee_number', 'SEMI-1')->firstOrFail();
        $this->assertEquals(0, (float) $hired->daily_salary);
    }

    public function test_semiadmin_cannot_capture_or_decide_money_concepts(): void
    {
        $employee = Employee::factory()->create();
        $bono = $this->bonoConcept();
        $pending = Authorization::factory()->create([
            'employee_id' => $employee->id,
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $bono->id,
            'hours' => 4200,
            'status' => Authorization::STATUS_PENDING,
            'start_time' => null,
            'end_time' => null,
        ]);
        $user = $this->semiadmin();

        $this->assertFalse($user->can('approve', $pending));
        $this->assertFalse($user->can('reject', $pending));

        $this->post(route('authorizations.store'), [
            'employee_id' => $employee->id,
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $bono->id,
            'date' => '2026-11-20',
            'hours' => 100,
            'reason' => 'bono',
        ])->assertSessionHasErrors('compensation_type_id');

        // Y la cantidad del bono (que aquí ES el dinero) no viaja.
        $json = json_encode($this->get(route('authorizations.show', $pending))->viewData('page')['props']);
        $this->assertStringNotContainsString('4200', $json);
    }

    public function test_semiadmin_can_decide_overtime(): void
    {
        $employee = Employee::factory()->create();
        $te = Authorization::factory()->create([
            'employee_id' => $employee->id,
            'type' => Authorization::TYPE_OVERTIME,
            'compensation_type_id' => CompensationType::factory()->create([
                'code' => 'HE-'.uniqid(),
                'application_mode' => CompensationType::APPLICATION_PER_HOUR,
                'authorization_type' => 'overtime',
            ])->id,
            'status' => Authorization::STATUS_PENDING,
        ]);
        $user = $this->semiadmin();

        $this->assertTrue($user->can('approve', $te));
    }

    public function test_money_reports_are_closed_and_prenomina_has_no_amounts(): void
    {
        $this->semiadmin();

        $this->get(route('reports.payroll'))->assertForbidden();
        $this->get(route('reports.payrollTrends'))->assertForbidden();
        $this->get(route('payroll.prenomina', ['week_start' => '2026-08-17', 'week_end' => '2026-08-23', 'without_extras' => 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('withAmounts', false));
        $this->get(route('payroll.index'))->assertForbidden();
    }

    public function test_query_string_cannot_sneak_money_in(): void
    {
        $employee = Employee::factory()->create(['clabe' => null, 'finiquito_amount' => null]);
        $concept = $this->bonoConcept();
        $this->semiadmin();

        $this->put(route('employees.update', $employee).'?clabe=999999999999999999&finiquito_amount=77777&cash_pin=abcd&cash_pin_confirmation=abcd', [
            'employee_number' => $employee->employee_number,
            'zkteco_user_id' => $employee->zkteco_user_id,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'hire_date' => $employee->hire_date->toDateString(),
            'department_id' => $employee->department_id,
            'position_id' => $employee->position_id,
            'schedule_id' => $employee->schedule_id,
            'status' => 'active',
            'is_attendance_exempt' => true,
            'vacation_days_entitled' => 12,
            'vacation_days_used' => 0,
        ])->assertSessionHasNoErrors();
        $fresh = $employee->fresh();
        $this->assertNull($fresh->clabe);
        $this->assertNull($fresh->finiquito_amount);
        $this->assertNull($fresh->cash_pin);

        $this->post(route('employees.store').'?compensation_type_ids[]='.$concept->id.'&compensation_type_overrides['.$concept->id.']=5000', [
            'employee_number' => 'SEMI-Q',
            'first_name' => 'Q',
            'last_name' => 'S',
            'hire_date' => '2026-11-25',
            'department_id' => $employee->department_id,
            'position_id' => $employee->position_id,
            'schedule_id' => $employee->schedule_id,
            'is_attendance_exempt' => true,
        ])->assertSessionHasNoErrors();
        $this->assertSame(0, Employee::where('employee_number', 'SEMI-Q')->firstOrFail()->compensationTypes()->count());
    }

    public function test_semiadmin_cannot_rewrite_or_delete_a_pending_bonus(): void
    {
        $employee = Employee::factory()->create();
        $bono = $this->bonoConcept();
        $other = CompensationType::factory()->create(['code' => 'HE-'.uniqid(), 'application_mode' => CompensationType::APPLICATION_PER_HOUR, 'authorization_type' => 'overtime']);
        $pending = Authorization::factory()->create([
            'employee_id' => $employee->id,
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $bono->id,
            'hours' => 600,
            'status' => Authorization::STATUS_PENDING,
            'start_time' => null,
            'end_time' => null,
        ]);
        $user = $this->semiadmin();

        $this->assertFalse($user->can('update', $pending));
        $this->assertFalse($user->can('delete', $pending));
        $this->put(route('authorizations.update', $pending), ['compensation_type_id' => $other->id, 'type' => Authorization::TYPE_OVERTIME]);
        $this->assertSame($bono->id, $pending->fresh()->compensation_type_id);
    }
}
