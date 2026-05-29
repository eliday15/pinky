<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\FeatureTestCase;

/**
 * Feature tests for AuditLogController.
 *
 * Covers index (with filters), show, RBAC (logs.view → admin only), and the
 * Inertia prop contract for AuditLogs/Index and AuditLogs/Show.
 */
class AuditLogControllerTest extends FeatureTestCase
{
    // ---------------------------------------------------------------------
    // index
    // ---------------------------------------------------------------------

    public function test_admin_sees_audit_logs_index_with_expected_props(): void
    {
        $this->actingAsAdmin();
        AuditLog::factory()->count(3)->create();

        $this->get(route('audit-logs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('AuditLogs/Index')
                ->has('logs.data')
                ->has('users')
                ->has('filters')
                ->has('modules')
                ->has('actions'));
    }

    public function test_audit_logs_module_filter_is_applied(): void
    {
        $this->actingAsAdmin();
        AuditLog::factory()->create(['module' => AuditLog::MODULE_PAYROLL]);
        AuditLog::factory()->create(['module' => AuditLog::MODULE_EMPLOYEES]);

        $this->get(route('audit-logs.index', ['module' => AuditLog::MODULE_PAYROLL]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.module', AuditLog::MODULE_PAYROLL)
                ->has('logs.data', 1)
                ->where('logs.data.0.module', AuditLog::MODULE_PAYROLL));
    }

    public function test_audit_logs_action_filter_is_applied(): void
    {
        $this->actingAsAdmin();
        AuditLog::factory()->create(['action' => AuditLog::ACTION_DELETE]);
        AuditLog::factory()->create(['action' => AuditLog::ACTION_CREATE]);

        $this->get(route('audit-logs.index', ['action' => AuditLog::ACTION_DELETE]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.action', AuditLog::ACTION_DELETE)
                ->has('logs.data', 1));
    }

    public function test_audit_logs_user_filter_is_applied(): void
    {
        $admin = $this->actingAsAdmin();
        $mine = AuditLog::factory()->create(['user_id' => $admin->id]);
        AuditLog::factory()->create(['user_id' => $this->rrhhUser()->id]);

        $this->get(route('audit-logs.index', ['user_id' => $admin->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.user_id', (string) $admin->id)
                ->has('logs.data', 1)
                ->where('logs.data.0.id', $mine->id));
    }

    public function test_audit_logs_search_filter_matches_description(): void
    {
        $this->actingAsAdmin();
        $match = AuditLog::factory()->create(['description' => 'Zxqwerty unique description']);
        AuditLog::factory()->create(['description' => 'Something else entirely']);

        $this->get(route('audit-logs.index', ['search' => 'Zxqwerty unique']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.search', 'Zxqwerty unique')
                ->has('logs.data', 1)
                ->where('logs.data.0.id', $match->id));
    }

    public function test_audit_logs_date_range_filter_is_applied(): void
    {
        $this->actingAsAdmin();
        $inRange = AuditLog::factory()->create(['created_at' => '2026-03-15 10:00:00']);
        AuditLog::factory()->create(['created_at' => '2026-01-01 10:00:00']);

        $this->get(route('audit-logs.index', ['from_date' => '2026-03-01', 'to_date' => '2026-03-31']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.from_date', '2026-03-01')
                ->where('filters.to_date', '2026-03-31')
                ->has('logs.data', 1)
                ->where('logs.data.0.id', $inRange->id));
    }

    public function test_audit_logs_index_exposes_module_and_action_options(): void
    {
        $this->actingAsAdmin();

        $this->get(route('audit-logs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('modules', 7)
                ->has('actions', 9)
                ->where('modules.0.value', AuditLog::MODULE_EMPLOYEES)
                ->where('actions.0.value', AuditLog::ACTION_CREATE));
    }

    public function test_rrhh_cannot_view_audit_logs(): void
    {
        $this->actingAsRrhh();

        $this->get(route('audit-logs.index'))->assertForbidden();
    }

    public function test_supervisor_cannot_view_audit_logs(): void
    {
        $this->actingAsSupervisor();

        $this->get(route('audit-logs.index'))->assertForbidden();
    }

    public function test_employee_cannot_view_audit_logs(): void
    {
        $this->actingAsEmployee();

        $this->get(route('audit-logs.index'))->assertForbidden();
    }

    public function test_guest_redirected_to_login_from_audit_logs(): void
    {
        $this->get(route('audit-logs.index'))->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------------
    // show
    // ---------------------------------------------------------------------

    public function test_admin_sees_audit_log_show_with_expected_props(): void
    {
        $this->actingAsAdmin();
        $log = AuditLog::factory()->create();

        $this->get(route('audit-logs.show', $log))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('AuditLogs/Show')
                ->has('log')
                ->where('log.id', $log->id));
    }

    public function test_rrhh_cannot_view_audit_log_show(): void
    {
        $this->actingAsRrhh();
        $log = AuditLog::factory()->create();

        $this->get(route('audit-logs.show', $log))->assertForbidden();
    }

    public function test_employee_cannot_view_audit_log_show(): void
    {
        $this->actingAsEmployee();
        $log = AuditLog::factory()->create();

        $this->get(route('audit-logs.show', $log))->assertForbidden();
    }

    public function test_supervisor_cannot_view_audit_log_show(): void
    {
        $this->actingAsSupervisor();
        $log = AuditLog::factory()->create();

        $this->get(route('audit-logs.show', $log))->assertForbidden();
    }

    public function test_guest_redirected_to_login_from_audit_log_show(): void
    {
        $log = AuditLog::factory()->create();

        $this->get(route('audit-logs.show', $log))->assertRedirect(route('login'));
    }
}
