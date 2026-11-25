<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\BreakfastClaim;
use App\Models\CompensationType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\Position;
use App\Models\SystemSetting;
use App\Models\User;
use Tests\FeatureTestCase;

/**
 * Precisión de Elías (2026-11-25): el semiadmin VE TODO, pero sin montos. Cada
 * pantalla que se le abre se prueba buscando el importe en lo que viaja al
 * navegador; y las acciones siguen cerradas.
 */
class SemiadminReadsWithoutMoneyTest extends FeatureTestCase
{
    private function semiadmin(): User
    {
        $user = $this->createUser('semiadmin');
        $this->actingAs($user);

        return $user;
    }

    private function props($response): string
    {
        return json_encode($response->assertOk()->viewData('page')['props']);
    }

    public function test_payroll_period_shows_days_and_hours_without_a_single_amount(): void
    {
        $period = PayrollPeriod::factory()->weekly()->create(['created_by' => User::factory()->create()->id]);
        PayrollEntry::factory()->create([
            'payroll_period_id' => $period->id,
            'days_worked' => 6,
            'days_absent' => 1,
            'hourly_rate' => 111.11,
            'gross_pay' => 7654.32,
            'net_pay' => 6543.21,
            'cash_amount' => 1234.56,
            'bank_amount' => 5308.65,
        ]);
        $this->semiadmin();

        $json = $this->props($this->get(route('payroll.index')));
        $this->assertStringNotContainsString('6543.21', $json);

        $response = $this->get(route('payroll.show', $period));
        $json = $this->props($response);
        foreach (['111.11', '7654.32', '6543.21', '1234.56', '5308.65', 'net_pay', 'total_gross', 'calculation_breakdown'] as $leak) {
            $this->assertStringNotContainsString($leak, $json, "no debe viajar: {$leak}");
        }
        $response->assertInertia(fn ($page) => $page
            ->where('entries.0.days_worked', 6)
            ->where('entries.0.days_absent', 1)
            ->where('can.viewMoney', false)
            ->where('can.calculate', false)
            ->where('can.approve', false));

        $entry = PayrollEntry::first();
        $this->get(route('payroll.entry', $entry))->assertForbidden();
    }

    public function test_admin_still_sees_payroll_amounts(): void
    {
        $period = PayrollPeriod::factory()->weekly()->create(['created_by' => User::factory()->create()->id]);
        PayrollEntry::factory()->create(['payroll_period_id' => $period->id, 'net_pay' => 6543.21]);
        $this->actingAsAdmin();

        $this->assertStringContainsString('6543.21', $this->props($this->get(route('payroll.show', $period))));
    }

    public function test_settings_are_readable_but_peso_values_are_blank_and_not_editable(): void
    {
        SystemSetting::set('dinner_allowance_amount', 87.5, 'float', 'payroll');
        SystemSetting::set('overtime_rate_multiplier', 2, 'float', 'payroll');
        $this->semiadmin();

        $response = $this->get(route('settings.payroll'));
        $json = $this->props($response);
        $this->assertStringNotContainsString('87.5', $json);
        $response->assertInertia(fn ($page) => $page->where('can.edit', false));

        $this->put(route('settings.update'), ['settings' => []])->assertForbidden();
    }

    public function test_audit_log_is_readable_without_salaries_or_peso_figures(): void
    {
        $employee = Employee::factory()->create();
        $log = AuditLog::create([
            'module' => 'employees',
            'action' => 'updated',
            'auditable_type' => Employee::class,
            'auditable_id' => $employee->id,
            'old_values' => ['daily_salary' => '412.37', 'first_name' => 'Ana'],
            'new_values' => ['daily_salary' => '498.21', 'first_name' => 'Ana María'],
            'description' => 'Pago en efectivo de $3,456.78 entregado',
        ]);
        $this->semiadmin();

        $index = $this->props($this->get(route('audit-logs.index')));
        $show = $this->props($this->get(route('audit-logs.show', $log)));
        foreach ([$index, $show] as $json) {
            foreach (['412.37', '498.21', '3,456.78', 'daily_salary'] as $leak) {
                $this->assertStringNotContainsString($leak, $json, "no debe viajar: {$leak}");
            }
        }
        $this->assertStringContainsString('Ana Mar', $show, 'lo que no es dinero sí se ve');
    }

    public function test_breakfasts_show_who_and_when_without_cost_and_no_export(): void
    {
        BreakfastClaim::factory()->onDate(now()->toDateString())->create(['unit_cost' => 37.25]);
        $this->semiadmin();

        $json = $this->props($this->get(route('breakfasts.index')));
        $this->assertStringNotContainsString('37.25', $json);
        $this->assertStringNotContainsString('"unit_cost":37', $json);
        $this->get(route('breakfasts.export'))->assertForbidden();
    }

    public function test_catalogs_are_readable_without_amounts_and_stay_locked(): void
    {
        $position = Position::factory()->create(['base_hourly_rate' => 93.17]);
        CompensationType::factory()->create(['code' => 'X-'.uniqid(), 'fixed_amount' => 4567.89, 'is_active' => true]);
        $department = Department::factory()->create();
        $this->semiadmin();

        $this->assertStringNotContainsString('93.17', $this->props($this->get(route('positions.index'))));
        $this->assertStringNotContainsString('93.17', $this->props($this->get(route('positions.show', $position))));
        $this->assertStringNotContainsString('4567.89', $this->props($this->get(route('compensation-types.index'))));
        $this->get(route('departments.index'))->assertOk();
        $this->get(route('schedules.index'))->assertOk();
        $this->get(route('incident-types.index'))->assertOk();

        // Leer no es administrar: crear compensaciones sigue cerrado (lo más importante).
        $this->get(route('compensation-types.create'))->assertForbidden();
        $this->post(route('compensation-types.store'), ['name' => 'Bono', 'code' => 'B1'])->assertForbidden();
        $this->get(route('positions.edit', $position))->assertForbidden();
        $this->delete(route('departments.destroy', $department))->assertForbidden();
    }

    public function test_semiadmin_never_sees_money_even_with_a_second_role(): void
    {
        $user = $this->semiadmin();
        $user->assignRole('breakfast_vendor');

        $this->assertFalse($user->fresh()->canSeeMoney());
    }
}
