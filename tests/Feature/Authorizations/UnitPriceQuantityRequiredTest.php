<?php

namespace Tests\Feature\Authorizations;

use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Employee;
use Tests\FeatureTestCase;

/**
 * Monto único con precio por unidad de $1: la cantidad ES el monto. Dejarla en
 * blanco pagaba $1 en silencio (Luis 2026-10-29: 22 bonos por carga de trabajo
 * del 27/10 capturados por masiva sin monto). Los conceptos con monto propio
 * por empleado siguen aceptando la cantidad vacía = una vez su monto.
 */
class UnitPriceQuantityRequiredTest extends FeatureTestCase
{
    private function unitPriceConcept(): CompensationType
    {
        return CompensationType::factory()->fixed(1)->create([
            'name' => 'Bono por carga de trabajo',
            'code' => 'ALM',
            'application_mode' => CompensationType::APPLICATION_ONE_TIME,
            'authorization_type' => Authorization::TYPE_SPECIAL,
        ]);
    }

    public function test_bulk_rejects_blank_quantity_on_unit_price_concept(): void
    {
        $this->actingAsAdmin();
        $concept = $this->unitPriceConcept();
        $emp = Employee::factory()->create();

        $this->from(route('authorizations.createBulk'))->post(route('authorizations.storeBulk'), [
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $concept->id,
            'reason' => 'BONO POR CARGA DE TRABAJO',
            'employee_ids' => [$emp->id],
            'date' => '2026-10-27',
            'hours' => '',
        ])->assertSessionHasErrors('hours');

        $this->assertSame(0, Authorization::count());
    }

    public function test_bulk_entries_reject_blank_quantity_per_row(): void
    {
        $this->actingAsAdmin();
        $concept = $this->unitPriceConcept();
        $emp = Employee::factory()->create();
        $other = Employee::factory()->create();

        $this->from(route('authorizations.createBulk'))->post(route('authorizations.storeBulk'), [
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $concept->id,
            'reason' => 'BONO POR CARGA DE TRABAJO',
            'entries' => [
                ['employee_id' => $emp->id, 'date' => '2026-10-27', 'hours' => 500],
                ['employee_id' => $other->id, 'date' => '2026-10-27', 'hours' => null],
            ],
        ])->assertSessionHasErrors('entries.1');

        $this->assertSame(0, Authorization::count(), 'el lote completo se rechaza, sin altas parciales');
    }

    public function test_bulk_accepts_quantity_on_unit_price_concept(): void
    {
        $this->actingAsAdmin();
        $concept = $this->unitPriceConcept();
        $emp = Employee::factory()->create();

        $this->from(route('authorizations.createBulk'))->post(route('authorizations.storeBulk'), [
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $concept->id,
            'reason' => 'BONO POR CARGA DE TRABAJO',
            'employee_ids' => [$emp->id],
            'date' => '2026-10-27',
            'hours' => 500,
        ])->assertSessionHasNoErrors();

        $this->assertEquals(500, (float) Authorization::sole()->hours);
    }

    public function test_blank_quantity_still_allowed_when_employee_has_own_amount(): void
    {
        $this->actingAsAdmin();
        $concept = $this->unitPriceConcept();
        $emp = Employee::factory()->create();
        $emp->compensationTypes()->attach($concept->id, ['custom_fixed_amount' => 300, 'is_active' => true]);

        $this->from(route('authorizations.createBulk'))->post(route('authorizations.storeBulk'), [
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $concept->id,
            'reason' => 'Bono Diseño',
            'employee_ids' => [$emp->id],
            'date' => '2026-10-27',
            'hours' => '',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Authorization::count());
    }

    public function test_single_store_rejects_blank_quantity_on_unit_price_concept(): void
    {
        $this->actingAsAdmin();
        $concept = $this->unitPriceConcept();
        $emp = Employee::factory()->create();

        $this->from(route('authorizations.create'))->post(route('authorizations.store'), [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $concept->id,
            'date' => '2026-10-27',
            'reason' => 'BONO POR CARGA DE TRABAJO',
        ])->assertSessionHasErrors('hours');

        $this->assertSame(0, Authorization::count());
    }
}
