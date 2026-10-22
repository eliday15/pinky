<?php

namespace Tests\Feature\Breakfasts;

use App\Models\BreakfastClaim;
use App\Models\BreakfastPayout;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\SystemSetting;
use Carbon\Carbon;
use Tests\FeatureTestCase;

/**
 * Cortes de pago al vendedor de desayunos (Luis 2026-10-22): rango elegido,
 * consecutivo, sin pagar fechas anteriores y con deshacer del último.
 */
class BreakfastPayoutTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-22 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        SystemSetting::set('breakfast_vendor_employee_id', 0);
        parent::tearDown();
    }

    private function claimOn(string $date, float $cost = 20): BreakfastClaim
    {
        return BreakfastClaim::factory()->for(Employee::factory()->create())->onDate($date)->withCost($cost)->create();
    }

    public function test_admin_registers_payout_for_chosen_range_and_marks_claims_paid(): void
    {
        $user = $this->actingAsAdmin();
        $vendor = Employee::factory()->create();
        SystemSetting::set('breakfast_vendor_employee_id', (string) $vendor->id);
        $inRange = [$this->claimOn('2026-10-16'), $this->claimOn('2026-10-21')];
        $after = $this->claimOn('2026-10-22');

        $this->post(route('breakfasts.payouts.store'), [
            'start_date' => '2026-10-16',
            'end_date' => '2026-10-21',
        ])->assertRedirect()->assertSessionHas('success');

        $payout = BreakfastPayout::sole();
        $this->assertSame(['2026-10-16', '2026-10-21', 2, '40.00', $vendor->id, $user->id], [
            $payout->start_date->toDateString(), $payout->end_date->toDateString(),
            $payout->claims_count, $payout->amount, $payout->vendor_employee_id, $payout->paid_by,
        ]);
        foreach ($inRange as $claim) {
            $this->assertSame($payout->id, $claim->fresh()->breakfast_payout_id);
        }
        $this->assertNull($after->fresh()->breakfast_payout_id);
    }

    public function test_cannot_pay_again_any_date_up_to_last_payout(): void
    {
        $this->actingAsAdmin();
        $this->claimOn('2026-10-16');
        $this->claimOn('2026-10-20');
        $this->post(route('breakfasts.payouts.store'), ['start_date' => '2026-10-16', 'end_date' => '2026-10-18']);

        $this->post(route('breakfasts.payouts.store'), ['start_date' => '2026-10-18', 'end_date' => '2026-10-20'])
            ->assertSessionHasErrors(['start_date' => 'Ya se pagó hasta el 18/10/2026. El siguiente pago empieza el 19/10/2026.']);
        $this->assertSame(1, BreakfastPayout::count());
    }

    public function test_cannot_skip_days_with_unpaid_breakfasts(): void
    {
        $this->actingAsAdmin();
        $this->claimOn('2026-10-14');
        $this->post(route('breakfasts.payouts.store'), ['start_date' => '2026-10-14', 'end_date' => '2026-10-15']);
        $this->claimOn('2026-10-16');
        $this->claimOn('2026-10-19');

        $this->post(route('breakfasts.payouts.store'), ['start_date' => '2026-10-19', 'end_date' => '2026-10-21'])
            ->assertSessionHasErrors('start_date');

        // Saltar días SIN desayunos (descanso/festivo) sí se permite.
        $this->post(route('breakfasts.payouts.store'), ['start_date' => '2026-10-16', 'end_date' => '2026-10-16'])
            ->assertSessionHasNoErrors();
        $this->post(route('breakfasts.payouts.store'), ['start_date' => '2026-10-18', 'end_date' => '2026-10-21'])
            ->assertSessionHasNoErrors();
        $this->assertSame(3, BreakfastPayout::count());
    }

    public function test_cannot_pay_future_or_empty_range(): void
    {
        $this->actingAsAdmin();
        $this->claimOn('2026-10-20');

        $this->post(route('breakfasts.payouts.store'), ['start_date' => '2026-10-20', 'end_date' => '2026-10-23'])
            ->assertSessionHasErrors('end_date');
        $this->post(route('breakfasts.payouts.store'), ['start_date' => '2026-10-21', 'end_date' => '2026-10-22'])
            ->assertSessionHasErrors(['start_date' => 'No hay desayunos sin pagar en ese rango.']);
        $this->assertSame(0, BreakfastPayout::count());
    }

    public function test_only_last_payout_can_be_undone_and_frees_its_claims(): void
    {
        $this->actingAsAdmin();
        $first = $this->claimOn('2026-10-14');
        $second = $this->claimOn('2026-10-16');
        $this->post(route('breakfasts.payouts.store'), ['start_date' => '2026-10-14', 'end_date' => '2026-10-15']);
        $this->post(route('breakfasts.payouts.store'), ['start_date' => '2026-10-16', 'end_date' => '2026-10-21']);
        [$older, $latest] = BreakfastPayout::orderBy('end_date')->get()->all();

        $this->delete(route('breakfasts.payouts.destroy', $older))->assertSessionHasErrors('payout');
        $this->delete(route('breakfasts.payouts.destroy', $latest))->assertSessionHas('success');

        $this->assertModelMissing($latest);
        $this->assertNull($second->fresh()->breakfast_payout_id);
        $this->assertSame($older->id, $first->fresh()->breakfast_payout_id);
    }

    public function test_index_defaults_to_pending_range_after_last_payout(): void
    {
        $this->actingAsAdmin();
        $this->claimOn('2026-10-14');
        $this->claimOn('2026-10-19', 25);
        $this->post(route('breakfasts.payouts.store'), ['start_date' => '2026-10-14', 'end_date' => '2026-10-15']);

        $this->get(route('breakfasts.index'))
            ->assertInertia(fn ($page) => $page
                ->where('filters.start_date', '2026-10-16')
                ->where('filters.end_date', '2026-10-22')
                ->where('nextPayoutStart', '2026-10-16')
                ->where('totals.pending_count', 1)
                ->where('totals.pending_amount', 25)
                ->where('canPay', true)
                ->has('payouts', 1));
    }

    public function test_rrhh_without_pay_permission_cannot_register_payouts(): void
    {
        $this->actingAsRrhh();
        $this->claimOn('2026-10-20');

        $this->post(route('breakfasts.payouts.store'), ['start_date' => '2026-10-20', 'end_date' => '2026-10-20'])
            ->assertForbidden();
    }

    public function test_payout_goes_into_open_weekly_payroll_and_vendor_receipt_is_recalculated(): void
    {
        $this->actingAsAdmin();
        $vendor = Employee::factory()->create(['status' => 'active', 'daily_salary' => 0, 'hourly_rate' => 0, 'hire_date' => '2025-01-01']);
        SystemSetting::set('breakfast_vendor_employee_id', (string) $vendor->id);
        $period = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-10-14', 'end_date' => '2026-10-20', 'status' => 'review',
        ]);
        $this->claimOn('2026-10-19');
        $this->claimOn('2026-10-22');

        $this->post(route('breakfasts.payouts.store'), ['start_date' => '2026-10-19', 'end_date' => '2026-10-22'])
            ->assertSessionHasNoErrors();

        $payout = BreakfastPayout::sole();
        $this->assertSame($period->id, $payout->payroll_period_id);
        $entry = PayrollEntry::where('payroll_period_id', $period->id)->where('employee_id', $vendor->id)->sole();
        $this->assertEqualsWithDelta(40.00, (float) $entry->net_pay, 0.01);

        // Deshacer mientras la nómina sigue abierta: el recibo se rehace sin el corte.
        $this->delete(route('breakfasts.payouts.destroy', $payout))->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(0.00, (float) $entry->fresh()->net_pay, 0.01);
    }

    public function test_payout_in_approved_payroll_cannot_be_undone(): void
    {
        $this->actingAsAdmin();
        $vendor = Employee::factory()->create();
        SystemSetting::set('breakfast_vendor_employee_id', (string) $vendor->id);
        $period = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-10-14', 'end_date' => '2026-10-20', 'status' => 'review',
        ]);
        $this->claimOn('2026-10-19');
        $this->post(route('breakfasts.payouts.store'), ['start_date' => '2026-10-19', 'end_date' => '2026-10-22']);
        $period->update(['status' => 'approved']);

        $this->delete(route('breakfasts.payouts.destroy', BreakfastPayout::sole()))
            ->assertSessionHasErrors('payout');
        $this->assertSame(1, BreakfastPayout::count());
    }
}
