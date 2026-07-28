<?php

namespace Tests\Feature\Deliveries;

use App\Models\DeliveryWeek;
use App\Models\Employee;
use Tests\FeatureTestCase;

/**
 * Pantalla "Personal de entregas por semana": RRHH/admin marca quiénes salieron
 * a entregas esa semana. El guardado sincroniza (agrega/quita) por semana.
 */
class DeliveryWeekControllerTest extends FeatureTestCase
{
    private const WEEK = '2026-06-01'; // lunes

    public function test_index_renders_with_the_week_and_employees(): void
    {
        $e = Employee::factory()->create(['status' => 'active']);
        DeliveryWeek::create(['employee_id' => $e->id, 'week_start' => self::WEEK]);

        $this->actingAsAdmin();
        $this->get(route('deliveries.index', ['week' => '2026-06-03']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Deliveries/Index')
                ->where('weekStart', self::WEEK)
                ->where('markedCount', 1));
    }

    public function test_store_marks_the_selected_employees_for_the_week(): void
    {
        $a = Employee::factory()->create(['status' => 'active']);
        $b = Employee::factory()->create(['status' => 'active']);

        $this->actingAsAdmin();
        $this->post(route('deliveries.store'), [
            'week_start' => '2026-06-03', // cualquier día → se normaliza al lunes
            'employee_ids' => [$a->id, $b->id],
        ])->assertRedirect();

        $this->assertDatabaseHas('delivery_weeks', ['employee_id' => $a->id, 'week_start' => self::WEEK]);
        $this->assertDatabaseHas('delivery_weeks', ['employee_id' => $b->id, 'week_start' => self::WEEK]);
    }

    public function test_store_syncs_removing_the_unchecked(): void
    {
        $a = Employee::factory()->create(['status' => 'active']);
        $b = Employee::factory()->create(['status' => 'active']);
        DeliveryWeek::create(['employee_id' => $a->id, 'week_start' => self::WEEK]);
        DeliveryWeek::create(['employee_id' => $b->id, 'week_start' => self::WEEK]);

        $this->actingAsAdmin();
        // Solo queda $a marcado → $b se quita.
        $this->post(route('deliveries.store'), [
            'week_start' => self::WEEK,
            'employee_ids' => [$a->id],
        ])->assertRedirect();

        $this->assertDatabaseHas('delivery_weeks', ['employee_id' => $a->id, 'week_start' => self::WEEK]);
        $this->assertDatabaseMissing('delivery_weeks', ['employee_id' => $b->id, 'week_start' => self::WEEK]);
    }

    public function test_store_with_empty_list_clears_the_week(): void
    {
        $a = Employee::factory()->create(['status' => 'active']);
        DeliveryWeek::create(['employee_id' => $a->id, 'week_start' => self::WEEK]);

        $this->actingAsAdmin();
        $this->post(route('deliveries.store'), ['week_start' => self::WEEK, 'employee_ids' => []])
            ->assertRedirect();

        $this->assertDatabaseCount('delivery_weeks', 0);
    }

    public function test_a_user_without_permission_cannot_access(): void
    {
        $this->actingAs($this->supervisorUser());

        $this->get(route('deliveries.index'))->assertForbidden();
        $this->post(route('deliveries.store'), ['week_start' => self::WEEK, 'employee_ids' => []])->assertForbidden();
    }
}
