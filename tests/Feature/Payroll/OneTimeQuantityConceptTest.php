<?php

namespace Tests\Feature\Payroll;

use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Employee;
use App\Services\CompensationRateResolverService;
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
}
