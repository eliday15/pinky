<?php

namespace Tests\Feature\Payroll;

use App\Models\BreakfastClaim;
use App\Models\CompensationType;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\SystemSetting;
use App\Services\PayrollCalculatorService;
use Tests\FeatureTestCase;

/**
 * Feature tests for the informational breakfast total in the weekly payroll.
 *
 * All breakfasts claimed at the kiosk during the period are shown to the ONE
 * configured vendor employee, in the period that pays BASE (weekly), summing
 * the frozen unit_cost snapshots without changing any payable amount.
 */
class BreakfastVendorPayTest extends FeatureTestCase
{
    private function calculator(): PayrollCalculatorService
    {
        return app(PayrollCalculatorService::class);
    }

    /**
     * Vendor who "solo cobra los desayunos": zero salary, zero hourly rate.
     */
    private function makeVendor(): Employee
    {
        // hire_date fijo: el factory lo pone aleatorio y si cae dentro del
        // periodo prorratea la base (paidCalendarDays), causando un flake en el
        // assert de regular_pay = SD × 7.
        $vendor = Employee::factory()->create([
            'status' => 'active',
            'daily_salary' => 0,
            'hourly_rate' => 0,
            'hire_date' => '2025-01-01',
        ]);

        SystemSetting::set('breakfast_vendor_employee_id', $vendor->id);

        return $vendor;
    }

    private function weeklyPeriod(): PayrollPeriod
    {
        return PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
        ]);
    }

    protected function tearDown(): void
    {
        // El id del vendedor queda cacheado por SystemSetting::get; se limpia
        // para no contaminar otras clases de test del mismo proceso.
        SystemSetting::set('breakfast_vendor_employee_id', 0);

        parent::tearDown();
    }

    public function test_weekly_period_shows_claim_sum_without_paying_vendor(): void
    {
        $vendor = $this->makeVendor();
        $vendor->update(['is_imss_enrolled' => true]);

        // Aun si el catálogo reutiliza DES como concepto transferible, el
        // desayuno informativo nunca debe entrar al banco.
        CompensationType::factory()->fixed(1)->create([
            'code' => 'DES',
            'pays_via_transfer' => true,
        ]);

        // 3 desayunos de distintos empleados dentro de la semana.
        BreakfastClaim::factory()->onDate('2026-06-02')->withCost(30)->create();
        BreakfastClaim::factory()->onDate('2026-06-03')->withCost(30)->create();
        BreakfastClaim::factory()->onDate('2026-06-05')->withCost(35)->create();

        $entry = $this->calculator()->calculateEmployeePayroll($this->weeklyPeriod(), $vendor);

        $this->assertEqualsWithDelta(0.00, (float) $entry->other_compensation_pay, 0.01);
        $this->assertEqualsWithDelta(0.00, (float) $entry->regular_pay, 0.01, 'vendor has no base salary');
        $this->assertEqualsWithDelta(0.00, (float) $entry->gross_pay, 0.01);
        $this->assertEqualsWithDelta(0.00, (float) $entry->net_pay, 0.01);
        $this->assertEqualsWithDelta(0.00, (float) $entry->cash_amount, 0.01);
        $this->assertEqualsWithDelta(0.00, (float) $entry->bank_amount, 0.01);

        $concepts = collect($entry->calculation_breakdown['compensation_concepts']);
        $des = $concepts->firstWhere('code', 'DES');
        $this->assertNotNull($des, 'DES concept present in breakdown');
        $this->assertSame(3, $des['quantity']);
        $this->assertEqualsWithDelta(95.00, $des['amount'], 0.01);
        $this->assertTrue($des['informational']);
    }

    public function test_claims_outside_the_period_are_not_paid(): void
    {
        $vendor = $this->makeVendor();

        BreakfastClaim::factory()->onDate('2026-05-31')->withCost(30)->create();
        BreakfastClaim::factory()->onDate('2026-06-08')->withCost(30)->create();
        BreakfastClaim::factory()->onDate('2026-06-04')->withCost(30)->create();

        $entry = $this->calculator()->calculateEmployeePayroll($this->weeklyPeriod(), $vendor);

        $this->assertEqualsWithDelta(0.00, (float) $entry->other_compensation_pay, 0.01);
        $des = collect($entry->calculation_breakdown['compensation_concepts'])->firstWhere('code', 'DES');
        $this->assertSame(1, $des['quantity']);
        $this->assertEqualsWithDelta(30.00, $des['amount'], 0.01);
    }

    public function test_monthly_period_does_not_pay_breakfasts(): void
    {
        $vendor = $this->makeVendor();
        BreakfastClaim::factory()->onDate('2026-06-03')->withCost(30)->create();

        $period = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($period, $vendor);

        $this->assertEqualsWithDelta(0.00, (float) $entry->other_compensation_pay, 0.01);
        $this->assertEqualsWithDelta(0.00, (float) $entry->net_pay, 0.01);
    }

    public function test_recalculation_is_idempotent(): void
    {
        $vendor = $this->makeVendor();
        BreakfastClaim::factory()->onDate('2026-06-03')->withCost(30)->create();
        $period = $this->weeklyPeriod();

        $first = $this->calculator()->calculateEmployeePayroll($period, $vendor);
        $second = $this->calculator()->calculateEmployeePayroll($period, $vendor);

        $this->assertSame($first->id, $second->id, 'same entry updated, not duplicated');
        $this->assertEqualsWithDelta(0.00, (float) $second->other_compensation_pay, 0.01);
        $this->assertEqualsWithDelta((float) $first->net_pay, (float) $second->net_pay, 0.001);
    }

    public function test_non_vendor_employee_is_not_paid_breakfasts(): void
    {
        $this->makeVendor();
        BreakfastClaim::factory()->onDate('2026-06-03')->withCost(30)->create();

        $other = Employee::factory()->create([
            'status' => 'active',
            'daily_salary' => 200,
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($this->weeklyPeriod(), $other);

        $this->assertEqualsWithDelta(0.00, (float) $entry->other_compensation_pay, 0.01);
        $concepts = collect($entry->calculation_breakdown['compensation_concepts'] ?? []);
        $this->assertNull($concepts->firstWhere('code', 'DES'));
    }

    public function test_without_configured_vendor_nobody_is_paid(): void
    {
        SystemSetting::set('breakfast_vendor_employee_id', 0);
        BreakfastClaim::factory()->onDate('2026-06-03')->withCost(30)->create();

        $employee = Employee::factory()->create([
            'status' => 'active',
            'daily_salary' => 0,
            'hourly_rate' => 0,
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($this->weeklyPeriod(), $employee);

        $this->assertEqualsWithDelta(0.00, (float) $entry->other_compensation_pay, 0.01);
    }

    public function test_vendor_with_salary_sees_breakfasts_without_changing_pay(): void
    {
        $vendor = $this->makeVendor();
        $vendor->update(['daily_salary' => 100]);

        BreakfastClaim::factory()->onDate('2026-06-03')->withCost(30)->create();
        BreakfastClaim::factory()->onDate('2026-06-04')->withCost(30)->create();

        $entry = $this->calculator()->calculateEmployeePayroll($this->weeklyPeriod(), $vendor);

        // Base 100 × 7; los $60 de desayunos son solo informativos.
        $this->assertEqualsWithDelta(700.00, (float) $entry->regular_pay, 0.01);
        $this->assertEqualsWithDelta(0.00, (float) $entry->other_compensation_pay, 0.01);
        $this->assertEqualsWithDelta(700.00, (float) $entry->gross_pay, 0.01);
        $this->assertEqualsWithDelta(700.00, (float) $entry->net_pay, 0.01);

        $des = collect($entry->calculation_breakdown['compensation_concepts'])->firstWhere('code', 'DES');
        $this->assertEqualsWithDelta(60.00, $des['amount'], 0.01);
        $this->assertTrue($des['informational']);
    }
}
