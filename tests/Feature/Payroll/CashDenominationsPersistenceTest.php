<?php

namespace Tests\Feature\Payroll;

use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\FeatureTestCase;

/**
 * Denominaciones habilitadas persistidas POR PERIODO (Luis 2026-07-16).
 *
 * El custodio desmarca las denominaciones que no tiene (ej. $1000). Antes la
 * elección vivía sólo en el localStorage de su navegador, así que el cobrador
 * —en otra máquina— veía el desglose con billetes de $1000. Ahora se guarda en
 * el periodo y el prop `enabledDenominations` la expone a AMBOS pasos.
 */
class CashDenominationsPersistenceTest extends FeatureTestCase
{
    private function approvedPeriodWithCash(float $cashAmount = 2258): PayrollPeriod
    {
        $period = PayrollPeriod::factory()->create([
            'type' => 'weekly',
            'status' => 'approved',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
        ]);

        $employee = Employee::factory()->create([
            'status' => 'active',
            'is_trial_period' => true,
            'is_imss_enrolled' => false,
            'cash_pin' => '4321',
        ]);

        PayrollEntry::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'net_pay' => $cashAmount,
            'regular_pay' => 0,
            'deductions' => 0,
            'cash_amount' => $cashAmount,
            'bank_amount' => 0,
        ]);

        return $period;
    }

    public function test_custodio_saves_enabled_denominations_on_the_period(): void
    {
        $period = $this->approvedPeriodWithCash();
        $this->actingAs($this->superadminUser());

        // Desmarca $1000: quedan de $500 para abajo.
        $enabled = [500, 200, 100, 50, 20, 10, 5, 2, 1];
        $this->post(route('payroll.cashDenominations', $period->id), ['denominations' => $enabled])
            ->assertRedirect();

        $this->assertSame($enabled, $period->fresh()->cash_enabled_denominations);
    }

    public function test_saved_denominations_are_exposed_to_the_cash_page(): void
    {
        $period = $this->approvedPeriodWithCash();
        $period->update(['cash_enabled_denominations' => [500, 200, 100, 50, 20, 10, 5, 2, 1]]);

        $this->actingAs($this->superadminUser());
        $this->get(route('payroll.cash', $period->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Payroll/Cash')
                ->where('enabledDenominations', [500, 200, 100, 50, 20, 10, 5, 2, 1]));
    }

    public function test_cobrador_sees_the_same_saved_denominations(): void
    {
        $period = $this->approvedPeriodWithCash();
        // Custodio prepara y confirma la entrega para que el cobrador pueda entrar.
        $this->actingAs($this->superadminUser());
        $this->post(route('payroll.closeCash', $period->id))->assertRedirect();
        $this->post(route('payroll.cashDenominations', $period->id), [
            'denominations' => [500, 200, 100, 50, 20, 10, 5, 2, 1],
        ])->assertRedirect();
        $this->post(route('payroll.confirmDelivery', $period->id))->assertRedirect();

        // El cobrador —en otra sesión/máquina— ve el MISMO set (sin $1000).
        $this->actingAsCobrador('cobrador_general');
        $this->get(route('payroll.cash', $period->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('enabledDenominations', [500, 200, 100, 50, 20, 10, 5, 2, 1])
                ->where('can.deliverCash', false));
    }

    public function test_cobrador_cannot_change_denominations(): void
    {
        $period = $this->approvedPeriodWithCash();
        $this->actingAsCobrador('cobrador_general');

        $this->post(route('payroll.cashDenominations', $period->id), ['denominations' => [500, 200]])
            ->assertForbidden();
    }

    public function test_denominations_must_be_valid_and_non_empty(): void
    {
        $period = $this->approvedPeriodWithCash();
        $this->actingAs($this->superadminUser());

        $this->post(route('payroll.cashDenominations', $period->id), ['denominations' => []])
            ->assertSessionHasErrors('denominations');

        $this->post(route('payroll.cashDenominations', $period->id), ['denominations' => [7, 3]])
            ->assertSessionHasErrors('denominations.0');
    }
}
