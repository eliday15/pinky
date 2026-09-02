<?php

namespace Tests\Feature\MaquilaBonuses;

use App\Models\CompensationType;
use App\Models\Employee;
use App\Services\MaquilaBonusMetricsService;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\FeatureTestCase;

class MaquilaBonusSummaryTest extends FeatureTestCase
{
    public function test_summary_uses_each_employees_effective_rate_instead_of_global_times_count(): void
    {
        $this->actingAsAdmin();

        $concept = CompensationType::factory()->create([
            'name' => 'Maquila mandada',
            'code' => MaquilaBonusMetricsService::CODE_MAQUILA_MANDADA,
            'calculation_type' => 'fixed',
            'fixed_amount' => 0.0055,
            'application_mode' => CompensationType::APPLICATION_ONE_TIME,
        ]);
        $globalEmployee = Employee::factory()->create(['full_name' => 'Tarifa general']);
        $customEmployee = Employee::factory()->create(['full_name' => 'Tarifa particular']);
        $globalEmployee->compensationTypes()->attach($concept->id, ['is_active' => true]);
        $customEmployee->compensationTypes()->attach($concept->id, [
            'is_active' => true,
            'custom_fixed_amount' => 0.0075,
        ]);

        $quantities = array_fill_keys(array_keys(MaquilaBonusMetricsService::catalog()), 0);
        $quantities[MaquilaBonusMetricsService::CODE_MAQUILA_MANDADA] = 210751;
        $this->mock(MaquilaBonusMetricsService::class, function (MockInterface $mock) use ($quantities) {
            $mock->shouldReceive('metricsForMonth')->once()->andReturn($quantities);
            $mock->shouldReceive('cortador2NameFor')->andReturn('');
        });

        $this->get(route('maquila-bonuses.index', ['month' => '2026-08']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('concepts.0.effective_unit_rate_min', 0.0055)
                ->where('concepts.0.effective_unit_rate_max', 0.0075)
                ->where('concepts.0.estimated_payout_min', 1159.13)
                ->where('concepts.0.estimated_payout_max', 1580.63)
                ->where('concepts.0.estimated_total', 2739.76)
                ->has('concepts.0.employee_payouts', 2));
    }
}
