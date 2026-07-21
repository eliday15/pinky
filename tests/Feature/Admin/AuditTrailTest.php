<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Support\AuditContext;
use Tests\FeatureTestCase;

/**
 * The audit trail must answer, for every important action: who did it, what
 * they did, to whom, and from where.
 */
class AuditTrailTest extends FeatureTestCase
{
    /** Every entry snapshots the actor's name and role, not just their id. */
    public function test_records_actor_name_and_role_snapshot(): void
    {
        $admin = $this->actingAsAdmin(['name' => 'Dani Ramirez']);

        $employee = Employee::factory()->create(['full_name' => 'Juan Perez']);

        $entry = AuditLog::where('auditable_type', Employee::class)
            ->where('auditable_id', $employee->id)
            ->firstOrFail();

        $this->assertSame($admin->id, $entry->user_id);
        $this->assertSame('Dani Ramirez', $entry->actor_name);
        $this->assertSame('admin', $entry->actor_role);
        $this->assertSame(AuditContext::CONTEXT_WEB, $entry->context);
    }

    /** The actor snapshot survives the user being renamed or deleted. */
    public function test_actor_label_survives_user_deletion(): void
    {
        $admin = $this->actingAsAdmin(['name' => 'Dani Ramirez']);
        $employee = Employee::factory()->create();

        $entry = AuditLog::where('auditable_type', Employee::class)
            ->where('auditable_id', $employee->id)
            ->firstOrFail();

        $admin->delete();
        $entry->refresh();

        $this->assertNull($entry->user_id);
        $this->assertSame('Dani Ramirez', $entry->actor_label);
    }

    /** Entries are tied to the employee they are about, so you can filter by person. */
    public function test_links_entry_to_the_affected_employee(): void
    {
        $this->actingAsAdmin();

        $employee = Employee::factory()->create(['full_name' => 'Juan Perez']);
        $employee->update(['full_name' => 'Juan Perez Ramirez']);

        $entry = AuditLog::where('auditable_type', Employee::class)
            ->where('action', AuditLog::ACTION_UPDATE)
            ->firstOrFail();

        $this->assertSame($employee->id, $entry->employee_id);
        $this->assertStringContainsString('Juan Perez', $entry->subject_label);
    }

    /** A change that only touched excluded fields must not leave a blank entry. */
    public function test_does_not_record_an_entry_with_no_visible_changes(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->create();

        AuditLog::query()->delete();

        $employee->touch();

        $this->assertSame(0, AuditLog::where('action', AuditLog::ACTION_UPDATE)->count());
    }

    /** A semantic event absorbs the generic entry so one action leaves one line. */
    public function test_semantic_event_absorbs_the_automatic_entry(): void
    {
        $this->actingAsAdmin();

        $employee = Employee::factory()->create(['full_name' => 'Juan Perez']);
        $authorization = Authorization::factory()->create([
            'employee_id' => $employee->id,
            'status' => 'pending',
        ]);

        AuditLog::query()->delete();

        $authorization->update(['status' => 'approved']);
        $authorization->recordAuditEvent(
            action: AuditLog::ACTION_APPROVE,
            description: 'Aprobo Tiempo Extra de Juan Perez',
        );

        $entries = AuditLog::where('auditable_type', Authorization::class)->get();

        $this->assertCount(1, $entries, 'One approval must leave exactly one entry.');
        $this->assertSame(AuditLog::ACTION_APPROVE, $entries->first()->action);
        // The absorbed diff is preserved on the surviving entry.
        $this->assertSame('pending', $entries->first()->old_values['status'] ?? null);
        $this->assertSame('approved', $entries->first()->new_values['status'] ?? null);
    }

    /** Two separate automatic changes still leave two entries. */
    public function test_automatic_entries_do_not_absorb_each_other(): void
    {
        $this->actingAsAdmin();

        $employee = Employee::factory()->create();
        AuditLog::query()->delete();

        $employee->update(['full_name' => 'Primero']);
        $employee->update(['full_name' => 'Segundo']);

        $this->assertSame(2, AuditLog::where('auditable_type', Employee::class)->count());
    }

    /** Payroll periods are audited — previously they had no trail at all. */
    public function test_payroll_period_changes_are_audited(): void
    {
        $this->actingAsAdmin();

        $period = PayrollPeriod::factory()->create();

        $entry = AuditLog::where('auditable_type', PayrollPeriod::class)
            ->where('action', AuditLog::ACTION_CREATE)
            ->firstOrFail();

        $this->assertSame('payroll', $entry->module);
        $this->assertStringContainsString($period->name, $entry->subject_label);
    }

    /** User and role changes are audited, and credentials never are. */
    public function test_user_changes_are_audited_without_credentials(): void
    {
        $this->actingAsAdmin();

        $user = User::factory()->create(['name' => 'Nuevo Usuario']);

        $entry = AuditLog::where('auditable_type', User::class)
            ->where('auditable_id', $user->id)
            ->firstOrFail();

        $this->assertSame('users', $entry->module);

        $recorded = array_merge($entry->old_values ?? [], $entry->new_values ?? []);
        foreach (['password', 'remember_token', 'two_factor_recovery_codes', 'two_factor_secret'] as $secret) {
            $this->assertArrayNotHasKey($secret, $recorded, "{$secret} must never be audited.");
        }
    }

    /** Work done outside a request is attributed to its process, not a bare "Sistema". */
    public function test_non_http_actor_is_named(): void
    {
        AuditContext::setActor('Sincronizacion de relojes', AuditContext::CONTEXT_SYNC);

        $employee = Employee::factory()->create();

        $entry = AuditLog::where('auditable_type', Employee::class)
            ->where('auditable_id', $employee->id)
            ->firstOrFail();

        $this->assertNull($entry->user_id);
        $this->assertSame('Sincronizacion de relojes', $entry->actor_label);
        $this->assertSame(AuditContext::CONTEXT_SYNC, $entry->context);
        $this->assertSame('Sincronizacion de relojes', $entry->context_label);
    }

    /** Legacy rows with no description still read as a sentence. */
    public function test_summary_falls_back_to_a_generated_sentence(): void
    {
        $this->actingAsAdmin();

        $employee = Employee::factory()->create(['full_name' => 'Juan Perez']);

        $entry = AuditLog::where('auditable_type', Employee::class)
            ->where('action', AuditLog::ACTION_CREATE)
            ->firstOrFail();

        $this->assertNull($entry->description);
        $this->assertStringStartsWith('Creo', $entry->summary);
        $this->assertStringContainsString('Juan Perez', $entry->summary);
        $this->assertSame('Empleado', $entry->entity_label);
    }

    /** Compensation type changes land in their own filterable module. */
    public function test_compensation_type_changes_are_audited(): void
    {
        $this->actingAsAdmin();

        $type = CompensationType::factory()->create();

        $entry = AuditLog::where('auditable_type', CompensationType::class)
            ->where('auditable_id', $type->id)
            ->firstOrFail();

        $this->assertSame('compensation_types', $entry->module);
        $this->assertNull($entry->employee_id);
    }
}
