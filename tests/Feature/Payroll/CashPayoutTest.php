<?php

namespace Tests\Feature\Payroll;

use App\Models\CashPayout;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\FeatureTestCase;

/**
 * Cierre de efectivo (closeCash), página de cobro (cash), cobro con PIN
 * (collectCash) y acumulado por empleado (opening_balance).
 *
 * Solo admin tiene payroll.pay_cash; el resto recibe 403.
 */
class CashPayoutTest extends FeatureTestCase
{
    /**
     * An approved period with one entry of the given cash_amount.
     */
    private function approvedPeriodWithEntry(float $cashAmount, array $periodAttrs = []): array
    {
        // Trial + sin IMSS => paga base en efectivo, así closeCash re-deriva
        // cash_amount = net_pay (= $cashAmount) con la regla vigente.
        $employee = Employee::factory()->create([
            'status' => 'active',
            'is_trial_period' => true,
            'trial_period_end_date' => null,
            'is_imss_enrolled' => false,
        ]);

        $period = PayrollPeriod::factory()->create(array_merge([
            'type' => 'weekly',
            'status' => 'approved',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
        ], $periodAttrs));

        PayrollEntry::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'net_pay' => $cashAmount,
            'regular_pay' => 0,
            'deductions' => 0,
            'cash_amount' => $cashAmount,
            'bank_amount' => 0,
        ]);

        return [$period, $employee];
    }

    /**
     * Confirmar la entrega del efectivo (paso 1) — requisito para cobrar. Debe
     * llamarse como un usuario con payroll.pay_cash y con el efectivo ya cerrado.
     */
    private function confirmDelivery(PayrollPeriod $period): void
    {
        $this->post(route('payroll.confirmDelivery', $period->id))->assertRedirect();
    }

    // ---- closeCash ------------------------------------------------------

    public function test_close_cash_forbidden_without_permission(): void
    {
        [$period] = $this->approvedPeriodWithEntry(1000);
        $this->actingAsRrhh();

        $this->post(route('payroll.closeCash', $period->id))->assertForbidden();
    }

    public function test_close_cash_requires_approved_status(): void
    {
        [$period] = $this->approvedPeriodWithEntry(1000, ['status' => 'review']);
        $this->actingAsAdmin();

        $this->post(route('payroll.closeCash', $period->id))
            ->assertRedirect();

        $this->assertDatabaseCount('cash_payouts', 0);
        $this->assertNull($period->fresh()->cash_closed_at);
    }

    public function test_close_cash_creates_payouts_with_breakdown_and_marks_closed(): void
    {
        [$period, $employee] = $this->approvedPeriodWithEntry(1247.40);
        $this->actingAsAdmin();

        $this->post(route('payroll.closeCash', $period->id))
            ->assertRedirect(route('payroll.cash', $period->id));

        $payout = CashPayout::where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->firstOrFail();

        // 1247.40 redondea a 1247.
        $this->assertEqualsWithDelta(1247.00, (float) $payout->period_amount, 0.01);
        $this->assertEqualsWithDelta(0.00, (float) $payout->opening_balance, 0.01);
        $this->assertEqualsWithDelta(1247.00, (float) $payout->total_due, 0.01);
        $this->assertSame('pending', $payout->status);
        $this->assertSame(
            ['1000' => 1, '200' => 1, '20' => 2, '5' => 1, '2' => 1],
            collect($payout->denomination_breakdown)->mapWithKeys(fn ($v, $k) => [(string) $k => $v])->all()
        );
        $this->assertNotNull($period->fresh()->cash_closed_at);
    }

    // ---- cash page ------------------------------------------------------

    public function test_cash_page_renders_for_admin(): void
    {
        [$period] = $this->approvedPeriodWithEntry(1000);
        $this->actingAsAdmin();
        $this->post(route('payroll.closeCash', $period->id));

        $this->get(route('payroll.cash', $period->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Payroll/Cash')
                ->has('payouts', 1)
                ->has('globalBreakdown')
                ->has('summary'));
    }

    public function test_cash_page_forbidden_for_supervisor(): void
    {
        [$period] = $this->approvedPeriodWithEntry(1000);
        $this->actingAsSupervisor();

        $this->get(route('payroll.cash', $period->id))->assertForbidden();
    }

    // ---- collectCash ----------------------------------------------------

    public function test_collect_with_correct_pin_marks_paid(): void
    {
        [$period, $employee] = $this->approvedPeriodWithEntry(1000);
        $employee->update(['cash_pin' => '4321']);

        $admin = $this->actingAsAdmin();
        $this->post(route('payroll.closeCash', $period->id));
        $this->confirmDelivery($period);

        $payout = CashPayout::where('payroll_period_id', $period->id)->firstOrFail();

        $this->post(route('payroll.payouts.collect', [$period->id, $payout->id]), ['pin' => '4321'])
            ->assertRedirect(route('payroll.cash', $period->id));

        $payout->refresh();
        $this->assertSame('paid', $payout->status);
        $this->assertEqualsWithDelta(1000.00, (float) $payout->amount_paid, 0.01);
        $this->assertTrue((bool) $payout->pin_verified);
        $this->assertSame($admin->id, $payout->collected_by);
        $this->assertNotNull($payout->collected_at);
    }

    public function test_collect_with_wrong_pin_keeps_pending(): void
    {
        [$period, $employee] = $this->approvedPeriodWithEntry(1000);
        $employee->update(['cash_pin' => '4321']);

        $this->actingAsAdmin();
        $this->post(route('payroll.closeCash', $period->id));
        $this->confirmDelivery($period);
        $payout = CashPayout::where('payroll_period_id', $period->id)->firstOrFail();

        $this->post(route('payroll.payouts.collect', [$period->id, $payout->id]), ['pin' => '0000'])
            ->assertSessionHasErrors('pin');

        $this->assertSame('pending', $payout->fresh()->status);
    }

    public function test_collect_forbidden_without_permission(): void
    {
        [$period, $employee] = $this->approvedPeriodWithEntry(1000);
        $employee->update(['cash_pin' => '4321']);
        $this->actingAsAdmin();
        $this->post(route('payroll.closeCash', $period->id));
        $payout = CashPayout::where('payroll_period_id', $period->id)->firstOrFail();

        // Switch to a supervisor (no payroll.pay_cash).
        $this->actingAsSupervisor();
        $this->post(route('payroll.payouts.collect', [$period->id, $payout->id]), ['pin' => '4321'])
            ->assertForbidden();

        $this->assertSame('pending', $payout->fresh()->status);
    }

    // ---- accumulation ---------------------------------------------------

    public function test_uncollected_balance_accumulates_to_next_period(): void
    {
        $employee = Employee::factory()->create([
            'status' => 'active', 'cash_pin' => '4321',
            'is_trial_period' => true, 'trial_period_end_date' => null, 'is_imss_enrolled' => false,
        ]);
        $this->actingAsAdmin();

        // P1: $500, never collected.
        $p1 = PayrollPeriod::factory()->create([
            'type' => 'weekly', 'status' => 'approved',
            'start_date' => '2026-06-01', 'end_date' => '2026-06-07',
        ]);
        PayrollEntry::factory()->create([
            'payroll_period_id' => $p1->id, 'employee_id' => $employee->id,
            'net_pay' => 500, 'regular_pay' => 0, 'deductions' => 0,
            'cash_amount' => 500, 'bank_amount' => 0,
        ]);
        $this->post(route('payroll.closeCash', $p1->id));

        // P2: $700, closed after P1 went uncollected.
        $p2 = PayrollPeriod::factory()->create([
            'type' => 'weekly', 'status' => 'approved',
            'start_date' => '2026-06-08', 'end_date' => '2026-06-14',
        ]);
        PayrollEntry::factory()->create([
            'payroll_period_id' => $p2->id, 'employee_id' => $employee->id,
            'net_pay' => 700, 'regular_pay' => 0, 'deductions' => 0,
            'cash_amount' => 700, 'bank_amount' => 0,
        ]);
        $this->post(route('payroll.closeCash', $p2->id));

        $payout2 = CashPayout::where('payroll_period_id', $p2->id)
            ->where('employee_id', $employee->id)
            ->firstOrFail();

        $this->assertEqualsWithDelta(700.00, (float) $payout2->period_amount, 0.01);
        $this->assertEqualsWithDelta(500.00, (float) $payout2->opening_balance, 0.01, 'acumulado de P1');
        $this->assertEqualsWithDelta(1200.00, (float) $payout2->total_due, 0.01);
    }

    public function test_collecting_rolled_total_settles_prior_pending(): void
    {
        $employee = Employee::factory()->create([
            'status' => 'active', 'cash_pin' => '4321',
            'is_trial_period' => true, 'trial_period_end_date' => null, 'is_imss_enrolled' => false,
        ]);
        $this->actingAsAdmin();

        $p1 = PayrollPeriod::factory()->create([
            'type' => 'weekly', 'status' => 'approved',
            'start_date' => '2026-06-01', 'end_date' => '2026-06-07',
        ]);
        PayrollEntry::factory()->create([
            'payroll_period_id' => $p1->id, 'employee_id' => $employee->id,
            'net_pay' => 500, 'regular_pay' => 0, 'deductions' => 0,
            'cash_amount' => 500, 'bank_amount' => 0,
        ]);
        $this->post(route('payroll.closeCash', $p1->id));

        $p2 = PayrollPeriod::factory()->create([
            'type' => 'weekly', 'status' => 'approved',
            'start_date' => '2026-06-08', 'end_date' => '2026-06-14',
        ]);
        PayrollEntry::factory()->create([
            'payroll_period_id' => $p2->id, 'employee_id' => $employee->id,
            'net_pay' => 700, 'regular_pay' => 0, 'deductions' => 0,
            'cash_amount' => 700, 'bank_amount' => 0,
        ]);
        $this->post(route('payroll.closeCash', $p2->id));
        $this->confirmDelivery($p2);

        $payout2 = CashPayout::where('payroll_period_id', $p2->id)->firstOrFail();
        $this->post(route('payroll.payouts.collect', [$p2->id, $payout2->id]), ['pin' => '4321']);

        // Cobrar el total acumulado de P2 ($1,200) salda también P1.
        $payout1 = CashPayout::where('payroll_period_id', $p1->id)->firstOrFail();
        $this->assertSame('paid', $payout1->fresh()->status);
        $this->assertSame('paid', $payout2->fresh()->status);
    }

    // ---- diferencia tras cobro parcial (recálculo al alza) --------------

    public function test_recalc_after_collection_reopens_the_difference(): void
    {
        [$period, $employee] = $this->approvedPeriodWithEntry(50);
        $employee->update(['cash_pin' => '4321']);
        $this->actingAsAdmin();

        // Cierra, confirma la entrega y cobra los $50.
        $this->post(route('payroll.closeCash', $period->id));
        $this->confirmDelivery($period);
        $payout = CashPayout::where('payroll_period_id', $period->id)->firstOrFail();
        $this->post(route('payroll.payouts.collect', [$period->id, $payout->id]), ['pin' => '4321']);
        $this->assertSame('paid', $payout->fresh()->status);

        // Un recálculo sube el efectivo del periodo a $650 (p. ej. un concepto
        // que antes pagaba $1 ahora paga $600).
        $period->entries()->update(['net_pay' => 650, 'cash_amount' => 650]);

        // Re-cerrar efectivo reabre solo la diferencia como pendiente.
        $this->post(route('payroll.closeCash', $period->id));

        $payout->refresh();
        $this->assertSame('pending', $payout->status);
        $this->assertEqualsWithDelta(650.00, (float) $payout->total_due, 0.01);
        $this->assertEqualsWithDelta(50.00, (float) $payout->amount_paid, 0.01, 'conserva lo ya cobrado');
        $this->assertEqualsWithDelta(600.00, $payout->outstanding(), 0.01, 'solo la diferencia queda por cobrar');
    }

    public function test_recalc_not_higher_leaves_paid_payout_untouched(): void
    {
        [$period, $employee] = $this->approvedPeriodWithEntry(650);
        $employee->update(['cash_pin' => '4321']);
        $this->actingAsAdmin();

        $this->post(route('payroll.closeCash', $period->id));
        $this->confirmDelivery($period);
        $payout = CashPayout::where('payroll_period_id', $period->id)->firstOrFail();
        $this->post(route('payroll.payouts.collect', [$period->id, $payout->id]), ['pin' => '4321']);
        $this->assertSame('paid', $payout->fresh()->status);

        // Un recálculo que NO sube el monto no reabre ni descobra.
        $period->entries()->update(['net_pay' => 600, 'cash_amount' => 600]);
        $this->post(route('payroll.closeCash', $period->id));

        $payout->refresh();
        $this->assertSame('paid', $payout->status);
        $this->assertEqualsWithDelta(650.00, (float) $payout->amount_paid, 0.01, 'no se descobra');
    }

    public function test_cash_page_flags_stale_when_recalc_changed_cash(): void
    {
        [$period] = $this->approvedPeriodWithEntry(50);
        $this->actingAsAdmin();
        $this->post(route('payroll.closeCash', $period->id));

        // Un recálculo sube el efectivo del asiento después de cerrar el efectivo.
        $period->entries()->update(['net_pay' => 650, 'cash_amount' => 650]);

        $this->get(route('payroll.cash', $period->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('cashStale', true));
    }

    public function test_cash_page_not_stale_right_after_close(): void
    {
        [$period] = $this->approvedPeriodWithEntry(50);
        $this->actingAsAdmin();
        $this->post(route('payroll.closeCash', $period->id));

        $this->get(route('payroll.cash', $period->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('cashStale', false));
    }

    public function test_collecting_reopened_difference_settles_it(): void
    {
        [$period, $employee] = $this->approvedPeriodWithEntry(50);
        $employee->update(['cash_pin' => '4321']);
        $this->actingAsAdmin();

        $this->post(route('payroll.closeCash', $period->id));
        $this->confirmDelivery($period);
        $payout = CashPayout::where('payroll_period_id', $period->id)->firstOrFail();
        $this->post(route('payroll.payouts.collect', [$period->id, $payout->id]), ['pin' => '4321']);

        $period->entries()->update(['net_pay' => 650, 'cash_amount' => 650]);
        // Re-cerrar reinicia la confirmación de entrega: hay que volver a confirmar.
        $this->post(route('payroll.closeCash', $period->id));
        $this->confirmDelivery($period);

        // Cobrar la diferencia la salda por completo.
        $this->post(route('payroll.payouts.collect', [$period->id, $payout->id]), ['pin' => '4321']);

        $payout->refresh();
        $this->assertSame('paid', $payout->status);
        $this->assertEqualsWithDelta(650.00, (float) $payout->amount_paid, 0.01);
    }

    // ---- entrega del efectivo (paso 1) requisito para cobrar (paso 2) ----

    public function test_cannot_collect_before_delivery_confirmed(): void
    {
        [$period, $employee] = $this->approvedPeriodWithEntry(1000);
        $employee->update(['cash_pin' => '4321']);
        $this->actingAsAdmin();
        $this->post(route('payroll.closeCash', $period->id));

        // Efectivo cerrado pero entrega SIN confirmar: no se puede cobrar.
        $payout = CashPayout::where('payroll_period_id', $period->id)->firstOrFail();
        $this->from(route('payroll.cash', $period->id))
            ->post(route('payroll.payouts.collect', [$period->id, $payout->id]), ['pin' => '4321'])
            ->assertRedirect(route('payroll.cash', $period->id))
            ->assertSessionHas('error');

        $this->assertSame('pending', $payout->fresh()->status);
    }

    public function test_confirm_delivery_requires_cash_closed(): void
    {
        [$period] = $this->approvedPeriodWithEntry(1000);
        $this->actingAsAdmin();

        // El efectivo no se ha cerrado todavía.
        $this->from(route('payroll.show', $period->id))
            ->post(route('payroll.confirmDelivery', $period->id))
            ->assertSessionHas('error');

        $this->assertNull($period->fresh()->cash_delivery_confirmed_at);
    }

    public function test_reclosing_cash_resets_delivery_confirmation(): void
    {
        [$period] = $this->approvedPeriodWithEntry(1000);
        $this->actingAsAdmin();
        $this->post(route('payroll.closeCash', $period->id));
        $this->confirmDelivery($period);
        $this->assertNotNull($period->fresh()->cash_delivery_confirmed_at);

        // Re-cerrar/re-preparar el efectivo obliga a volver a confirmar la entrega.
        $this->post(route('payroll.closeCash', $period->id));
        $this->assertNull($period->fresh()->cash_delivery_confirmed_at);
    }

    public function test_confirm_delivery_forbidden_without_permission(): void
    {
        [$period] = $this->approvedPeriodWithEntry(1000);
        $this->actingAsAdmin();
        $this->post(route('payroll.closeCash', $period->id));

        $this->actingAsSupervisor();
        $this->post(route('payroll.confirmDelivery', $period->id))->assertForbidden();
    }

    // ---- transferencias (pantalla independiente) ------------------------

    public function test_transfers_page_renders_with_bank_amounts(): void
    {
        // No trial + IMSS => el sueldo base va a banco (bank_amount > 0).
        $employee = Employee::factory()->create([
            'status' => 'active', 'is_trial_period' => false, 'is_imss_enrolled' => true,
        ]);
        $period = PayrollPeriod::factory()->create(['type' => 'weekly', 'status' => 'approved']);
        PayrollEntry::factory()->create([
            'payroll_period_id' => $period->id, 'employee_id' => $employee->id,
            'net_pay' => 1000, 'regular_pay' => 1000, 'deductions' => 0,
            'cash_amount' => 0, 'bank_amount' => 1000,
        ]);

        $this->actingAsAdmin();
        $this->get(route('payroll.transfers', $period->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Payroll/Transfers')
                ->has('transfers', 1)
                ->where('summary.total_transfer', 1000)
                ->where('summary.transfer_count', 1));
    }

    public function test_transfers_page_forbidden_for_supervisor(): void
    {
        $period = PayrollPeriod::factory()->create(['type' => 'weekly', 'status' => 'approved']);
        $this->actingAsSupervisor();
        $this->get(route('payroll.transfers', $period->id))->assertForbidden();
    }

    // ---- detalle concepto por concepto en el cobro ---------------------

    public function test_cash_page_payout_includes_concept_by_concept_detail(): void
    {
        // IMSS => el efectivo son solo los extras (la base va a banco).
        $employee = Employee::factory()->create([
            'status' => 'active', 'is_trial_period' => false, 'is_imss_enrolled' => true,
        ]);
        $period = PayrollPeriod::factory()->create(['type' => 'weekly', 'status' => 'approved']);
        PayrollEntry::factory()->create([
            'payroll_period_id' => $period->id, 'employee_id' => $employee->id,
            'regular_pay' => 2000, 'deductions' => 0,
            'overtime_pay' => 100, 'other_compensation_pay' => 650,
            'net_pay' => 2750, 'gross_pay' => 2750,
            'cash_amount' => 750, 'bank_amount' => 2000,
            'calculation_breakdown' => [
                'compensation_concepts' => [
                    ['name' => 'Cena por entrega a Walmart', 'code' => 'Cena_Walmart', 'amount' => 50],
                    ['name' => 'Puntualidad Almacen', 'code' => 'Puntualidad Alm', 'amount' => 600],
                ],
            ],
        ]);

        $this->actingAsAdmin();
        $this->post(route('payroll.closeCash', $period->id));

        // Efectivo = Horas extra $100 + Cena $50 + Puntualidad $600 (sin base ni
        // "Otros conceptos" agrupado). Los tres renglones, solo lo que sí tuvo.
        $this->get(route('payroll.cash', $period->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Payroll/Cash')
                ->has('payouts.0.cash_items', 3)
                ->where('payouts.0.cash_items.0.label', 'Horas extra')
                ->where('payouts.0.cash_items.1.label', 'Cena por entrega a Walmart')
                ->where('payouts.0.cash_items.2.amount', 600));
    }
}
