<?php

namespace Tests\Feature\Payroll;

use App\Services\Fiscal\EmployerQuotaCalculatorService;
use Database\Seeders\FiscalSettingsSeeder;
use Tests\FeatureTestCase;

/**
 * Cuotas patronales (Fase 2): fórmulas por rubro, tabla CyV escalonada 2026 y
 * absorción de la cuota obrera del salario mínimo (Art. 36 LSS). El modelo
 * agregado se validó contra el bloque Obligaciones de Contpaq Sem28 (<1% por
 * rubro; CyV Δ$97 de $33,293).
 */
class EmployerQuotaTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FiscalSettingsSeeder::class);
    }

    private function calc(): EmployerQuotaCalculatorService
    {
        return app(EmployerQuotaCalculatorService::class);
    }

    public function test_full_week_quotas_match_formulas(): void
    {
        // SBC 420.26 (3.58 UMA), no mínimo, sin faltas, percepciones 2778.16.
        $q = $this->calc()->quotas(420.26, 7, 0, 396.88, 2778.16);

        $uma = 117.31;
        $this->assertEqualsWithDelta($uma * 0.204 * 7, $q['eym_fixed'], 0.01);
        $this->assertEqualsWithDelta((420.26 - 3 * $uma) * 0.011 * 7, $q['eym_excess'], 0.01);
        $this->assertEqualsWithDelta(420.26 * 0.0175 * 7, $q['eym_money_gmp'], 0.01);
        $this->assertEqualsWithDelta(420.26 * 0.0175 * 7, $q['iv'], 0.01);
        // 3.58 UMA → rango 3.51-4.00 → 6.613%
        $this->assertEqualsWithDelta(420.26 * 0.06613 * 7, $q['cyv'], 0.01);
        $this->assertEqualsWithDelta(420.26 * 0.01 * 7, $q['guarderia'], 0.01);
        $this->assertEqualsWithDelta(420.26 * 0.02 * 7, $q['retiro'], 0.01);
        $this->assertEqualsWithDelta(420.26 * 0.05 * 7, $q['infonavit'], 0.01);
        $this->assertEqualsWithDelta(2778.16 * 0.03, $q['isn'], 0.01);
        $this->assertSame(0.0, (float) $q['absorbed_worker'], 'no mínimo: sin absorción');
        $this->assertSame(0.0, (float) $q['riesgo_trabajo'], 'prima RT en 0 hasta capturarla');
    }

    public function test_minimum_wage_absorbs_worker_quota(): void
    {
        // Mínimo: SBC ~330.58 (2.82 UMA → CyV 6.026%), el patrón absorbe la
        // cuota obrera (EyM 0.625% todos los días + IV/CyV 1.75% días enteros).
        $q = $this->calc()->quotas(330.58, 7, 0, 315.04, 2205.28);

        $expected = 330.58 * 0.00625 * 7 + 330.58 * (0.00625 + 0.01125) * 7;
        $this->assertEqualsWithDelta($expected, $q['absorbed_worker'], 0.02);
        $this->assertEqualsWithDelta(330.58 * 0.06026 * 7, $q['cyv'], 0.02, 'CyV del mínimo por SBC/UMA (2.82 → 6.026%), no fila SM');
    }

    public function test_absences_reduce_iv_cyv_but_not_eym(): void
    {
        $full = $this->calc()->quotas(420.26, 7, 0, 396.88, 2778.16);
        $abs2 = $this->calc()->quotas(420.26, 7, 2, 396.88, 2778.16);

        $this->assertEqualsWithDelta($full['eym_fixed'], $abs2['eym_fixed'], 0.01, 'EyM fija no descuenta faltas');
        $this->assertEqualsWithDelta($full['eym_money_gmp'], $abs2['eym_money_gmp'], 0.01);
        $this->assertEqualsWithDelta($full['iv'] * 5 / 7, $abs2['iv'], 0.02, 'IV cotiza 5 de 7 días');
        $this->assertEqualsWithDelta($full['cyv'] * 5 / 7, $abs2['cyv'], 0.05, 'CyV cotiza 5 de 7 días');
    }

    public function test_cyv_bracket_selection(): void
    {
        $calc = $this->calc();
        $uma = 117.31;
        $this->assertEqualsWithDelta(4.851, $calc->cyvRate(1.8 * $uma), 0.001);
        $this->assertEqualsWithDelta(6.026, $calc->cyvRate(2.82 * $uma), 0.001);
        $this->assertEqualsWithDelta(7.513, $calc->cyvRate(10 * $uma), 0.001);
    }

    public function test_zero_sbc_returns_zero(): void
    {
        $q = $this->calc()->quotas(0, 7, 0, 500, 3500);
        $this->assertSame(0.0, (float) $q['total']);
    }
}
