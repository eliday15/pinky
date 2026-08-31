<?php

namespace Tests\Feature\Payroll;

use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Services\CompensationRateResolverService;
use App\Services\PayrollCalculatorService;
use Illuminate\Support\Collection;
use Tests\FeatureTestCase;

/**
 * One-time compensation concepts pay `fixed_amount × authorized quantity`.
 *
 * A concept like "Puntualidad Almacen" is configured as one_time + fixed $1
 * (a per-unit price). The authorized quantity of units/bonos is stored in the
 * authorization's `hours` field (e.g. 600). The payroll must pay 600 × $1 =
 * $600, not a flat $1. When no quantity is set the concept still pays its fixed
 * amount exactly once (backwards compatible).
 */
class OneTimeQuantityConceptTest extends FeatureTestCase
{
    private function resolver(): CompensationRateResolverService
    {
        return app(CompensationRateResolverService::class);
    }

    private function oneTimeType(float $fixed = 1.00): CompensationType
    {
        return CompensationType::factory()->create([
            'name' => 'Puntualidad Almacen',
            'code' => 'PUNT-'.uniqid(),
            'calculation_type' => 'fixed',
            'fixed_amount' => $fixed,
            'application_mode' => CompensationType::APPLICATION_ONE_TIME,
            'authorization_type' => 'special',
            'payment_period' => CompensationType::PAYMENT_PERIOD_WEEKLY,
            'is_active' => true,
        ]);
    }

    private function approvedAuth(Employee $employee, CompensationType $type, float $hours): Authorization
    {
        return Authorization::factory()->create([
            'employee_id' => $employee->id,
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $type->id,
            'hours' => $hours,
            'status' => Authorization::STATUS_APPROVED,
            'start_time' => null,
            'end_time' => null,
        ]);
    }

    /** @param array<int,Authorization> $auths */
    private function compensation(Employee $employee, array $auths): array
    {
        $employee->load('compensationTypes');

        return $this->resolver()->calculateAllCompensation(
            $employee,
            [],
            100.00,
            800.00,
            new Collection($auths),
        );
    }

    public function test_one_time_concept_pays_fixed_amount_times_authorized_quantity(): void
    {
        $employee = Employee::factory()->create(['status' => 'active']);
        $type = $this->oneTimeType(1.00);
        $employee->compensationTypes()->attach($type->id, ['is_active' => true]);

        $auth = $this->approvedAuth($employee, $type, 600.00);

        $result = $this->compensation($employee, [$auth->load('compensationType')]);

        $concept = collect($result['concepts'])->firstWhere('code', $type->code);

        $this->assertNotNull($concept, 'The one-time concept must appear in the breakdown');
        $this->assertEqualsWithDelta(600.00, $concept['amount'], 0.01, '600 bonos × $1 must pay $600');
        $this->assertEqualsWithDelta(600.00, $concept['quantity'], 0.01);
        $this->assertEqualsWithDelta(600.00, $result['total'], 0.01);

        $recalculated = $this->compensation($employee, [$auth->fresh()->load('compensationType')]);
        $this->assertSame($result['total'], $recalculated['total'], 'recalcular materializa el mismo compromiso una sola vez');
    }

    public function test_one_time_concept_with_price_above_one_multiplies_correctly(): void
    {
        $employee = Employee::factory()->create(['status' => 'active']);
        $type = $this->oneTimeType(2.50);
        $employee->compensationTypes()->attach($type->id, ['is_active' => true]);

        $auth = $this->approvedAuth($employee, $type, 300.00);

        $result = $this->compensation($employee, [$auth->load('compensationType')]);
        $concept = collect($result['concepts'])->firstWhere('code', $type->code);

        $this->assertEqualsWithDelta(750.00, $concept['amount'], 0.01, '300 × $2.50 = $750');
    }

    public function test_one_time_concept_without_quantity_pays_fixed_amount_once(): void
    {
        $employee = Employee::factory()->create(['status' => 'active']);
        $type = $this->oneTimeType(1340.00); // e.g. Vales de Despensa, a flat lump
        $employee->compensationTypes()->attach($type->id, ['is_active' => true]);

        // No quantity captured on the authorization (hours = 0).
        $auth = $this->approvedAuth($employee, $type, 0.00);

        $result = $this->compensation($employee, [$auth->load('compensationType')]);
        $concept = collect($result['concepts'])->firstWhere('code', $type->code);

        $this->assertNotNull($concept);
        $this->assertEqualsWithDelta(1340.00, $concept['amount'], 0.01, 'No quantity → pay fixed amount once');
    }

    public function test_one_time_concept_with_negative_quantity_pays_a_deduction(): void
    {
        $employee = Employee::factory()->create(['status' => 'active']);
        $type = $this->oneTimeType(1.00);
        $employee->compensationTypes()->attach($type->id, ['is_active' => true]);

        // Cantidad negativa = descuento (Adriana 2026-07-14): -763 × $1 = -$763.
        $auth = $this->approvedAuth($employee, $type, -763.00);

        $result = $this->compensation($employee, [$auth->load('compensationType')]);
        $concept = collect($result['concepts'])->firstWhere('code', $type->code);

        $this->assertNotNull($concept, 'The deduction must appear in the breakdown');
        $this->assertEqualsWithDelta(-763.00, $concept['amount'], 0.01, '-763 × $1 must deduct $763');
        $this->assertEqualsWithDelta(-763.00, $concept['quantity'], 0.01);
        $this->assertEqualsWithDelta(-763.00, $result['total'], 0.01);
    }

    public function test_negative_quantity_deduction_nets_against_other_concepts(): void
    {
        $employee = Employee::factory()->create(['status' => 'active']);
        $bonus = $this->oneTimeType(1.00);
        $discount = $this->oneTimeType(1.00);
        $employee->compensationTypes()->attach($bonus->id, ['is_active' => true]);
        $employee->compensationTypes()->attach($discount->id, ['is_active' => true]);

        $bonusAuth = $this->approvedAuth($employee, $bonus, 1000.00);
        $discountAuth = $this->approvedAuth($employee, $discount, -400.00);

        $result = $this->compensation($employee, [
            $bonusAuth->load('compensationType'),
            $discountAuth->load('compensationType'),
        ]);

        $this->assertEqualsWithDelta(600.00, $result['total'], 0.01, '$1000 bono - $400 descuento = $600');
    }

    public function test_negative_quantity_discount_deducts_from_cash_in_weekly_payroll(): void
    {
        // Empleado en efectivo: base 800×7=5600. Descuento autorizado de monto
        // único (-763 × $1) → sale del efectivo como deducción de concepto:
        // efectivo/neto 4837, y el desglose conserva el concepto con su signo.
        $employee = Employee::factory()->create([
            'status' => 'active',
            'daily_salary' => 800.00,
            'hourly_rate' => 100.00,
            'hire_date' => '2025-01-01',
            'is_trial_period' => true,
            'is_imss_enrolled' => false,
        ]);
        $type = $this->oneTimeType(1.00);
        $employee->compensationTypes()->attach($type->id, ['is_active' => true]);

        $period = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
        ]);

        Authorization::factory()->create([
            'employee_id' => $employee->id,
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $type->id,
            'hours' => -763.00,
            'status' => Authorization::STATUS_APPROVED,
            'date' => '2026-06-03',
            'start_time' => null,
            'end_time' => null,
        ]);

        $entry = app(PayrollCalculatorService::class)->calculateEmployeePayroll($period, $employee);

        $this->assertEqualsWithDelta(4837.00, (float) $entry->net_pay, 0.01, '5600 - 763 = 4837');
        $this->assertEqualsWithDelta(4837.00, (float) $entry->cash_amount, 0.01, 'el descuento sale del efectivo');
        $this->assertEqualsWithDelta(0.00, (float) $entry->other_compensation_pay, 0.01, 'no distorsiona percepciones');

        $concepts = collect($entry->calculation_breakdown['compensation_concepts'] ?? []);
        $this->assertTrue(
            $concepts->contains(fn ($c) => abs((float) $c['amount'] + 763.0) < 0.01),
            'el descuento aparece en el desglose con su signo',
        );
    }
}
