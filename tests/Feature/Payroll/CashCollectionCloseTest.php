<?php

namespace Tests\Feature\Payroll;

use App\Models\CashPayout;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\FeatureTestCase;

/**
 * Roles cobrador (general/taller) + cierre del cobro + devolución del efectivo.
 *
 * Segregación de funciones: el superadmin ENTREGA el efectivo (paso 1) y
 * RECIBE la devolución; el cobrador solo COBRA (paso 2) en SU nómina y la
 * CIERRA (congela el efectivo a regresar). Lo no cobrado sigue pendiente en
 * cash_payouts y se acumula al empleado en el siguiente cierre de efectivo.
 */
class CashCollectionCloseTest extends FeatureTestCase
{
    /**
     * An approved period (optionally department-scoped) with one cash entry.
     *
     * @return array{0: PayrollPeriod, 1: Employee}
     */
    private function approvedPeriodWithEntry(float $cashAmount, array $periodAttrs = []): array
    {
        $period = PayrollPeriod::factory()->create(array_merge([
            'type' => 'weekly',
            'status' => 'approved',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
        ], $periodAttrs));

        $employee = $this->addCashEntry($period, $cashAmount);

        return [$period, $employee];
    }

    /**
     * Add one employee paid fully in cash to the given period.
     */
    private function addCashEntry(PayrollPeriod $period, float $cashAmount, array $employeeAttrs = []): Employee
    {
        // Trial + sin IMSS => paga base en efectivo (cash_amount = net_pay).
        $employee = Employee::factory()->create(array_merge([
            'status' => 'active',
            'is_trial_period' => true,
            'trial_period_end_date' => null,
            'is_imss_enrolled' => false,
            'cash_pin' => '4321',
        ], $employeeAttrs));

        PayrollEntry::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'net_pay' => $cashAmount,
            'regular_pay' => 0,
            'deductions' => 0,
            'cash_amount' => $cashAmount,
            'bank_amount' => 0,
        ]);

        return $employee;
    }

    /**
     * As superadmin: prepara el efectivo y confirma la entrega (paso 1).
     * Deja al llamador SIN usuario autenticado específico (debe re-actuar).
     */
    private function prepareAndDeliver(PayrollPeriod $period): void
    {
        $this->actingAs($this->superadminUser());
        $this->post(route('payroll.closeCash', $period->id))->assertRedirect();
        $this->post(route('payroll.confirmDelivery', $period->id))->assertRedirect();
    }

    private function tallerDepartment(): Department
    {
        return Department::factory()->separatePayroll()->create(['name' => 'Taller']);
    }

    // ---- scope del cobrador ----------------------------------------------

    public function test_cobrador_general_sees_general_cash_page_but_not_taller(): void
    {
        [$general] = $this->approvedPeriodWithEntry(1000);
        [$taller] = $this->approvedPeriodWithEntry(800, [
            'department_id' => $this->tallerDepartment()->id,
            'start_date' => '2026-06-08', 'end_date' => '2026-06-14',
        ]);
        $this->prepareAndDeliver($general);
        $this->prepareAndDeliver($taller);

        $this->actingAsCobrador('cobrador_general');
        $this->get(route('payroll.cash', $general->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Payroll/Cash')
                ->where('can.deliverCash', false)
                ->where('can.collectCash', true)
                ->where('can.closeCollection', true)
                ->where('can.receiveReturn', false));
        $this->get(route('payroll.cash', $taller->id))->assertForbidden();
    }

    public function test_cobrador_taller_sees_taller_cash_page_but_not_general(): void
    {
        [$general] = $this->approvedPeriodWithEntry(1000);
        [$taller] = $this->approvedPeriodWithEntry(800, [
            'department_id' => $this->tallerDepartment()->id,
            'start_date' => '2026-06-08', 'end_date' => '2026-06-14',
        ]);
        $this->prepareAndDeliver($general);
        $this->prepareAndDeliver($taller);

        $this->actingAsCobrador('cobrador_taller');
        $this->get(route('payroll.cash', $taller->id))->assertOk();
        $this->get(route('payroll.cash', $general->id))->assertForbidden();
    }

    public function test_cobrador_collects_with_pin_in_own_scope(): void
    {
        [$period] = $this->approvedPeriodWithEntry(1000);
        $this->prepareAndDeliver($period);
        $payout = CashPayout::where('payroll_period_id', $period->id)->firstOrFail();

        $cobrador = $this->actingAsCobrador('cobrador_general');
        $this->post(route('payroll.payouts.collect', [$period->id, $payout->id]), ['pin' => '4321'])
            ->assertRedirect(route('payroll.cash', $period->id));

        $payout->refresh();
        $this->assertSame('paid', $payout->status);
        $this->assertSame($cobrador->id, $payout->collected_by);
    }

    public function test_cobrador_cannot_collect_outside_own_scope(): void
    {
        [$period] = $this->approvedPeriodWithEntry(1000);
        $this->prepareAndDeliver($period);
        $payout = CashPayout::where('payroll_period_id', $period->id)->firstOrFail();

        $this->actingAsCobrador('cobrador_taller');
        $this->post(route('payroll.payouts.collect', [$period->id, $payout->id]), ['pin' => '4321'])
            ->assertForbidden();

        $this->assertSame('pending', $payout->fresh()->status);
    }

    public function test_cobrador_forbidden_everywhere_else(): void
    {
        [$period] = $this->approvedPeriodWithEntry(1000);
        $this->actingAs($this->superadminUser());
        $this->post(route('payroll.closeCash', $period->id));

        $this->actingAsCobrador('cobrador_general');
        $this->post(route('payroll.closeCash', $period->id))->assertForbidden();
        $this->post(route('payroll.confirmDelivery', $period->id))->assertForbidden();
        $this->post(route('payroll.receiveReturn', $period->id))->assertForbidden();
        $this->post(route('payroll.reopenCollection', $period->id))->assertForbidden();
        $this->get(route('payroll.transfers', $period->id))->assertForbidden();
        $this->get(route('payroll.index'))->assertForbidden();
        $this->get(route('payroll.show', $period->id))->assertForbidden();
    }

    // ---- custodia exclusiva del superadmin --------------------------------

    public function test_admin_cannot_deliver_nor_receive_cash(): void
    {
        [$period] = $this->approvedPeriodWithEntry(1000);

        $this->actingAsAdmin();
        $this->post(route('payroll.closeCash', $period->id))->assertForbidden();
        $this->post(route('payroll.confirmDelivery', $period->id))->assertForbidden();
        $this->post(route('payroll.receiveReturn', $period->id))->assertForbidden();
        $this->post(route('payroll.reopenCollection', $period->id))->assertForbidden();
    }

    public function test_superadmin_delivers_cash(): void
    {
        [$period] = $this->approvedPeriodWithEntry(1000);

        $this->actingAsSuperadmin();
        $this->post(route('payroll.closeCash', $period->id))->assertRedirect(route('payroll.cash', $period->id));
        $this->post(route('payroll.confirmDelivery', $period->id))->assertRedirect(route('payroll.cash', $period->id));

        $period->refresh();
        $this->assertNotNull($period->cash_closed_at);
        $this->assertNotNull($period->cash_delivery_confirmed_at);
    }

    // ---- cierre del cobro ("Cerrar nómina") --------------------------------

    public function test_close_collection_freezes_return_amount_of_uncollected(): void
    {
        // Dos empleados: uno cobra $1,000, el otro deja pendientes $750.
        [$period] = $this->approvedPeriodWithEntry(1000);
        $this->addCashEntry($period, 750);
        $this->prepareAndDeliver($period);

        $cobrador = $this->actingAsCobrador('cobrador_general');
        $paid = CashPayout::where('payroll_period_id', $period->id)
            ->get()->firstWhere(fn (CashPayout $p) => (float) $p->total_due === 1000.0);
        $this->post(route('payroll.payouts.collect', [$period->id, $paid->id]), ['pin' => '4321']);

        $this->post(route('payroll.closeCollection', $period->id))
            ->assertRedirect(route('payroll.cash', $period->id))
            ->assertSessionHas('success');

        $period->refresh();
        $this->assertNotNull($period->cash_collection_closed_at);
        $this->assertSame($cobrador->id, $period->cash_collection_closed_by);
        $this->assertEqualsWithDelta(750.00, (float) $period->cash_return_amount, 0.01, 'solo lo no cobrado se regresa');
    }

    public function test_close_collection_with_everyone_paid_returns_zero(): void
    {
        [$period] = $this->approvedPeriodWithEntry(1000);
        $this->prepareAndDeliver($period);

        $this->actingAsCobrador('cobrador_general');
        $payout = CashPayout::where('payroll_period_id', $period->id)->firstOrFail();
        $this->post(route('payroll.payouts.collect', [$period->id, $payout->id]), ['pin' => '4321']);
        $this->post(route('payroll.closeCollection', $period->id));

        $this->assertEqualsWithDelta(0.00, (float) $period->fresh()->cash_return_amount, 0.01);
    }

    public function test_close_collection_requires_delivery_confirmed(): void
    {
        [$period] = $this->approvedPeriodWithEntry(1000);
        $this->actingAs($this->superadminUser());
        $this->post(route('payroll.closeCash', $period->id));
        // Entrega NO confirmada.

        $this->actingAsCobrador('cobrador_general');
        $this->post(route('payroll.closeCollection', $period->id))->assertSessionHas('error');
        $this->assertNull($period->fresh()->cash_collection_closed_at);
    }

    public function test_close_collection_twice_fails(): void
    {
        [$period] = $this->approvedPeriodWithEntry(1000);
        $this->prepareAndDeliver($period);

        $this->actingAsCobrador('cobrador_general');
        $this->post(route('payroll.closeCollection', $period->id))->assertSessionHas('success');
        $this->post(route('payroll.closeCollection', $period->id))->assertSessionHas('error');
    }

    public function test_close_collection_respects_cobrador_scope(): void
    {
        [$period] = $this->approvedPeriodWithEntry(1000);
        $this->prepareAndDeliver($period);

        $this->actingAsCobrador('cobrador_taller');
        $this->post(route('payroll.closeCollection', $period->id))->assertForbidden();
    }

    public function test_collect_blocked_after_collection_closed(): void
    {
        [$period] = $this->approvedPeriodWithEntry(1000);
        $this->prepareAndDeliver($period);

        $this->actingAsCobrador('cobrador_general');
        $this->post(route('payroll.closeCollection', $period->id));

        $payout = CashPayout::where('payroll_period_id', $period->id)->firstOrFail();
        $this->from(route('payroll.cash', $period->id))
            ->post(route('payroll.payouts.collect', [$period->id, $payout->id]), ['pin' => '4321'])
            ->assertSessionHas('error');

        $this->assertSame('pending', $payout->fresh()->status);
    }

    public function test_reprepare_cash_blocked_after_collection_closed(): void
    {
        [$period] = $this->approvedPeriodWithEntry(1000);
        $this->prepareAndDeliver($period);
        $this->actingAsCobrador('cobrador_general');
        $this->post(route('payroll.closeCollection', $period->id));

        $this->actingAsSuperadmin();
        $this->post(route('payroll.closeCash', $period->id))->assertSessionHas('error');
    }

    // ---- devolución del efectivo -------------------------------------------

    public function test_superadmin_receives_returned_cash(): void
    {
        [$period] = $this->approvedPeriodWithEntry(1000);
        $this->prepareAndDeliver($period);
        $this->actingAsCobrador('cobrador_general');
        $this->post(route('payroll.closeCollection', $period->id));

        $superadmin = $this->actingAsSuperadmin();
        $this->post(route('payroll.receiveReturn', $period->id))
            ->assertRedirect(route('payroll.cash', $period->id))
            ->assertSessionHas('success');

        $period->refresh();
        $this->assertNotNull($period->cash_return_received_at);
        $this->assertSame($superadmin->id, $period->cash_return_received_by);
    }

    public function test_receive_return_requires_collection_closed(): void
    {
        [$period] = $this->approvedPeriodWithEntry(1000);
        $this->prepareAndDeliver($period);

        $this->actingAsSuperadmin();
        $this->post(route('payroll.receiveReturn', $period->id))->assertSessionHas('error');
        $this->assertNull($period->fresh()->cash_return_received_at);
    }

    public function test_reopen_before_receive_clears_close_and_allows_collect(): void
    {
        [$period] = $this->approvedPeriodWithEntry(1000);
        $this->prepareAndDeliver($period);
        $this->actingAsCobrador('cobrador_general');
        $this->post(route('payroll.closeCollection', $period->id));

        $this->actingAsSuperadmin();
        $this->post(route('payroll.reopenCollection', $period->id))->assertSessionHas('success');

        $period->refresh();
        $this->assertNull($period->cash_collection_closed_at);
        $this->assertNull($period->cash_collection_closed_by);
        $this->assertNull($period->cash_return_amount);

        // Se puede volver a cobrar.
        $this->actingAsCobrador('cobrador_general');
        $payout = CashPayout::where('payroll_period_id', $period->id)->firstOrFail();
        $this->post(route('payroll.payouts.collect', [$period->id, $payout->id]), ['pin' => '4321']);
        $this->assertSame('paid', $payout->fresh()->status);
    }

    public function test_reopen_blocked_after_return_received(): void
    {
        [$period] = $this->approvedPeriodWithEntry(1000);
        $this->prepareAndDeliver($period);
        $this->actingAsCobrador('cobrador_general');
        $this->post(route('payroll.closeCollection', $period->id));

        $this->actingAsSuperadmin();
        $this->post(route('payroll.receiveReturn', $period->id));
        $this->post(route('payroll.reopenCollection', $period->id))->assertSessionHas('error');

        $this->assertNotNull($period->fresh()->cash_collection_closed_at);
    }

    // ---- el sobrante se acumula a la siguiente semana ----------------------

    public function test_uncollected_after_close_carries_to_next_period(): void
    {
        $employee = null;
        [$p1] = $this->approvedPeriodWithEntry(500);
        $employee = $p1->entries()->first()->employee;
        $this->prepareAndDeliver($p1);

        // El cobrador cierra sin que el empleado cobre: debe regresar $500.
        $this->actingAsCobrador('cobrador_general');
        $this->post(route('payroll.closeCollection', $p1->id));
        $this->assertEqualsWithDelta(500.00, (float) $p1->fresh()->cash_return_amount, 0.01);

        // La siguiente semana arrastra los $500 como acumulado del empleado.
        $p2 = PayrollPeriod::factory()->create([
            'type' => 'weekly', 'status' => 'approved',
            'start_date' => '2026-06-08', 'end_date' => '2026-06-14',
        ]);
        PayrollEntry::factory()->create([
            'payroll_period_id' => $p2->id, 'employee_id' => $employee->id,
            'net_pay' => 700, 'regular_pay' => 0, 'deductions' => 0,
            'cash_amount' => 700, 'bank_amount' => 0,
        ]);
        $this->actingAsSuperadmin();
        $this->post(route('payroll.closeCash', $p2->id));

        $payout2 = CashPayout::where('payroll_period_id', $p2->id)
            ->where('employee_id', $employee->id)
            ->firstOrFail();
        $this->assertEqualsWithDelta(500.00, (float) $payout2->opening_balance, 0.01);
        $this->assertEqualsWithDelta(1200.00, (float) $payout2->total_due, 0.01);
    }

    // ---- landing del cobrador ----------------------------------------------

    public function test_cash_collection_index_lists_only_own_scope(): void
    {
        [$general] = $this->approvedPeriodWithEntry(1000);
        [$taller] = $this->approvedPeriodWithEntry(800, [
            'department_id' => $this->tallerDepartment()->id,
            'start_date' => '2026-06-08', 'end_date' => '2026-06-14',
        ]);
        $this->prepareAndDeliver($general);
        $this->prepareAndDeliver($taller);

        $this->actingAsCobrador('cobrador_general');
        $this->get(route('payroll.cashCollection'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Payroll/CashCollectionIndex')
                ->has('periods', 1)
                ->where('periods.0.id', $general->id));

        $this->actingAsCobrador('cobrador_taller');
        $this->get(route('payroll.cashCollection'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('periods', 1)
                ->where('periods.0.id', $taller->id));
    }

    public function test_cash_collection_index_skips_unprepared_periods(): void
    {
        // Sin closeCash: no aparece en la lista.
        $this->approvedPeriodWithEntry(1000);

        $this->actingAsCobrador('cobrador_general');
        $this->get(route('payroll.cashCollection'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('periods', 0));
    }

    public function test_dashboard_redirects_cobrador_to_cash_collection(): void
    {
        $this->actingAsCobrador('cobrador_general');
        $this->get(route('dashboard'))->assertRedirect(route('payroll.cashCollection'));

        $this->actingAsCobrador('cobrador_taller');
        $this->get(route('dashboard'))->assertRedirect(route('payroll.cashCollection'));
    }
}
