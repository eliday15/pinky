<?php

namespace Tests\Feature\Payroll;

use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Services\TwoFactorService;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\FeatureTestCase;

/**
 * Feature tests for the PayrollController front-to-back contract.
 *
 * Covers index/create/store/show/calculate/approve/markPaid/destroy/
 * exportContpaqi/entryDetail across the seeded RBAC matrix, Inertia
 * prop contracts (matched against the Vue Pages/Payroll/*.vue defineProps),
 * status-lifecycle guards, validation rules, and DB effects.
 *
 * Among seeded roles only `admin` holds payroll.* permissions; rrhh,
 * supervisor and employee must receive 403 on every action.
 */
class PayrollControllerTest extends FeatureTestCase
{
    /**
     * Bind a fake TwoFactorService so approve/markPaid clear the 2FA gate.
     *
     * The harness stores a plaintext device secret, which the real service
     * tries to Crypt::decrypt — so the genuine code path can't be exercised
     * with a real TOTP here. The 2FA check is orthogonal to payroll logic.
     */
    private function bypassTwoFactor(): void
    {
        $this->mock(TwoFactorService::class, function ($mock) {
            $mock->shouldReceive('verifyCode')->andReturn(true);
        });
    }

    // ---------------------------------------------------------------------
    // index
    // ---------------------------------------------------------------------

    public function test_index_renders_inertia_page_with_periods_prop_for_admin(): void
    {
        PayrollPeriod::factory()->count(2)->create();

        $this->actingAsAdmin();

        $this->get(route('payroll.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Payroll/Index')
                ->has('periods')
                ->has('periods.data', 2));
    }

    public function test_index_forbidden_for_rrhh(): void
    {
        $this->actingAsRrhh();
        $this->get(route('payroll.index'))->assertForbidden();
    }

    public function test_index_forbidden_for_supervisor(): void
    {
        $this->actingAsSupervisor();
        $this->get(route('payroll.index'))->assertForbidden();
    }

    public function test_index_forbidden_for_employee(): void
    {
        $this->actingAsEmployee();
        $this->get(route('payroll.index'))->assertForbidden();
    }

    public function test_index_redirects_guest_to_login(): void
    {
        $this->get(route('payroll.index'))->assertRedirect(route('login'));
    }

    public function test_index_exposes_entries_count_and_net_pay_sum_consumed_by_vue(): void
    {
        // The Index.vue table renders period.entries_count and
        // period.entries_sum_net_pay (withCount/withSum aggregates).
        $period = PayrollPeriod::factory()->review()->create();
        PayrollEntry::factory()->create([
            'payroll_period_id' => $period->id,
            'net_pay' => 1234.56,
        ]);

        $this->actingAsAdmin();

        $this->get(route('payroll.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Payroll/Index')
                ->has('periods.data', 1)
                ->has('periods.data.0.entries_count')
                ->where('periods.data.0.entries_count', 1)
                ->has('periods.data.0.entries_sum_net_pay'));
    }

    // ---------------------------------------------------------------------
    // create
    // ---------------------------------------------------------------------

    public function test_create_renders_inertia_page_with_suggested_dates_for_admin(): void
    {
        $this->actingAsAdmin();

        $this->get(route('payroll.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Payroll/Create')
                ->has('suggestedDates', fn (Assert $dates) => $dates
                    ->has('start_date')
                    ->has('end_date')
                    ->has('payment_date')));
    }

    public function test_create_forbidden_for_rrhh(): void
    {
        $this->actingAsRrhh();
        $this->get(route('payroll.create'))->assertForbidden();
    }

    public function test_create_forbidden_for_supervisor(): void
    {
        $this->actingAsSupervisor();
        $this->get(route('payroll.create'))->assertForbidden();
    }

    public function test_create_forbidden_for_employee(): void
    {
        $this->actingAsEmployee();
        $this->get(route('payroll.create'))->assertForbidden();
    }

    public function test_create_redirects_guest_to_login(): void
    {
        $this->get(route('payroll.create'))->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------------
    // store
    // ---------------------------------------------------------------------

    public function test_store_creates_draft_period_and_redirects_to_show(): void
    {
        $admin = $this->actingAsAdmin();

        $response = $this->post(route('payroll.store'), [
            'name' => 'Quincena de prueba',
            'type' => 'biweekly',
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-14',
            'payment_date' => '2026-01-17',
        ]);

        $period = PayrollPeriod::where('name', 'Quincena de prueba')->firstOrFail();

        $response->assertRedirect(route('payroll.show', $period))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('payroll_periods', [
            'name' => 'Quincena de prueba',
            'type' => 'biweekly',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);
    }

    public function test_store_requires_all_fields(): void
    {
        $this->actingAsAdmin();

        $this->from(route('payroll.create'))
            ->post(route('payroll.store'), [])
            ->assertRedirect(route('payroll.create'))
            ->assertSessionHasErrors(['name', 'type', 'start_date', 'end_date', 'payment_date']);
    }

    public function test_store_rejects_invalid_type_enum(): void
    {
        $this->actingAsAdmin();

        $this->from(route('payroll.create'))
            ->post(route('payroll.store'), [
                'name' => 'Periodo malo',
                'type' => 'annual',
                'start_date' => '2026-02-01',
                'end_date' => '2026-02-14',
                'payment_date' => '2026-02-17',
            ])
            ->assertSessionHasErrors(['type']);
    }

    public function test_store_rejects_end_date_not_after_start(): void
    {
        $this->actingAsAdmin();

        $this->from(route('payroll.create'))
            ->post(route('payroll.store'), [
                'name' => 'Periodo malo',
                'type' => 'weekly',
                'start_date' => '2026-03-10',
                'end_date' => '2026-03-10',
                'payment_date' => '2026-03-15',
            ])
            ->assertSessionHasErrors(['end_date']);
    }

    public function test_store_rejects_payment_date_before_end_date(): void
    {
        $this->actingAsAdmin();

        $this->from(route('payroll.create'))
            ->post(route('payroll.store'), [
                'name' => 'Periodo malo',
                'type' => 'weekly',
                'start_date' => '2026-04-01',
                'end_date' => '2026-04-07',
                'payment_date' => '2026-04-05',
            ])
            ->assertSessionHasErrors(['payment_date']);
    }

    public function test_store_rejects_overlapping_period(): void
    {
        $this->actingAsAdmin();

        PayrollPeriod::factory()->create([
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-14',
        ]);

        $this->from(route('payroll.create'))
            ->post(route('payroll.store'), [
                'name' => 'Traslape',
                'type' => 'biweekly',
                'start_date' => '2026-05-07',
                'end_date' => '2026-05-20',
                'payment_date' => '2026-05-23',
            ])
            ->assertSessionHasErrors(['start_date']);

        $this->assertDatabaseMissing('payroll_periods', ['name' => 'Traslape']);
    }

    public function test_store_forbidden_for_rrhh(): void
    {
        $this->actingAsRrhh();

        $this->post(route('payroll.store'), [
            'name' => 'No permitido',
            'type' => 'weekly',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
            'payment_date' => '2026-06-10',
        ])->assertForbidden();
    }

    public function test_store_forbidden_for_supervisor(): void
    {
        $this->actingAsSupervisor();

        $this->post(route('payroll.store'), [
            'name' => 'No permitido',
            'type' => 'weekly',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
            'payment_date' => '2026-06-10',
        ])->assertForbidden();
    }

    public function test_store_forbidden_for_employee(): void
    {
        $this->actingAsEmployee();

        $this->post(route('payroll.store'), [
            'name' => 'No permitido',
            'type' => 'weekly',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
            'payment_date' => '2026-06-10',
        ])->assertForbidden();
    }

    public function test_store_redirects_guest_to_login(): void
    {
        $this->post(route('payroll.store'), [])->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------------
    // show
    // ---------------------------------------------------------------------

    public function test_show_renders_inertia_page_with_full_prop_contract(): void
    {
        $period = PayrollPeriod::factory()->review()->create();
        $entry = PayrollEntry::factory()->create(['payroll_period_id' => $period->id]);

        $this->actingAsAdmin();

        $this->get(route('payroll.show', $period))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Payroll/Show')
                ->has('period')
                ->where('period.id', $period->id)
                ->has('entries', 1)
                ->has('summary', fn (Assert $summary) => $summary
                    ->has('employee_count')
                    ->has('total_gross')
                    ->has('total_net')
                    ->has('total_deductions')
                    ->has('total_overtime')
                    ->has('average_pay')
                    ->has('by_department'))
                ->has('can', fn (Assert $can) => $can
                    ->where('viewComplete', true)
                    ->where('calculate', true)
                    ->where('approve', true)
                    ->where('export', true)));
    }

    public function test_show_orders_entries_by_net_pay_desc(): void
    {
        // Controller sorts entries by net_pay desc; the Show.vue list relies
        // on that ordering. Verify the highest-paid entry comes first.
        $period = PayrollPeriod::factory()->review()->create();
        $low = PayrollEntry::factory()->create([
            'payroll_period_id' => $period->id,
            'net_pay' => 100.00,
        ]);
        $high = PayrollEntry::factory()->create([
            'payroll_period_id' => $period->id,
            'net_pay' => 9000.00,
        ]);

        $this->actingAsAdmin();

        $this->get(route('payroll.show', $period))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Payroll/Show')
                ->has('entries', 2)
                ->where('entries.0.id', $high->id)
                ->where('entries.1.id', $low->id)
                ->has('summary.by_department'));
    }

    public function test_show_forbidden_for_rrhh(): void
    {
        // rrhh holds attendance/employees perms but NO payroll.* perms.
        $period = PayrollPeriod::factory()->create();
        $this->actingAsRrhh();
        $this->get(route('payroll.show', $period))->assertForbidden();
    }

    public function test_show_forbidden_for_supervisor(): void
    {
        $period = PayrollPeriod::factory()->create();
        $this->actingAsSupervisor();
        $this->get(route('payroll.show', $period))->assertForbidden();
    }

    public function test_show_forbidden_for_employee(): void
    {
        $period = PayrollPeriod::factory()->create();
        $this->actingAsEmployee();
        $this->get(route('payroll.show', $period))->assertForbidden();
    }

    public function test_show_redirects_guest_to_login(): void
    {
        $period = PayrollPeriod::factory()->create();
        $this->get(route('payroll.show', $period))->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------------
    // calculate
    // ---------------------------------------------------------------------

    public function test_calculate_draft_period_sets_status_review_and_redirects(): void
    {
        $period = PayrollPeriod::factory()->draft()->create([
            'start_date' => '2026-07-06',
            'end_date' => '2026-07-12',
        ]);
        Employee::factory()->count(2)->create(['status' => 'active']);

        $this->actingAsAdmin();

        $this->post(route('payroll.calculate', $period))
            ->assertRedirect(route('payroll.show', $period))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('payroll_periods', [
            'id' => $period->id,
            'status' => 'review',
        ]);
    }

    public function test_calculate_blocked_for_approved_period(): void
    {
        $period = PayrollPeriod::factory()->approved()->create();

        $this->actingAsAdmin();

        $this->from(route('payroll.show', $period))
            ->post(route('payroll.calculate', $period))
            ->assertRedirect(route('payroll.show', $period))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('payroll_periods', [
            'id' => $period->id,
            'status' => 'approved',
        ]);
    }

    public function test_calculate_allowed_when_period_in_review(): void
    {
        // Controller permits recalculation while status is draft OR review.
        $period = PayrollPeriod::factory()->review()->create([
            'start_date' => '2026-07-13',
            'end_date' => '2026-07-19',
        ]);
        Employee::factory()->count(1)->create(['status' => 'active']);

        $this->actingAsAdmin();

        $this->post(route('payroll.calculate', $period))
            ->assertRedirect(route('payroll.show', $period))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('payroll_periods', [
            'id' => $period->id,
            'status' => 'review',
        ]);
    }

    public function test_calculate_blocked_for_paid_period(): void
    {
        $period = PayrollPeriod::factory()->paid()->create();

        $this->actingAsAdmin();

        $this->from(route('payroll.show', $period))
            ->post(route('payroll.calculate', $period))
            ->assertRedirect(route('payroll.show', $period))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('payroll_periods', [
            'id' => $period->id,
            'status' => 'paid',
        ]);
    }

    public function test_calculate_forbidden_for_rrhh(): void
    {
        $period = PayrollPeriod::factory()->draft()->create();
        $this->actingAsRrhh();
        $this->post(route('payroll.calculate', $period))->assertForbidden();
    }

    public function test_calculate_forbidden_for_supervisor(): void
    {
        $period = PayrollPeriod::factory()->draft()->create();
        $this->actingAsSupervisor();
        $this->post(route('payroll.calculate', $period))->assertForbidden();
    }

    public function test_calculate_forbidden_for_employee(): void
    {
        $period = PayrollPeriod::factory()->draft()->create();
        $this->actingAsEmployee();
        $this->post(route('payroll.calculate', $period))->assertForbidden();
    }

    public function test_calculate_redirects_guest_to_login(): void
    {
        $period = PayrollPeriod::factory()->draft()->create();
        $this->post(route('payroll.calculate', $period))->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------------
    // approve
    // ---------------------------------------------------------------------

    public function test_approve_review_period_sets_status_approved(): void
    {
        $this->bypassTwoFactor();
        $period = PayrollPeriod::factory()->review()->create();

        $admin = $this->actingAsAdmin();

        $this->post(route('payroll.approve', $period), ['two_factor_code' => '123456'])
            ->assertRedirect(route('payroll.show', $period))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('payroll_periods', [
            'id' => $period->id,
            'status' => 'approved',
            'approved_by' => $admin->id,
        ]);
    }

    public function test_approve_blocked_when_not_in_review(): void
    {
        $this->bypassTwoFactor();
        $period = PayrollPeriod::factory()->draft()->create();

        $this->actingAsAdmin();

        $this->from(route('payroll.show', $period))
            ->post(route('payroll.approve', $period), ['two_factor_code' => '123456'])
            ->assertRedirect(route('payroll.show', $period))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('payroll_periods', [
            'id' => $period->id,
            'status' => 'draft',
        ]);
    }

    public function test_approve_rejects_invalid_two_factor_code(): void
    {
        // Admin user created with a confirmed 2FA device, so verifyTwoFactorCode
        // is enforced. A wrong code must throw a validation error and NOT approve.
        $this->mock(TwoFactorService::class, function ($mock) {
            $mock->shouldReceive('verifyCode')->andReturn(false);
        });
        $period = PayrollPeriod::factory()->review()->create();

        $this->actingAsAdmin();

        $this->from(route('payroll.show', $period))
            ->post(route('payroll.approve', $period), ['two_factor_code' => '000000'])
            ->assertSessionHasErrors(['two_factor_code']);

        $this->assertDatabaseHas('payroll_periods', [
            'id' => $period->id,
            'status' => 'review',
        ]);
    }

    public function test_approve_forbidden_for_rrhh(): void
    {
        $period = PayrollPeriod::factory()->review()->create();
        $this->actingAsRrhh();
        $this->post(route('payroll.approve', $period), ['two_factor_code' => '123456'])
            ->assertForbidden();
    }

    public function test_approve_forbidden_for_supervisor(): void
    {
        $period = PayrollPeriod::factory()->review()->create();
        $this->actingAsSupervisor();
        $this->post(route('payroll.approve', $period), ['two_factor_code' => '123456'])
            ->assertForbidden();
    }

    public function test_approve_forbidden_for_employee(): void
    {
        $period = PayrollPeriod::factory()->review()->create();
        $this->actingAsEmployee();
        $this->post(route('payroll.approve', $period), ['two_factor_code' => '123456'])
            ->assertForbidden();
    }

    public function test_approve_redirects_guest_to_login(): void
    {
        $period = PayrollPeriod::factory()->review()->create();
        $this->post(route('payroll.approve', $period))->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------------
    // markPaid
    // ---------------------------------------------------------------------

    public function test_mark_paid_approved_period_sets_status_paid(): void
    {
        $this->bypassTwoFactor();
        $period = PayrollPeriod::factory()->approved()->create();

        $this->actingAsAdmin();

        $this->post(route('payroll.markPaid', $period), ['two_factor_code' => '123456'])
            ->assertRedirect(route('payroll.show', $period))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('payroll_periods', [
            'id' => $period->id,
            'status' => 'paid',
        ]);
    }

    public function test_mark_paid_blocked_when_not_approved(): void
    {
        $this->bypassTwoFactor();
        $period = PayrollPeriod::factory()->review()->create();

        $this->actingAsAdmin();

        $this->from(route('payroll.show', $period))
            ->post(route('payroll.markPaid', $period), ['two_factor_code' => '123456'])
            ->assertRedirect(route('payroll.show', $period))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('payroll_periods', [
            'id' => $period->id,
            'status' => 'review',
        ]);
    }

    public function test_mark_paid_forbidden_for_rrhh(): void
    {
        $period = PayrollPeriod::factory()->approved()->create();
        $this->actingAsRrhh();
        $this->post(route('payroll.markPaid', $period), ['two_factor_code' => '123456'])
            ->assertForbidden();
    }

    public function test_mark_paid_forbidden_for_supervisor(): void
    {
        $period = PayrollPeriod::factory()->approved()->create();
        $this->actingAsSupervisor();
        $this->post(route('payroll.markPaid', $period), ['two_factor_code' => '123456'])
            ->assertForbidden();
    }

    public function test_mark_paid_forbidden_for_employee(): void
    {
        $period = PayrollPeriod::factory()->approved()->create();
        $this->actingAsEmployee();
        $this->post(route('payroll.markPaid', $period), ['two_factor_code' => '123456'])
            ->assertForbidden();
    }

    public function test_mark_paid_redirects_guest_to_login(): void
    {
        $period = PayrollPeriod::factory()->approved()->create();
        $this->post(route('payroll.markPaid', $period))->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------------
    // destroy
    // ---------------------------------------------------------------------

    public function test_destroy_draft_period_deletes_period_and_entries(): void
    {
        $period = PayrollPeriod::factory()->draft()->create();
        $entry = PayrollEntry::factory()->create(['payroll_period_id' => $period->id]);

        $this->actingAsAdmin();

        $this->delete(route('payroll.destroy', $period))
            ->assertRedirect(route('payroll.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('payroll_periods', ['id' => $period->id]);
        $this->assertDatabaseMissing('payroll_entries', ['id' => $entry->id]);
    }

    public function test_admin_can_destroy_non_draft_period(): void
    {
        // Admins may delete periods in any status (post-hoc cleanup power);
        // the draft-only guard applies to non-admin users.
        $period = PayrollPeriod::factory()->approved()->create();
        $entry = PayrollEntry::factory()->create(['payroll_period_id' => $period->id]);

        $this->actingAsAdmin();

        $this->delete(route('payroll.destroy', $period))
            ->assertRedirect(route('payroll.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('payroll_periods', ['id' => $period->id]);
        $this->assertDatabaseMissing('payroll_entries', ['id' => $entry->id]);
    }

    public function test_destroy_blocked_for_non_draft_period_without_admin_role(): void
    {
        // A user holding payroll.create directly (no admin role) keeps the
        // original draft-only restriction.
        $period = PayrollPeriod::factory()->approved()->create();

        $user = $this->createUser();
        $user->givePermissionTo('payroll.create');
        $this->actingAs($user);

        $this->from(route('payroll.show', $period))
            ->delete(route('payroll.destroy', $period))
            ->assertRedirect(route('payroll.show', $period))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('payroll_periods', ['id' => $period->id]);
    }

    public function test_destroy_forbidden_for_rrhh(): void
    {
        $period = PayrollPeriod::factory()->draft()->create();
        $this->actingAsRrhh();
        $this->delete(route('payroll.destroy', $period))->assertForbidden();
    }

    public function test_destroy_forbidden_for_supervisor(): void
    {
        $period = PayrollPeriod::factory()->draft()->create();
        $this->actingAsSupervisor();
        $this->delete(route('payroll.destroy', $period))->assertForbidden();
    }

    public function test_destroy_forbidden_for_employee(): void
    {
        $period = PayrollPeriod::factory()->draft()->create();
        $this->actingAsEmployee();
        $this->delete(route('payroll.destroy', $period))->assertForbidden();
    }

    public function test_destroy_redirects_guest_to_login(): void
    {
        $period = PayrollPeriod::factory()->draft()->create();
        $this->delete(route('payroll.destroy', $period))->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------------
    // exportContpaqi
    // ---------------------------------------------------------------------

    public function test_export_contpaqi_downloads_when_period_has_entries(): void
    {
        $period = PayrollPeriod::factory()->review()->create();
        PayrollEntry::factory()->create(['payroll_period_id' => $period->id]);

        $this->actingAsAdmin();

        $response = $this->get(route('payroll.export.contpaqi', $period));

        $response->assertOk();
        $this->assertStringContainsString(
            'attachment',
            (string) $response->headers->get('content-disposition')
        );
    }

    public function test_export_contpaqi_redirects_with_error_when_no_entries(): void
    {
        $period = PayrollPeriod::factory()->draft()->create();

        $this->actingAsAdmin();

        $this->from(route('payroll.show', $period))
            ->get(route('payroll.export.contpaqi', $period))
            ->assertRedirect(route('payroll.show', $period))
            ->assertSessionHas('error');
    }

    public function test_export_contpaqi_supports_csv_format(): void
    {
        $period = PayrollPeriod::factory()->review()->create();
        PayrollEntry::factory()->create(['payroll_period_id' => $period->id]);

        $this->actingAsAdmin();

        $response = $this->get(route('payroll.export.contpaqi', [$period, 'format' => 'csv']));

        $response->assertOk();
        $this->assertStringContainsString(
            '.csv',
            (string) $response->headers->get('content-disposition')
        );
    }

    public function test_export_contpaqi_forbidden_for_rrhh(): void
    {
        $period = PayrollPeriod::factory()->review()->create();
        PayrollEntry::factory()->create(['payroll_period_id' => $period->id]);

        $this->actingAsRrhh();
        $this->get(route('payroll.export.contpaqi', $period))->assertForbidden();
    }

    public function test_export_contpaqi_forbidden_for_supervisor(): void
    {
        $period = PayrollPeriod::factory()->review()->create();
        PayrollEntry::factory()->create(['payroll_period_id' => $period->id]);

        $this->actingAsSupervisor();
        $this->get(route('payroll.export.contpaqi', $period))->assertForbidden();
    }

    public function test_export_contpaqi_forbidden_for_employee(): void
    {
        $period = PayrollPeriod::factory()->review()->create();
        PayrollEntry::factory()->create(['payroll_period_id' => $period->id]);

        $this->actingAsEmployee();
        $this->get(route('payroll.export.contpaqi', $period))->assertForbidden();
    }

    public function test_export_contpaqi_redirects_guest_to_login(): void
    {
        $period = PayrollPeriod::factory()->review()->create();
        $this->get(route('payroll.export.contpaqi', $period))->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------------
    // entryDetail
    // ---------------------------------------------------------------------

    public function test_entry_detail_renders_inertia_page_with_entry_prop(): void
    {
        $period = PayrollPeriod::factory()->review()->create();
        $entry = PayrollEntry::factory()->create(['payroll_period_id' => $period->id]);

        $this->actingAsAdmin();

        $this->get(route('payroll.entry', $entry))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Payroll/EntryDetail')
                ->has('entry')
                ->where('entry.id', $entry->id)
                // EntryDetail.vue reads entry.employee.* and entry.payroll_period.*
                // (relations eager-loaded by the controller).
                ->has('entry.employee')
                ->where('entry.payroll_period.id', $period->id)
                ->has('entry.net_pay'));
    }

    public function test_entry_detail_forbidden_for_rrhh(): void
    {
        $entry = PayrollEntry::factory()->create();
        $this->actingAsRrhh();
        $this->get(route('payroll.entry', $entry))->assertForbidden();
    }

    public function test_entry_detail_forbidden_for_supervisor(): void
    {
        $entry = PayrollEntry::factory()->create();
        $this->actingAsSupervisor();
        $this->get(route('payroll.entry', $entry))->assertForbidden();
    }

    public function test_entry_detail_forbidden_for_employee(): void
    {
        $entry = PayrollEntry::factory()->create();
        $this->actingAsEmployee();
        $this->get(route('payroll.entry', $entry))->assertForbidden();
    }

    public function test_entry_detail_redirects_guest_to_login(): void
    {
        $entry = PayrollEntry::factory()->create();
        $this->get(route('payroll.entry', $entry))->assertRedirect(route('login'));
    }
}
