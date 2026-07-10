<?php

namespace Tests\Feature\Payroll;

use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\SystemSetting;
use App\Services\PayrollCalculatorService;
use Database\Seeders\FiscalSettingsSeeder;
use Tests\FeatureTestCase;

/**
 * Aguinaldo anual automático (F4): el periodo semanal que contiene la fecha
 * configurada paga el proporcional (días × SD × días_año/365) por
 * transferencia, exento hasta 30 UMA.
 */
class AguinaldoTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FiscalSettingsSeeder::class);
    }

    private function formalized(string $hireDate, float $salary = 500.00): Employee
    {
        return Employee::factory()->create([
            'status' => 'active',
            'daily_salary' => $salary,
            'hire_date' => $hireDate,
            'is_imss_enrolled' => true,
            'imss_number' => '12345678901',
            'is_trial_period' => false,
            'is_attendance_exempt' => true,
        ]);
    }

    private function decemberEntry(Employee $employee)
    {
        $period = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-12-14',
            'end_date' => '2026-12-20',
        ]);

        return app(PayrollCalculatorService::class)->calculateEmployeePayroll($period, $employee);
    }

    private function aguinaldoConcept($entry): ?array
    {
        return collect($entry->calculation_breakdown['compensation_concepts'] ?? [])
            ->firstWhere('code', 'AGUINALDO');
    }

    public function test_full_year_employee_gets_full_aguinaldo_via_transfer(): void
    {
        SystemSetting::updateOrCreate(['key' => 'fiscal_aguinaldo_payment_date'], ['value' => '2026-12-15']);
        $employee = $this->formalized('2024-01-15'); // año completo

        $entry = $this->decemberEntry($employee);

        $aguinaldo = $this->aguinaldoConcept($entry);
        $this->assertNotNull($aguinaldo, 'el periodo que contiene la fecha paga aguinaldo');
        $this->assertEqualsWithDelta(15 * 500.00, (float) $aguinaldo['amount'], 0.01, '15 días × SD, año completo');
        $this->assertTrue($aguinaldo['via_transfer'] ?? false);
        $this->assertEqualsWithDelta(0.00, (float) $entry->cash_amount, 0.01, 'va por transferencia, no efectivo');
    }

    public function test_new_hire_gets_proportional_aguinaldo(): void
    {
        SystemSetting::updateOrCreate(['key' => 'fiscal_aguinaldo_payment_date'], ['value' => '2026-12-15']);
        // Contratado el 1 jul 2026: 184 días del año (jul-dic).
        $employee = $this->formalized('2026-07-01');

        $entry = $this->decemberEntry($employee);

        $aguinaldo = $this->aguinaldoConcept($entry);
        $this->assertNotNull($aguinaldo);
        $this->assertEqualsWithDelta(15 * 500.00 * (184 / 365), (float) $aguinaldo['amount'], 0.5, 'proporcional a 184 días');
    }

    public function test_no_aguinaldo_outside_configured_week_or_without_date(): void
    {
        // Sin fecha configurada: nada.
        SystemSetting::updateOrCreate(['key' => 'fiscal_aguinaldo_payment_date'], ['value' => '']);
        $entry = $this->decemberEntry($this->formalized('2024-01-15'));
        $this->assertNull($this->aguinaldoConcept($entry), 'sin fecha configurada no paga');

        // Fecha en OTRA semana: tampoco.
        SystemSetting::updateOrCreate(['key' => 'fiscal_aguinaldo_payment_date'], ['value' => '2026-12-25']);
        $employee2 = $this->formalized('2024-01-15');
        $entry2 = $this->decemberEntry($employee2);
        $this->assertNull($this->aguinaldoConcept($entry2), 'la fecha cae fuera del periodo');
    }

    public function test_aguinaldo_exempt_up_to_30_uma_in_isr_base(): void
    {
        SystemSetting::updateOrCreate(['key' => 'fiscal_aguinaldo_payment_date'], ['value' => '2026-12-15']);
        // SD alto: aguinaldo 15×800=12,000 > 30 UMA (3,519.30) → excedente gravado.
        $employee = $this->formalized('2024-01-15', 800.00);

        $entry = $this->decemberEntry($employee);

        $taxableExtras = (float) data_get($entry->calculation_breakdown, 'fiscal.taxable_transfer_extras', 0);
        $this->assertEqualsWithDelta(12000 - 30 * 117.31, $taxableExtras, 0.5, 'solo el excedente de 30 UMA grava');
    }
}
