<?php

namespace Tests\Feature\MaquilaBonuses;

use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Employee;
use App\Services\MaquilaBonusAuthorizationService;
use App\Services\MaquilaBonusMetricsService;
use Mockery\MockInterface;
use Tests\FeatureTestCase;

/**
 * Cada cortador cobra lo que cortó (Luis 2026-10-08: "si cada quien cobra lo
 * que cortó según la lista de cortadores").
 *
 * Antes el concepto tenía UN nombre de cortador y su conteo se le pagaba
 * COMPLETO a cada empleado asignado: con dos operadores, cada uno cobraba
 * también las órdenes del otro.
 */
class MaquilaCortadorPerEmployeeTest extends FeatureTestCase
{
    private function cortadasConcept(): CompensationType
    {
        return CompensationType::factory()->create([
            'name' => 'Órdenes cortadas',
            'code' => MaquilaBonusMetricsService::CODE_ORDENES_CORTADAS,
            'calculation_type' => 'fixed',
            'fixed_amount' => 2.50,
            'application_mode' => CompensationType::APPLICATION_ONE_TIME,
        ]);
    }

    private function assign(Employee $employee, CompensationType $concept): void
    {
        $employee->compensationTypes()->attach($concept->id, ['is_active' => true]);
    }

    public function test_each_cutter_gets_only_his_own_orders(): void
    {
        $concept = $this->cortadasConcept();
        $carlos = Employee::factory()->create(['full_name' => 'Carlos Operador']);
        $juan = Employee::factory()->create(['full_name' => 'Juan Operador']);
        $this->assign($carlos, $concept);
        $this->assign($juan, $concept);

        $metrics = app(MaquilaBonusMetricsService::class);
        $metrics->setCortadorForEmployee($concept->code, $carlos->id, 'CARLOS');
        $metrics->setCortadorForEmployee($concept->code, $juan->id, 'JUAN');

        $quantities = array_fill_keys(array_keys(MaquilaBonusMetricsService::catalog()), 0);
        $quantities[$concept->code] = 300; // conteo global: las de los dos

        $this->mock(MaquilaBonusMetricsService::class, function (MockInterface $mock) use ($concept, $quantities) {
            $mock->shouldReceive('metricsForMonth')->andReturn($quantities);
            $mock->shouldReceive('cortadorForEmployee')->with($concept->code, \Mockery::any())
                ->andReturnUsing(fn ($code, $employeeId) => app()->make('cortador_map')[$employeeId] ?? '');
            $mock->shouldReceive('quantityForCortador')->with($concept->code, 2026, 10, 'CARLOS')->andReturn(200);
            $mock->shouldReceive('quantityForCortador')->with($concept->code, 2026, 10, 'JUAN')->andReturn(100);
        });

        app()->instance('cortador_map', [$carlos->id => 'CARLOS', $juan->id => 'JUAN']);

        app(MaquilaBonusAuthorizationService::class)->generateForMonth(2026, 10, $this->adminUser()->id);

        $this->assertEqualsWithDelta(
            200.0,
            (float) Authorization::where('employee_id', $carlos->id)->value('hours'),
            0.01,
            'Carlos cobra solo sus 200',
        );
        $this->assertEqualsWithDelta(
            100.0,
            (float) Authorization::where('employee_id', $juan->id)->value('hours'),
            0.01,
            'Juan cobra solo sus 100',
        );
    }

    public function test_an_employee_without_a_cutter_gets_nothing_and_is_reported(): void
    {
        $concept = $this->cortadasConcept();
        $sinCortador = Employee::factory()->create(['full_name' => 'Sin Cortador']);
        $this->assign($sinCortador, $concept);

        $quantities = array_fill_keys(array_keys(MaquilaBonusMetricsService::catalog()), 0);
        $quantities[$concept->code] = 300;

        $this->mock(MaquilaBonusMetricsService::class, function (MockInterface $mock) use ($quantities) {
            $mock->shouldReceive('metricsForMonth')->andReturn($quantities);
            $mock->shouldReceive('cortadorForEmployee')->andReturn('');
            $mock->shouldReceive('quantityForCortador')->andReturn(0);
        });

        $summary = app(MaquilaBonusAuthorizationService::class)
            ->generateForMonth(2026, 10, $this->adminUser()->id);

        $this->assertSame(
            0,
            Authorization::where('employee_id', $sinCortador->id)->count(),
            'sin cortador no se le genera nada: no cobra lo que cortaron otros',
        );

        $row = collect($summary)->firstWhere('code', $concept->code);
        $this->assertSame(['Sin Cortador'], $row['without_cortador'], 'se reporta por nombre, no en silencio');
    }

    public function test_the_cutter_map_round_trips(): void
    {
        $metrics = app(MaquilaBonusMetricsService::class);
        $code = MaquilaBonusMetricsService::CODE_ORDENES_CORTADAS;
        $employee = Employee::factory()->create();

        $metrics->setCortadorForEmployee($code, $employee->id, '  CARLOS  ');
        $this->assertSame('CARLOS', $metrics->cortadorForEmployee($code, $employee->id), 'se guarda sin espacios');

        $metrics->setCortadorForEmployee($code, $employee->id, '');
        $this->assertSame('', $metrics->cortadorForEmployee($code, $employee->id), 'vacío = sin asignar');
        $this->assertSame([], $metrics->cortadorMapFor($code));
    }

    public function test_other_bonuses_keep_paying_the_full_count(): void
    {
        // Los conceptos que no son por cortador (maquila mandada, etc.) siguen
        // igual: su conteo se le paga completo a cada empleado asignado.
        $concept = CompensationType::factory()->create([
            'name' => 'Maquila mandada',
            'code' => MaquilaBonusMetricsService::CODE_MAQUILA_MANDADA,
            'calculation_type' => 'fixed',
            'fixed_amount' => 0.0055,
            'application_mode' => CompensationType::APPLICATION_ONE_TIME,
        ]);
        $employee = Employee::factory()->create();
        $this->assign($employee, $concept);

        $quantities = array_fill_keys(array_keys(MaquilaBonusMetricsService::catalog()), 0);
        $quantities[$concept->code] = 210751;

        $this->mock(MaquilaBonusMetricsService::class, function (MockInterface $mock) use ($quantities) {
            $mock->shouldReceive('metricsForMonth')->andReturn($quantities);
            $mock->shouldReceive('cortadorForEmployee')->andReturn('');
            $mock->shouldReceive('quantityForCortador')->andReturn(0);
        });

        app(MaquilaBonusAuthorizationService::class)->generateForMonth(2026, 10, $this->adminUser()->id);

        $this->assertEqualsWithDelta(
            210751.0,
            (float) Authorization::where('employee_id', $employee->id)->value('hours'),
            0.01,
        );
    }
}
