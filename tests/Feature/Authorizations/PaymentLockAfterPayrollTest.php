<?php

namespace Tests\Feature\Authorizations;

use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\User;
use Tests\FeatureTestCase;

/**
 * Candado de nómina generada (Luis 2026-08-06): "una vez generada la nómina
 * que ya no se pueda modificar el pago". Con el periodo que PAGA la
 * autorización ya aprobado/pagado, aprobar/modificar/rechazar queda congelado
 * — solo el superadmin (custodio) puede corregir. Discrimina por tipo de
 * periodo: un concepto semanal se congela con la semanal; los extras
 * (mensuales), con la mensual — cerrar la semanal NO congela el TE.
 */
class PaymentLockAfterPayrollTest extends FeatureTestCase
{
    private function weeklyApproved(): PayrollPeriod
    {
        return PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
            'status' => 'approved',
        ]);
    }

    /** Concepto semanal (como Bono Fijo / Sueldo Semanal). */
    private function weeklyConceptAuth(string $status = Authorization::STATUS_APPROVED): Authorization
    {
        $ct = CompensationType::factory()->create([
            'code' => 'BFX',
            'application_mode' => CompensationType::APPLICATION_ONE_TIME,
            'authorization_type' => Authorization::TYPE_SPECIAL,
            'payment_period' => 'weekly',
        ]);

        return Authorization::factory()->special()->create([
            'employee_id' => Employee::factory()->create()->id,
            'requested_by' => User::factory()->create()->id,
            'compensation_type_id' => $ct->id,
            'date' => '2026-06-03',
            'hours' => 1,
            'status' => $status,
        ]);
    }

    public function test_weekly_approved_period_freezes_weekly_concept_for_admin(): void
    {
        $this->weeklyApproved();
        $auth = $this->weeklyConceptAuth();
        $admin = $this->adminUser();

        $this->assertFalse($admin->can('approve', $auth), 'Modificar congelado con la nómina generada');
        $this->assertFalse($admin->can('reject', $auth), 'revertir también congelado');
    }

    public function test_superadmin_can_still_correct_frozen_payment(): void
    {
        $this->weeklyApproved();
        $auth = $this->weeklyConceptAuth();
        $superadmin = $this->superadminUser();

        $this->assertTrue($superadmin->can('approve', $auth), 'el custodio conserva la corrección');
    }

    public function test_weekly_close_does_not_freeze_monthly_concepts(): void
    {
        // El TE (HE) paga en la MENSUAL: cerrar la semanal no lo congela.
        $this->weeklyApproved();
        $he = CompensationType::factory()->create([
            'code' => 'HE',
            'application_mode' => CompensationType::APPLICATION_PER_HOUR,
            'authorization_type' => Authorization::TYPE_OVERTIME,
            'payment_period' => 'monthly',
        ]);
        $auth = Authorization::factory()->create([
            'employee_id' => Employee::factory()->create()->id,
            'type' => Authorization::TYPE_OVERTIME,
            'compensation_type_id' => $he->id,
            'date' => '2026-06-03',
            'start_time' => '17:00',
            'end_time' => '19:00',
            'hours' => 2.0,
            'status' => Authorization::STATUS_PENDING,
        ]);
        $admin = $this->adminUser();

        $this->assertTrue($admin->can('approve', $auth), 'la mensual sigue abierta: el TE se puede aprobar');
    }

    public function test_monthly_approved_period_freezes_extras(): void
    {
        PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
            'status' => 'approved',
        ]);
        $he = CompensationType::factory()->create([
            'code' => 'HE',
            'application_mode' => CompensationType::APPLICATION_PER_HOUR,
            'authorization_type' => Authorization::TYPE_OVERTIME,
            'payment_period' => 'monthly',
        ]);
        $auth = Authorization::factory()->create([
            'employee_id' => Employee::factory()->create()->id,
            'type' => Authorization::TYPE_OVERTIME,
            'compensation_type_id' => $he->id,
            'date' => '2026-06-03',
            'start_time' => '17:00',
            'end_time' => '19:00',
            'hours' => 2.0,
            'status' => Authorization::STATUS_PENDING,
        ]);
        $admin = $this->adminUser();

        $this->assertFalse($admin->can('approve', $auth), 'mensual generada: extras congelados');
    }

    public function test_review_period_does_not_freeze(): void
    {
        PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
            'status' => 'review',
        ]);
        $auth = $this->weeklyConceptAuth(Authorization::STATUS_PENDING);
        $admin = $this->adminUser();

        $this->assertTrue($admin->can('approve', $auth), 'en revisión todo sigue editable');
    }
}
