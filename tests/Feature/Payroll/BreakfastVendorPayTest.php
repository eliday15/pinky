<?php

namespace Tests\Feature\Payroll;

use App\Models\BreakfastClaim;
use App\Models\BreakfastPayout;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\SystemSetting;
use App\Services\PayrollCalculatorService;
use Tests\FeatureTestCase;

/**
 * Los CORTES de desayunos se le pagan al vendedor dentro de su nómina semanal
 * (Luis 2026-10-22, "opción 1"). La nómina paga los cortes asignados a ella y
 * toma los que no tienen nómina; nunca paga un corte dos veces ni suma
 * desayunos sin corte.
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
    private function makeVendor(array $attributes = []): Employee
    {
        // hire_date fijo: el factory lo pone aleatorio y si cae dentro del
        // periodo prorratea la base (paidCalendarDays), causando un flake en el
        // assert de regular_pay = SD × 7.
        $vendor = Employee::factory()->create(array_merge([
            'status' => 'active',
            'daily_salary' => 0,
            'hourly_rate' => 0,
            'hire_date' => '2025-01-01',
        ], $attributes));

        SystemSetting::set('breakfast_vendor_employee_id', $vendor->id);

        return $vendor;
    }

    private function weeklyPeriod(array $attributes = []): PayrollPeriod
    {
        return PayrollPeriod::factory()->weekly()->create(array_merge([
            'start_date' => '2026-06-03',
            'end_date' => '2026-06-09',
            'status' => 'review',
        ], $attributes));
    }

    private function payout(string $start, string $end, int $count, float $amount, ?PayrollPeriod $period = null): BreakfastPayout
    {
        return BreakfastPayout::create([
            'start_date' => $start,
            'end_date' => $end,
            'claims_count' => $count,
            'amount' => $amount,
            'payroll_period_id' => $period?->id,
        ]);
    }

    protected function tearDown(): void
    {
        // El id del vendedor queda cacheado por SystemSetting::get; se limpia
        // para no contaminar otras clases de test del mismo proceso.
        SystemSetting::set('breakfast_vendor_employee_id', 0);

        parent::tearDown();
    }

    public function test_weekly_period_pays_unassigned_payout_even_if_its_dates_go_past_the_period(): void
    {
        $vendor = $this->makeVendor();
        $period = $this->weeklyPeriod();
        // Caso Luis: nómina mié→mar, corte lun→jue.
        $payout = $this->payout('2026-06-08', '2026-06-11', 90, 1800);

        $entry = $this->calculator()->calculateEmployeePayroll($period, $vendor);

        $this->assertSame($period->id, $payout->fresh()->payroll_period_id);
        $this->assertEqualsWithDelta(1800.00, (float) $entry->other_compensation_pay, 0.01);
        $this->assertEqualsWithDelta(1800.00, (float) $entry->net_pay, 0.01);
        $this->assertEqualsWithDelta(1800.00, (float) $entry->cash_amount, 0.01);

        $des = collect($entry->calculation_breakdown['compensation_concepts'])->firstWhere('code', 'DES');
        $this->assertSame(90, $des['quantity']);
        $this->assertSame('Desayunos (08/06–11/06)', $des['name']);
        $this->assertArrayNotHasKey('informational', $des);
    }

    public function test_claims_without_payout_are_not_paid(): void
    {
        $vendor = $this->makeVendor();
        BreakfastClaim::factory()->onDate('2026-06-04')->withCost(30)->create();

        $entry = $this->calculator()->calculateEmployeePayroll($this->weeklyPeriod(), $vendor);

        $this->assertEqualsWithDelta(0.00, (float) $entry->net_pay, 0.01);
        $this->assertNull(collect($entry->calculation_breakdown['compensation_concepts'] ?? [])->firstWhere('code', 'DES'));
    }

    public function test_payout_assigned_to_another_period_is_never_paid_twice(): void
    {
        $vendor = $this->makeVendor();
        $previous = $this->weeklyPeriod(['start_date' => '2026-05-27', 'end_date' => '2026-06-02', 'status' => 'paid']);
        $this->payout('2026-05-25', '2026-05-28', 50, 1000, $previous);
        $this->payout('2026-05-29', '2026-06-04', 60, 1200);

        $entry = $this->calculator()->calculateEmployeePayroll($this->weeklyPeriod(), $vendor);

        $this->assertEqualsWithDelta(1200.00, (float) $entry->other_compensation_pay, 0.01);
    }

    public function test_approved_period_does_not_take_unassigned_payouts(): void
    {
        $vendor = $this->makeVendor();
        $payout = $this->payout('2026-06-03', '2026-06-04', 10, 200);

        $entry = $this->calculator()->calculateEmployeePayroll($this->weeklyPeriod(['status' => 'approved']), $vendor);

        $this->assertNull($payout->fresh()->payroll_period_id);
        $this->assertEqualsWithDelta(0.00, (float) $entry->other_compensation_pay, 0.01);
    }

    public function test_monthly_period_does_not_pay_breakfasts(): void
    {
        $vendor = $this->makeVendor();
        $payout = $this->payout('2026-06-03', '2026-06-04', 10, 200);
        $period = PayrollPeriod::factory()->monthly()->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
            'status' => 'review',
        ]);

        $entry = $this->calculator()->calculateEmployeePayroll($period, $vendor);

        $this->assertNull($payout->fresh()->payroll_period_id);
        $this->assertEqualsWithDelta(0.00, (float) $entry->net_pay, 0.01);
    }

    public function test_recalculation_is_idempotent(): void
    {
        $vendor = $this->makeVendor();
        $this->payout('2026-06-03', '2026-06-04', 10, 200);
        $period = $this->weeklyPeriod();

        $first = $this->calculator()->calculateEmployeePayroll($period, $vendor);
        $second = $this->calculator()->calculateEmployeePayroll($period, $vendor);

        $this->assertSame($first->id, $second->id, 'same entry updated, not duplicated');
        $this->assertEqualsWithDelta(200.00, (float) $second->other_compensation_pay, 0.01);
        $this->assertEqualsWithDelta((float) $first->net_pay, (float) $second->net_pay, 0.001);
    }

    public function test_non_vendor_employee_is_not_paid_breakfasts(): void
    {
        $this->makeVendor();
        $payout = $this->payout('2026-06-03', '2026-06-04', 10, 200);
        $other = Employee::factory()->create(['status' => 'active', 'daily_salary' => 200]);

        $entry = $this->calculator()->calculateEmployeePayroll($this->weeklyPeriod(), $other);

        $this->assertNull($payout->fresh()->payroll_period_id);
        $this->assertNull(collect($entry->calculation_breakdown['compensation_concepts'] ?? [])->firstWhere('code', 'DES'));
    }

    public function test_vendor_with_salary_gets_payout_on_top_of_base(): void
    {
        $vendor = $this->makeVendor(['daily_salary' => 100]);
        $this->payout('2026-06-03', '2026-06-04', 2, 60);

        $entry = $this->calculator()->calculateEmployeePayroll($this->weeklyPeriod(), $vendor);

        // Base 100 × 7 + $60 del corte.
        $this->assertEqualsWithDelta(700.00, (float) $entry->regular_pay, 0.01);
        $this->assertEqualsWithDelta(60.00, (float) $entry->other_compensation_pay, 0.01);
        $this->assertEqualsWithDelta(760.00, (float) $entry->gross_pay, 0.01);
    }
}
