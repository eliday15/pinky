<?php

namespace Tests\Feature\Payroll;

use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Services\PayrollCalculatorService;
use Illuminate\Support\Facades\DB;
use Tests\FeatureTestCase;

/**
 * Guardia de regresión del N+1 en nómina: calcular un periodo cuesta un número
 * de consultas esencialmente constante (prefetch en lote + catálogos
 * memoizados) más un margen chico por empleado (el upsert de su entry). Si
 * alguien reintroduce consultas por-empleado (asistencia, settings, brackets,
 * tipos de compensación), el costo marginal se dispara y este test truena.
 */
class PayrollQueryEfficiencyTest extends FeatureTestCase
{
    private function makeEmployees(int $count): void
    {
        Employee::factory()->count($count)->create([
            'status' => 'active',
            'daily_salary' => 500.00,
            'hire_date' => '2025-01-01',
            'termination_date' => null,
        ]);
    }

    public function test_marginal_queries_per_employee_stay_bounded(): void
    {
        $period = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-07-06',
            'end_date' => '2026-07-12',
        ]);

        $this->makeEmployees(4);
        DB::enableQueryLog();
        app(PayrollCalculatorService::class)->calculatePeriod($period);
        $small = count(DB::getQueryLog());
        DB::flushQueryLog();

        // Las factories quedan FUERA de la ventana de medición: solo se
        // cuentan las queries del cálculo en sí.
        DB::disableQueryLog();
        $this->makeEmployees(8);
        DB::enableQueryLog();
        DB::flushQueryLog();
        app(PayrollCalculatorService::class)->calculatePeriod($period);
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();

        // 12 empleados vs 4: el delta entre corridas dividido por los 8
        // empleados extra es el costo marginal real por empleado.
        $marginal = ($large - $small) / 8;

        $this->assertLessThanOrEqual(
            5,
            $marginal,
            "El cálculo escala a ~{$marginal} queries por empleado (4 empleados: {$small} queries, 12 empleados: {$large}); se reintrodujo un N+1.",
        );
    }
}
