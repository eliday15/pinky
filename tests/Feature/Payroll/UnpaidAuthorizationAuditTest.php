<?php

namespace Tests\Feature\Payroll;

use App\Models\Authorization;
use App\Models\CheckOmission;
use App\Models\CompensationType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\UnpaidAuthorizationAuditService;
use Tests\FeatureTestCase;

/**
 * "Aprobado y sin nómina que lo pague" (Elias 2026-08-26: arreglar de fondo).
 *
 * Un concepto se paga en el periodo de su alcance: los SEMANALES con el sueldo
 * base y los MENSUALES con los extras. Si falta ese periodo, la autorización se
 * queda aprobada y sin pagar — le pasó a Taller al dejar de llevar mensual.
 * Este detector lo saca a la luz en la nómina en vez de dejarlo pasar.
 */
class UnpaidAuthorizationAuditTest extends FeatureTestCase
{
    private const WEEK_START = '2026-08-17';

    private const WEEK_END = '2026-08-23';

    private function audit(): UnpaidAuthorizationAuditService
    {
        return app(UnpaidAuthorizationAuditService::class);
    }

    private function concept(string $paymentPeriod, string $code): CompensationType
    {
        return CompensationType::factory()->fixed(100)->create([
            'code' => $code,
            'name' => 'Concepto '.$code,
            'application_mode' => CompensationType::APPLICATION_ONE_TIME,
            'payment_period' => $paymentPeriod,
            'authorization_type' => Authorization::TYPE_SPECIAL,
        ]);
    }

    private function approvedFor(Employee $employee, CompensationType $type, string $date): Authorization
    {
        return Authorization::factory()->for($employee)->create([
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $type->id,
            'date' => $date,
            'hours' => 1,
            'status' => Authorization::STATUS_APPROVED,
            'approved_by' => User::factory(),
        ]);
    }

    private function weekly(?Department $department = null): PayrollPeriod
    {
        return PayrollPeriod::factory()->weekly()->create([
            'department_id' => $department?->id,
            'start_date' => self::WEEK_START,
            'end_date' => self::WEEK_END,
        ]);
    }

    public function test_a_monthly_concept_without_a_monthly_payroll_is_reported(): void
    {
        // Caso Taller: su nómina es solo semanal, así que un concepto mensual
        // aprobado no lo paga nadie.
        $taller = Department::factory()->separatePayroll()->create(['name' => 'Taller Adriana']);
        $employee = Employee::factory()->create(['status' => 'active', 'department_id' => $taller->id]);
        $mensual = $this->concept(CompensationType::PAYMENT_PERIOD_MONTHLY, 'MEN');
        $this->approvedFor($employee, $mensual, '2026-08-20');

        $alerts = $this->audit()->forPeriod($this->weekly($taller));

        $this->assertCount(1, $alerts);
        $this->assertSame($employee->full_name, $alerts[0]['employee']);
        $this->assertSame('Concepto MEN', $alerts[0]['concept']);
        $this->assertStringContainsString('extras del mes', $alerts[0]['reason']);
    }

    public function test_nothing_is_reported_when_a_period_does_pay_it(): void
    {
        // La misma autorización, pero con su nómina de extras generada: no hay
        // nada que reportar (la paga la otra, no ésta).
        $employee = Employee::factory()->create(['status' => 'active']);
        $mensual = $this->concept(CompensationType::PAYMENT_PERIOD_MONTHLY, 'MEN');
        $this->approvedFor($employee, $mensual, '2026-08-20');

        PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-07-27',
            'end_date' => self::WEEK_END,
        ]);

        $this->assertSame([], $this->audit()->forPeriod($this->weekly()));
    }

    public function test_a_unified_period_covers_the_month_so_nothing_is_reported(): void
    {
        // El pago unificado paga base Y extras: cubre a los dos alcances.
        $employee = Employee::factory()->create(['status' => 'active']);
        $mensual = $this->concept(CompensationType::PAYMENT_PERIOD_MONTHLY, 'MEN');
        $this->approvedFor($employee, $mensual, '2026-08-04');

        $unified = PayrollPeriod::factory()->unified('2026-07-27', self::WEEK_END)->create([
            'start_date' => self::WEEK_START,
            'end_date' => self::WEEK_END,
        ]);

        $this->assertSame([], $this->audit()->forPeriod($unified));
    }

    public function test_a_weekly_concept_outside_every_weekly_payroll_is_reported(): void
    {
        // También al revés: un concepto semanal cuya semana nunca se generó.
        $employee = Employee::factory()->create(['status' => 'active']);
        $semanal = $this->concept(CompensationType::PAYMENT_PERIOD_WEEKLY, 'SEM');
        // Cae en el rango de EXTRAS del unificado, pero fuera de su semana base
        // y sin una nómina semanal de esas fechas.
        $this->approvedFor($employee, $semanal, '2026-08-04');

        $unified = PayrollPeriod::factory()->unified('2026-07-27', self::WEEK_END)->create([
            'start_date' => self::WEEK_START,
            'end_date' => self::WEEK_END,
        ]);

        $alerts = $this->audit()->forPeriod($unified);

        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('sueldo base', $alerts[0]['reason']);
    }

    public function test_the_payroll_page_surfaces_the_alert(): void
    {
        $taller = Department::factory()->separatePayroll()->create(['name' => 'Taller Adriana']);
        $employee = Employee::factory()->create(['status' => 'active', 'department_id' => $taller->id]);
        $mensual = $this->concept(CompensationType::PAYMENT_PERIOD_MONTHLY, 'MEN');
        $this->approvedFor($employee, $mensual, '2026-08-20');
        $period = $this->weekly($taller);

        $this->actingAsAdmin();

        $this->get(route('payroll.show', $period))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('unpaidAuthorizationAlerts.0.employee', $employee->full_name)
                ->etc());
    }

    public function test_a_new_hire_with_approved_days_before_the_hire_date_is_reported(): void
    {
        // Caso Juan José López (Luis 2026-08-26): nuevo, apenas dado de alta en
        // el checador, con omisiones aprobadas del 19, 20 y 21 y fecha de
        // ingreso el 24 — la nómina le pagaba 2 días de la semana 19-25.
        $employee = Employee::factory()->create([
            'status' => 'active',
            'hire_date' => '2026-08-21',
        ]);

        foreach (['2026-08-17', '2026-08-18'] as $date) {
            CheckOmission::create([
                'employee_id' => $employee->id,
                'work_date' => $date,
                'reason' => CheckOmission::REASON_OTHER,
                'comments' => 'Nuevo empleado',
                'status' => CheckOmission::STATUS_APPROVED,
                'created_by' => User::factory()->create()->id,
            ]);
        }

        $alerts = $this->audit()->hireDateConflicts($this->weekly());

        $this->assertCount(1, $alerts);
        $this->assertSame($employee->full_name, $alerts[0]['employee']);
        $this->assertSame('2026-08-21', $alerts[0]['hire_date']);
        $this->assertSame(2, $alerts[0]['approved_before']);
        $this->assertSame('2026-08-17', $alerts[0]['first_date']);
    }

    public function test_a_new_hire_without_anything_approved_before_is_not_reported(): void
    {
        // Alta a media semana sin días aprobados antes: es un prorrateo normal,
        // no hay nada que revisar.
        Employee::factory()->create(['status' => 'active', 'hire_date' => '2026-08-21']);

        $this->assertSame([], $this->audit()->hireDateConflicts($this->weekly()));
    }

    public function test_employees_of_another_scope_are_not_reported(): void
    {
        // La general no reporta lo de Taller (ni al revés): cada quien su gente.
        $taller = Department::factory()->separatePayroll()->create(['name' => 'Taller Adriana']);
        $tallerEmployee = Employee::factory()->create(['status' => 'active', 'department_id' => $taller->id]);
        $mensual = $this->concept(CompensationType::PAYMENT_PERIOD_MONTHLY, 'MEN');
        $this->approvedFor($tallerEmployee, $mensual, '2026-08-20');

        $this->assertSame([], $this->audit()->forPeriod($this->weekly()), 'la general no ve a Taller');
    }
}
