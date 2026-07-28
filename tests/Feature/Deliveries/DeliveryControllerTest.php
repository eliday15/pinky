<?php

namespace Tests\Feature\Deliveries;

use App\Models\DeliveryPeriod;
use App\Models\Employee;
use Tests\FeatureTestCase;

/**
 * Pantalla "Personal de entregas": RRHH/admin elige un rango de fechas y marca
 * quiénes salieron a entregas. El guardado sincroniza (agrega/quita) por rango.
 */
class DeliveryControllerTest extends FeatureTestCase
{
    private const FROM = '2026-06-01';

    private const TO = '2026-06-07';

    public function test_index_renders_with_the_range_and_employees(): void
    {
        $e = Employee::factory()->create(['status' => 'active']);
        DeliveryPeriod::create(['employee_id' => $e->id, 'start_date' => self::FROM, 'end_date' => self::TO]);

        $this->actingAsAdmin();
        $this->get(route('deliveries.index', ['from' => self::FROM, 'to' => self::TO]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Deliveries/Index')
                ->where('from', self::FROM)
                ->where('to', self::TO)
                ->where('markedCount', 1));
    }

    public function test_store_marks_the_selected_employees_for_the_range(): void
    {
        $a = Employee::factory()->create(['status' => 'active']);
        $b = Employee::factory()->create(['status' => 'active']);

        $this->actingAsAdmin();
        $this->post(route('deliveries.store'), [
            'start_date' => self::FROM,
            'end_date' => self::TO,
            'employee_ids' => [$a->id, $b->id],
        ])->assertRedirect();

        $this->assertDatabaseHas('delivery_periods', ['employee_id' => $a->id, 'start_date' => self::FROM, 'end_date' => self::TO]);
        $this->assertDatabaseHas('delivery_periods', ['employee_id' => $b->id, 'start_date' => self::FROM, 'end_date' => self::TO]);
    }

    public function test_store_syncs_removing_the_unchecked_for_that_range(): void
    {
        $a = Employee::factory()->create(['status' => 'active']);
        $b = Employee::factory()->create(['status' => 'active']);
        DeliveryPeriod::create(['employee_id' => $a->id, 'start_date' => self::FROM, 'end_date' => self::TO]);
        DeliveryPeriod::create(['employee_id' => $b->id, 'start_date' => self::FROM, 'end_date' => self::TO]);

        $this->actingAsAdmin();
        $this->post(route('deliveries.store'), [
            'start_date' => self::FROM,
            'end_date' => self::TO,
            'employee_ids' => [$a->id],
        ])->assertRedirect();

        $this->assertDatabaseHas('delivery_periods', ['employee_id' => $a->id, 'start_date' => self::FROM]);
        $this->assertDatabaseMissing('delivery_periods', ['employee_id' => $b->id, 'start_date' => self::FROM]);
    }

    public function test_store_rejects_end_before_start(): void
    {
        $this->actingAsAdmin();
        $this->post(route('deliveries.store'), [
            'start_date' => self::TO,
            'end_date' => self::FROM, // fin antes que inicio
            'employee_ids' => [],
        ])->assertSessionHasErrors('end_date');
    }

    public function test_a_user_without_permission_cannot_access(): void
    {
        $this->actingAs($this->supervisorUser());

        $this->get(route('deliveries.index'))->assertForbidden();
        $this->post(route('deliveries.store'), [
            'start_date' => self::FROM,
            'end_date' => self::TO,
            'employee_ids' => [],
        ])->assertForbidden();
    }
}
