<?php

namespace Tests\Feature\Payroll;

use App\Models\Employee;
use App\Services\Fiscal\SdiCalculatorService;
use Carbon\Carbon;
use Database\Seeders\FiscalSettingsSeeder;
use Database\Seeders\VacationTableSeeder;
use Tests\FeatureTestCase;

/**
 * SDI/SBC con el factor de integración (Art. 27 LSS). Los factores esperados
 * están VERIFICADOS contra Contpaq Sem28: sdi/sal_diario de los 158 empleados
 * reproduce exactamente (365 + 15 + vac×0.25)/365 con la tabla LFT.
 */
class SdiCalculatorTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FiscalSettingsSeeder::class);
        $this->seed(VacationTableSeeder::class);
    }

    private function calc(): SdiCalculatorService
    {
        return app(SdiCalculatorService::class);
    }

    private function employee(string $hireDate, float $salary, float $prima = 25.0): Employee
    {
        return Employee::factory()->create([
            'status' => 'active',
            'hire_date' => $hireDate,
            'daily_salary' => $salary,
            'vacation_premium_percentage' => $prima,
        ]);
    }

    public function test_first_year_factor_is_1_0493(): void
    {
        // Contratado ESTE año calendario → año 1 → 12 días vac.
        $e = $this->employee(Carbon::now()->startOfYear()->addMonth()->toDateString(), 800.00);

        $this->assertEqualsWithDelta(383 / 365, $this->calc()->integrationFactor($e), 0.0001);
        $this->assertEqualsWithDelta(round(800 * 383 / 365, 2), $this->calc()->sdi($e), 0.01);
    }

    public function test_calendar_year_convention_matches_contpaq(): void
    {
        // Criterio Contpaq (validado 156/158 en prod): años = año actual − año
        // de ingreso, AUNQUE el aniversario no haya llegado todavía.
        // Ingresó el año pasado en diciembre → cumple 1 este año → 12 días.
        $e = $this->employee(Carbon::now()->subYear()->endOfYear()->toDateString(), 800.00);
        $this->assertEqualsWithDelta(383 / 365, $this->calc()->integrationFactor($e), 0.0001);

        // Ingresó hace 2 años calendario (diciembre) → cumple 2 este año → 14
        // días, aunque por aniversario apenas lleve ~1.5 años.
        $e2 = $this->employee(Carbon::now()->subYears(2)->endOfYear()->toDateString(), 800.00);
        $this->assertEqualsWithDelta(383.5 / 365, $this->calc()->integrationFactor($e2), 0.0001);
    }

    public function test_golden_case_aemb740_18_years(): void
    {
        // Caso real del PDF: sal_diario 396.88, año calendario 18 (26 días,
        // rango 16-20) → SDI 420.26.
        $e = $this->employee(Carbon::now()->subYears(18)->startOfYear()->addMonths(3)->toDateString(), 396.88);

        $this->assertEqualsWithDelta(420.26, $this->calc()->sdi($e), 0.01);
    }

    public function test_sbc_caps_at_25_uma(): void
    {
        // Sueldo alto: SDI > 25×UMA (2932.75) → SBC topado.
        $e = $this->employee(Carbon::now()->subYears(3)->toDateString(), 3000.00);

        $this->assertGreaterThan(25 * 117.31, $this->calc()->sdi($e));
        $this->assertEqualsWithDelta(25 * 117.31, $this->calc()->sbc($e), 0.01);
    }

    public function test_no_hire_date_defaults_to_first_year(): void
    {
        // hire_date es NOT NULL en la BD; el caso "sin fecha" solo puede darse
        // en memoria — el servicio cae al primer año como defensa.
        $e = Employee::factory()->make([
            'status' => 'active',
            'hire_date' => null,
            'daily_salary' => 500.00,
            'vacation_premium_percentage' => 25.0,
        ]);

        $this->assertEqualsWithDelta(383 / 365, $this->calc()->integrationFactor($e), 0.0001);
    }

    public function test_zero_salary_gives_zero_sdi(): void
    {
        $e = Employee::factory()->create([
            'status' => 'active',
            'hire_date' => '2025-01-01',
            'daily_salary' => 0,
            'hourly_rate' => 0,
        ]);

        $this->assertSame(0.0, $this->calc()->sdi($e));
    }
}
