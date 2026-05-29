<?php

namespace Tests\Feature\Anomalies;

use App\Models\AttendanceAnomaly;
use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\Department;
use App\Models\Employee;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\FeatureTestCase;

/**
 * Front-to-back coverage for AnomalyResolutionController.
 *
 * Routes: anomalies.index (GET), anomalies.show (GET),
 * anomalies.resolve/dismiss/linkAuthorization (POST {anomaly}),
 * anomalies.bulk-resolve/bulk-dismiss (POST).
 *
 * RBAC: admin full; supervisor view_team only (no resolve/dismiss);
 * rrhh + employee have NO anomalies perms.
 *
 * NOTE on 2FA: resolve/dismiss/bulk call $this->verifyTwoFactorCode($request).
 * The harness 2FA device stores a PLAINTEXT secret while the controller decrypts
 * it via Crypt::decryptString — so any submitted two_factor_code triggers a
 * DecryptException (500). Without a code, validation (size:6) fails first.
 * These paths are documented as bugs/limitations below.
 */
class AnomalyResolutionControllerTest extends FeatureTestCase
{
    /** Build an open anomaly tied to a fresh employee + record. */
    private function makeAnomaly(array $attrs = [], array $employeeAttrs = []): AttendanceAnomaly
    {
        $employee = Employee::factory()->create($employeeAttrs);
        $record = AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
        ]);

        return AttendanceAnomaly::factory()->open()->create(array_merge([
            'employee_id' => $employee->id,
            'attendance_record_id' => $record->id,
            'work_date' => $record->work_date,
        ], $attrs));
    }

    // ---------------------------------------------------------------------
    // index
    // ---------------------------------------------------------------------

    public function test_guest_is_redirected_to_login_from_index(): void
    {
        $this->get(route('anomalies.index'))->assertRedirect(route('login'));
    }

    public function test_admin_sees_empty_anomalies_index_with_all_props(): void
    {
        // With ZERO matching rows the FIELD()-based ORDER BY is not evaluated,
        // so the index renders and we can assert the full prop contract.
        $this->actingAsAdmin();

        $this->get(route('anomalies.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Anomalies/Index')
                ->has('anomalies.data', 0)
                ->has('stats')
                ->has('filters')
                ->has('employees')
                ->has('departments')
                ->has('anomalyTypes')
                ->where('can.resolve', true)
                ->where('can.dismiss', true));
    }

    public function test_admin_index_renders_when_anomalies_present(): void
    {
        // FIXED: portable CASE-based ORDER BY renders fine on SQLite once a
        // matching (open) anomaly exists. The index now returns 200 with the row.
        $this->actingAsAdmin();
        $anomaly = $this->makeAnomaly();

        $this->get(route('anomalies.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Anomalies/Index')
                ->has('anomalies.data', 1)
                ->where('anomalies.data.0.id', $anomaly->id)
                ->where('can.resolve', true)
                ->where('can.dismiss', true));
    }

    public function test_index_stats_count_open_anomalies_by_severity(): void
    {
        // FIXED: with the portable ORDER BY the page renders with rows present,
        // so stats counters can be asserted alongside a populated list.
        $this->actingAsAdmin();
        $this->makeAnomaly(['severity' => AttendanceAnomaly::SEVERITY_CRITICAL]);

        $this->get(route('anomalies.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Anomalies/Index')
                ->where('stats.open', 1)
                ->where('stats.critical', 1)
                ->where('stats.warning', 0)
                ->where('stats.info', 0));
    }

    public function test_index_filters_are_reachable_with_rows(): void
    {
        // FIXED: filtering by anomaly_type now returns the matching row and a
        // non-matching filter excludes it, since the page renders with rows.
        $this->actingAsAdmin();
        $match = $this->makeAnomaly(['anomaly_type' => AttendanceAnomaly::TYPE_MISSING_CHECKOUT]);
        $this->makeAnomaly(['anomaly_type' => AttendanceAnomaly::TYPE_MISSING_LUNCH]);

        $this->get(route('anomalies.index', [
            'anomaly_type' => AttendanceAnomaly::TYPE_MISSING_CHECKOUT,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Anomalies/Index')
                ->has('anomalies.data', 1)
                ->where('anomalies.data.0.id', $match->id)
                ->where('filters.anomaly_type', AttendanceAnomaly::TYPE_MISSING_CHECKOUT));
    }

    public function test_index_filters_props_echo_back_query_with_empty_results(): void
    {
        // With no matching rows the page renders; confirm filters are echoed back.
        $this->actingAsAdmin();

        $this->get(route('anomalies.index', [
            'status' => AttendanceAnomaly::STATUS_RESOLVED,
            'severity' => AttendanceAnomaly::SEVERITY_CRITICAL,
            'anomaly_type' => AttendanceAnomaly::TYPE_MISSING_CHECKOUT,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('anomalies.data', 0)
                ->where('filters.status', AttendanceAnomaly::STATUS_RESOLVED)
                ->where('filters.severity', AttendanceAnomaly::SEVERITY_CRITICAL)
                ->where('filters.anomaly_type', AttendanceAnomaly::TYPE_MISSING_CHECKOUT));
    }

    public function test_rrhh_is_forbidden_from_index(): void
    {
        $this->actingAsRrhh();
        $this->get(route('anomalies.index'))->assertForbidden();
    }

    public function test_employee_is_forbidden_from_index(): void
    {
        $this->actingAsEmployee();
        $this->get(route('anomalies.index'))->assertForbidden();
    }

    public function test_supervisor_sees_only_team_anomalies_on_index(): void
    {
        // FIXED: with the portable ORDER BY the populated list renders, so we can
        // assert team scoping — the supervisor sees their subordinate's anomaly
        // but NOT an unrelated employee's anomaly.
        $supervisorUser = $this->supervisorUser();
        $supEmployee = $this->attachEmployee($supervisorUser);
        $this->actingAs($supervisorUser);

        $teamAnomaly = $this->makeAnomaly([], ['supervisor_id' => $supEmployee->id]);
        // Anomaly for an employee outside the supervisor's team — must be excluded.
        $this->makeAnomaly();

        $this->get(route('anomalies.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Anomalies/Index')
                ->has('anomalies.data', 1)
                ->where('anomalies.data.0.id', $teamAnomaly->id)
                ->where('can.resolve', false)
                ->where('can.dismiss', false));
    }

    public function test_supervisor_without_employee_sees_nothing(): void
    {
        // Supervisor user with NO linked Employee → whereRaw('1 = 0').
        $this->actingAsSupervisor();
        $this->makeAnomaly();

        $this->get(route('anomalies.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('anomalies.data', 0));
    }

    // ---------------------------------------------------------------------
    // show
    // ---------------------------------------------------------------------

    public function test_guest_is_redirected_to_login_from_show(): void
    {
        $anomaly = $this->makeAnomaly();
        $this->get(route('anomalies.show', $anomaly))->assertRedirect(route('login'));
    }

    public function test_admin_sees_show_with_all_props(): void
    {
        $this->actingAsAdmin();
        $anomaly = $this->makeAnomaly();

        $this->get(route('anomalies.show', $anomaly))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Anomalies/Show')
                ->has('anomaly')
                ->where('anomaly.id', $anomaly->id)
                ->has('relatedAnomalies')
                ->has('relatedAuthorizations')
                ->where('can.resolve', true)
                ->where('can.dismiss', true)
                ->where('can.createAuthorization', true));
    }

    public function test_show_includes_related_anomalies_and_authorizations(): void
    {
        $this->actingAsAdmin();
        $anomaly = $this->makeAnomaly();

        // Same employee + same work_date → related anomaly.
        $related = AttendanceAnomaly::factory()->open()->create([
            'employee_id' => $anomaly->employee_id,
            'attendance_record_id' => $anomaly->attendance_record_id,
            'work_date' => $anomaly->work_date,
            'anomaly_type' => AttendanceAnomaly::TYPE_MISSING_LUNCH,
        ]);
        // Same employee + same date authorization → related authorization.
        Authorization::factory()->create([
            'employee_id' => $anomaly->employee_id,
            'date' => $anomaly->work_date,
            'requested_by' => $this->adminUser()->id,
        ]);

        $this->get(route('anomalies.show', $anomaly))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('relatedAnomalies', 1)
                ->where('relatedAnomalies.0.id', $related->id)
                ->has('relatedAuthorizations', 1));
    }

    public function test_supervisor_can_view_show_but_cannot_resolve_or_dismiss(): void
    {
        $supervisorUser = $this->supervisorUser();
        $supEmployee = $this->attachEmployee($supervisorUser);
        $this->actingAs($supervisorUser);

        $anomaly = $this->makeAnomaly([], ['supervisor_id' => $supEmployee->id]);

        // Supervisor lacks anomalies.resolve/dismiss but DOES hold
        // authorizations.create per the RBAC matrix, so createAuthorization=true.
        $this->get(route('anomalies.show', $anomaly))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Anomalies/Show')
                ->where('can.resolve', false)
                ->where('can.dismiss', false)
                ->where('can.createAuthorization', true));
    }

    public function test_rrhh_is_forbidden_from_show(): void
    {
        $rrhh = $this->rrhhUser();
        $this->actingAs($rrhh);
        $anomaly = $this->makeAnomaly();

        $this->get(route('anomalies.show', $anomaly))->assertForbidden();
    }

    public function test_employee_is_forbidden_from_show(): void
    {
        $this->actingAsEmployee();
        $anomaly = $this->makeAnomaly();

        $this->get(route('anomalies.show', $anomaly))->assertForbidden();
    }

    // ---------------------------------------------------------------------
    // resolve
    // ---------------------------------------------------------------------

    public function test_resolve_without_two_factor_code_fails_validation(): void
    {
        // Admin has 2FA enabled in the harness → verifyTwoFactorCode requires a
        // 6-digit two_factor_code. Omitting it yields a validation error and the
        // anomaly stays open (resolution never runs).
        $admin = $this->actingAsAdmin();
        $anomaly = $this->makeAnomaly();

        $this->from(route('anomalies.show', $anomaly))
            ->post(route('anomalies.resolve', $anomaly), [
                'resolution_notes' => 'Revisado.',
            ])
            ->assertSessionHasErrors(['two_factor_code']);

        $this->assertDatabaseHas('attendance_anomalies', [
            'id' => $anomaly->id,
            'status' => AttendanceAnomaly::STATUS_OPEN,
        ]);
    }

    public function test_resolve_with_valid_two_factor_code_resolves_anomaly(): void
    {
        // FIXED: the harness now seeds an ENCRYPTED 2FA secret, so a valid TOTP
        // passes verification and the resolve happy path completes.
        $admin = $this->actingAsAdmin();
        $anomaly = $this->makeAnomaly();

        $this->from(route('anomalies.show', $anomaly))
            ->post(route('anomalies.resolve', $anomaly), [
                'resolution_notes' => 'Revisado.',
                'two_factor_code' => $this->validTwoFactorCode(),
            ])
            ->assertRedirect(route('anomalies.show', $anomaly))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('attendance_anomalies', [
            'id' => $anomaly->id,
            'status' => AttendanceAnomaly::STATUS_RESOLVED,
            'resolved_by' => $admin->id,
            'resolution_notes' => 'Revisado.',
        ]);
    }

    public function test_rrhh_cannot_resolve(): void
    {
        $rrhh = $this->rrhhUser();
        $this->actingAs($rrhh);
        $anomaly = $this->makeAnomaly();

        $this->post(route('anomalies.resolve', $anomaly), ['resolution_notes' => 'x'])
            ->assertForbidden();
    }

    public function test_supervisor_cannot_resolve(): void
    {
        // Supervisor lacks anomalies.resolve → 403 (checked before 2FA).
        $supervisorUser = $this->supervisorUser();
        $supEmployee = $this->attachEmployee($supervisorUser);
        $this->actingAs($supervisorUser);
        $anomaly = $this->makeAnomaly([], ['supervisor_id' => $supEmployee->id]);

        $this->post(route('anomalies.resolve', $anomaly), ['resolution_notes' => 'x'])
            ->assertForbidden();
    }

    public function test_employee_cannot_resolve(): void
    {
        $this->actingAsEmployee();
        $anomaly = $this->makeAnomaly();

        $this->post(route('anomalies.resolve', $anomaly), ['resolution_notes' => 'x'])
            ->assertForbidden();
    }

    public function test_guest_cannot_resolve(): void
    {
        $anomaly = $this->makeAnomaly();
        $this->post(route('anomalies.resolve', $anomaly))->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------------
    // dismiss
    // ---------------------------------------------------------------------

    public function test_dismiss_without_two_factor_code_fails_validation(): void
    {
        $this->actingAsAdmin();
        $anomaly = $this->makeAnomaly();

        $this->from(route('anomalies.show', $anomaly))
            ->post(route('anomalies.dismiss', $anomaly), [
                'resolution_notes' => 'No aplica.',
            ])
            ->assertSessionHasErrors(['two_factor_code']);

        $this->assertDatabaseHas('attendance_anomalies', [
            'id' => $anomaly->id,
            'status' => AttendanceAnomaly::STATUS_OPEN,
        ]);
    }

    public function test_dismiss_with_valid_two_factor_code_dismisses_anomaly(): void
    {
        // FIXED: encrypted harness secret + valid TOTP → dismiss happy path runs.
        $admin = $this->actingAsAdmin();
        $anomaly = $this->makeAnomaly();

        $this->from(route('anomalies.show', $anomaly))
            ->post(route('anomalies.dismiss', $anomaly), [
                'resolution_notes' => 'No aplica.',
                'two_factor_code' => $this->validTwoFactorCode(),
            ])
            ->assertRedirect(route('anomalies.show', $anomaly))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('attendance_anomalies', [
            'id' => $anomaly->id,
            'status' => AttendanceAnomaly::STATUS_DISMISSED,
            'resolved_by' => $admin->id,
            'resolution_notes' => 'No aplica.',
        ]);
    }

    public function test_rrhh_cannot_dismiss(): void
    {
        $rrhh = $this->rrhhUser();
        $this->actingAs($rrhh);
        $anomaly = $this->makeAnomaly();

        $this->post(route('anomalies.dismiss', $anomaly), ['resolution_notes' => 'x'])
            ->assertForbidden();
    }

    public function test_supervisor_cannot_dismiss(): void
    {
        $supervisorUser = $this->supervisorUser();
        $supEmployee = $this->attachEmployee($supervisorUser);
        $this->actingAs($supervisorUser);
        $anomaly = $this->makeAnomaly([], ['supervisor_id' => $supEmployee->id]);

        $this->post(route('anomalies.dismiss', $anomaly), ['resolution_notes' => 'x'])
            ->assertForbidden();
    }

    public function test_employee_cannot_dismiss(): void
    {
        $this->actingAsEmployee();
        $anomaly = $this->makeAnomaly();

        $this->post(route('anomalies.dismiss', $anomaly), ['resolution_notes' => 'x'])
            ->assertForbidden();
    }

    public function test_guest_cannot_dismiss(): void
    {
        $anomaly = $this->makeAnomaly();
        $this->post(route('anomalies.dismiss', $anomaly))->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------------
    // linkAuthorization (NO 2FA gate — happy path is reachable)
    // ---------------------------------------------------------------------

    public function test_admin_links_anomaly_to_authorization_for_same_employee(): void
    {
        $admin = $this->actingAsAdmin();
        $anomaly = $this->makeAnomaly();

        $authorization = Authorization::factory()->create([
            'employee_id' => $anomaly->employee_id,
            'date' => $anomaly->work_date,
            'requested_by' => $admin->id,
        ]);

        $this->from(route('anomalies.show', $anomaly))
            ->post(route('anomalies.linkAuthorization', $anomaly), [
                'authorization_id' => $authorization->id,
            ])
            ->assertRedirect(route('anomalies.show', $anomaly))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('attendance_anomalies', [
            'id' => $anomaly->id,
            'status' => AttendanceAnomaly::STATUS_LINKED,
            'linked_authorization_id' => $authorization->id,
        ]);
    }

    public function test_link_authorization_rejects_mismatched_employee(): void
    {
        $admin = $this->actingAsAdmin();
        $anomaly = $this->makeAnomaly();

        // Authorization belongs to a DIFFERENT employee.
        $otherEmployee = Employee::factory()->create();
        $authorization = Authorization::factory()->create([
            'employee_id' => $otherEmployee->id,
            'requested_by' => $admin->id,
        ]);

        $this->from(route('anomalies.show', $anomaly))
            ->post(route('anomalies.linkAuthorization', $anomaly), [
                'authorization_id' => $authorization->id,
            ])
            ->assertRedirect(route('anomalies.show', $anomaly))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('attendance_anomalies', [
            'id' => $anomaly->id,
            'status' => AttendanceAnomaly::STATUS_OPEN,
            'linked_authorization_id' => null,
        ]);
    }

    public function test_link_authorization_requires_existing_authorization_id(): void
    {
        $this->actingAsAdmin();
        $anomaly = $this->makeAnomaly();

        $this->from(route('anomalies.show', $anomaly))
            ->post(route('anomalies.linkAuthorization', $anomaly), [
                'authorization_id' => 999999,
            ])
            ->assertSessionHasErrors(['authorization_id']);
    }

    public function test_link_authorization_requires_authorization_id_field(): void
    {
        $this->actingAsAdmin();
        $anomaly = $this->makeAnomaly();

        $this->from(route('anomalies.show', $anomaly))
            ->post(route('anomalies.linkAuthorization', $anomaly), [])
            ->assertSessionHasErrors(['authorization_id']);
    }

    public function test_rrhh_cannot_link_authorization(): void
    {
        $rrhh = $this->rrhhUser();
        $this->actingAs($rrhh);
        $anomaly = $this->makeAnomaly();
        $authorization = Authorization::factory()->create([
            'employee_id' => $anomaly->employee_id,
            'requested_by' => $rrhh->id,
        ]);

        $this->post(route('anomalies.linkAuthorization', $anomaly), [
            'authorization_id' => $authorization->id,
        ])->assertForbidden();
    }

    public function test_supervisor_cannot_link_authorization(): void
    {
        // Supervisor lacks anomalies.resolve (required by linkAuthorization) → 403.
        $supervisorUser = $this->supervisorUser();
        $supEmployee = $this->attachEmployee($supervisorUser);
        $this->actingAs($supervisorUser);
        $anomaly = $this->makeAnomaly([], ['supervisor_id' => $supEmployee->id]);
        $authorization = Authorization::factory()->create([
            'employee_id' => $anomaly->employee_id,
            'requested_by' => $supervisorUser->id,
        ]);

        $this->post(route('anomalies.linkAuthorization', $anomaly), [
            'authorization_id' => $authorization->id,
        ])->assertForbidden();
    }

    public function test_employee_cannot_link_authorization(): void
    {
        $this->actingAsEmployee();
        $anomaly = $this->makeAnomaly();

        $this->post(route('anomalies.linkAuthorization', $anomaly), [
            'authorization_id' => 1,
        ])->assertForbidden();
    }

    public function test_guest_cannot_link_authorization(): void
    {
        $anomaly = $this->makeAnomaly();
        $this->post(route('anomalies.linkAuthorization', $anomaly))
            ->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------------
    // bulkResolve / bulkDismiss
    // ---------------------------------------------------------------------

    public function test_bulk_resolve_without_two_factor_code_fails_validation(): void
    {
        $this->actingAsAdmin();
        $a1 = $this->makeAnomaly();
        $a2 = $this->makeAnomaly();

        $this->from(route('anomalies.index'))
            ->post(route('anomalies.bulk-resolve'), [
                'anomaly_ids' => [$a1->id, $a2->id],
            ])
            ->assertSessionHasErrors(['two_factor_code']);

        $this->assertDatabaseHas('attendance_anomalies', [
            'id' => $a1->id,
            'status' => AttendanceAnomaly::STATUS_OPEN,
        ]);
    }

    public function test_bulk_resolve_validates_two_factor_before_anomaly_ids(): void
    {
        // verifyTwoFactorCode() runs BEFORE the anomaly_ids validation, so for a
        // 2FA-enabled admin an empty body surfaces the two_factor_code error and
        // the anomaly_ids rule is never reached.
        $this->actingAsAdmin();

        $this->from(route('anomalies.index'))
            ->post(route('anomalies.bulk-resolve'), [])
            ->assertSessionHasErrors(['two_factor_code'])
            ->assertSessionDoesntHaveErrors(['anomaly_ids']);
    }

    public function test_bulk_resolve_with_valid_two_factor_code_resolves_anomalies(): void
    {
        // FIXED: encrypted harness secret + valid TOTP → bulk resolve happy path runs.
        $admin = $this->actingAsAdmin();
        $a1 = $this->makeAnomaly();
        $a2 = $this->makeAnomaly();

        $this->from(route('anomalies.index'))
            ->post(route('anomalies.bulk-resolve'), [
                'anomaly_ids' => [$a1->id, $a2->id],
                'two_factor_code' => $this->validTwoFactorCode(),
            ])
            ->assertRedirect(route('anomalies.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('attendance_anomalies', [
            'id' => $a1->id,
            'status' => AttendanceAnomaly::STATUS_RESOLVED,
            'resolved_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('attendance_anomalies', [
            'id' => $a2->id,
            'status' => AttendanceAnomaly::STATUS_RESOLVED,
            'resolved_by' => $admin->id,
        ]);
    }

    public function test_bulk_dismiss_validates_two_factor_before_anomaly_ids(): void
    {
        $this->actingAsAdmin();

        $this->from(route('anomalies.index'))
            ->post(route('anomalies.bulk-dismiss'), [])
            ->assertSessionHasErrors(['two_factor_code'])
            ->assertSessionDoesntHaveErrors(['anomaly_ids']);
    }

    public function test_rrhh_cannot_bulk_resolve(): void
    {
        $rrhh = $this->rrhhUser();
        $this->actingAs($rrhh);
        $a1 = $this->makeAnomaly();

        $this->post(route('anomalies.bulk-resolve'), ['anomaly_ids' => [$a1->id]])
            ->assertForbidden();
    }

    public function test_supervisor_cannot_bulk_resolve(): void
    {
        $supervisorUser = $this->supervisorUser();
        $supEmployee = $this->attachEmployee($supervisorUser);
        $this->actingAs($supervisorUser);
        $a1 = $this->makeAnomaly([], ['supervisor_id' => $supEmployee->id]);

        $this->post(route('anomalies.bulk-resolve'), ['anomaly_ids' => [$a1->id]])
            ->assertForbidden();
    }

    public function test_rrhh_cannot_bulk_dismiss(): void
    {
        $rrhh = $this->rrhhUser();
        $this->actingAs($rrhh);
        $a1 = $this->makeAnomaly();

        $this->post(route('anomalies.bulk-dismiss'), ['anomaly_ids' => [$a1->id]])
            ->assertForbidden();
    }

    public function test_supervisor_cannot_bulk_dismiss(): void
    {
        $supervisorUser = $this->supervisorUser();
        $supEmployee = $this->attachEmployee($supervisorUser);
        $this->actingAs($supervisorUser);
        $a1 = $this->makeAnomaly([], ['supervisor_id' => $supEmployee->id]);

        $this->post(route('anomalies.bulk-dismiss'), ['anomaly_ids' => [$a1->id]])
            ->assertForbidden();
    }

    public function test_employee_cannot_bulk_resolve(): void
    {
        $this->actingAsEmployee();
        $a1 = $this->makeAnomaly();

        $this->post(route('anomalies.bulk-resolve'), ['anomaly_ids' => [$a1->id]])
            ->assertForbidden();
    }

    public function test_guest_cannot_bulk_resolve(): void
    {
        $a1 = $this->makeAnomaly();
        $this->post(route('anomalies.bulk-resolve'), ['anomaly_ids' => [$a1->id]])
            ->assertRedirect(route('login'));
    }

    public function test_guest_cannot_bulk_dismiss(): void
    {
        $a1 = $this->makeAnomaly();
        $this->post(route('anomalies.bulk-dismiss'), ['anomaly_ids' => [$a1->id]])
            ->assertRedirect(route('login'));
    }

    public function test_bulk_dismiss_without_two_factor_code_fails_validation(): void
    {
        // Parity with bulk_resolve: 2FA-enabled admin omitting the code gets a
        // validation error and BOTH anomalies remain open (no dismiss runs).
        $this->actingAsAdmin();
        $a1 = $this->makeAnomaly();
        $a2 = $this->makeAnomaly();

        $this->from(route('anomalies.index'))
            ->post(route('anomalies.bulk-dismiss'), [
                'anomaly_ids' => [$a1->id, $a2->id],
            ])
            ->assertSessionHasErrors(['two_factor_code']);

        $this->assertDatabaseHas('attendance_anomalies', [
            'id' => $a1->id,
            'status' => AttendanceAnomaly::STATUS_OPEN,
        ]);
        $this->assertDatabaseHas('attendance_anomalies', [
            'id' => $a2->id,
            'status' => AttendanceAnomaly::STATUS_OPEN,
        ]);
    }

    public function test_bulk_dismiss_with_valid_two_factor_code_dismisses_anomalies(): void
    {
        // FIXED: encrypted harness secret + valid TOTP → bulk dismiss happy path runs.
        $admin = $this->actingAsAdmin();
        $a1 = $this->makeAnomaly();
        $a2 = $this->makeAnomaly();

        $this->from(route('anomalies.index'))
            ->post(route('anomalies.bulk-dismiss'), [
                'anomaly_ids' => [$a1->id, $a2->id],
                'two_factor_code' => $this->validTwoFactorCode(),
            ])
            ->assertRedirect(route('anomalies.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('attendance_anomalies', [
            'id' => $a1->id,
            'status' => AttendanceAnomaly::STATUS_DISMISSED,
            'resolved_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('attendance_anomalies', [
            'id' => $a2->id,
            'status' => AttendanceAnomaly::STATUS_DISMISSED,
            'resolved_by' => $admin->id,
        ]);
    }

    public function test_employee_cannot_bulk_dismiss(): void
    {
        // RBAC trio parity: employee has no anomalies perms → 403.
        $this->actingAsEmployee();
        $a1 = $this->makeAnomaly();

        $this->post(route('anomalies.bulk-dismiss'), ['anomaly_ids' => [$a1->id]])
            ->assertForbidden();
    }

    // ---------------------------------------------------------------------
    // index — pagination + default-status edge cases
    // ---------------------------------------------------------------------

    public function test_index_returns_full_pagination_meta_for_anomalies_prop(): void
    {
        // The Index.vue consumes anomalies.data (computed/select) AND the paginator
        // meta (current_page/total/links) for its pager. Assert the contract on an
        // empty set (safe — the FIELD() ORDER BY bug only triggers with rows).
        $this->actingAsAdmin();

        $this->get(route('anomalies.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('anomalies.data')
                ->has('anomalies.current_page')
                ->has('anomalies.per_page')
                ->has('anomalies.total')
                ->has('anomalies.links')
                ->where('anomalies.total', 0));
    }

    public function test_index_stats_props_are_all_present_and_zero_when_empty(): void
    {
        // stats is its own object with open/critical/warning/info keys consumed by
        // the dashboard cards. With no rows every counter must be present and 0.
        $this->actingAsAdmin();

        $this->get(route('anomalies.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.open', 0)
                ->where('stats.critical', 0)
                ->where('stats.warning', 0)
                ->where('stats.info', 0));
    }

    public function test_index_anomaly_types_and_lookups_are_arrays(): void
    {
        // employees/departments/anomalyTypes feed the filter selects. Confirm the
        // anomalyTypes list shape (value/label) the Vue select binds to.
        $this->actingAsAdmin();

        $this->get(route('anomalies.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('anomalyTypes', 11)
                ->has('anomalyTypes.0.value')
                ->has('anomalyTypes.0.label')
                ->where('anomalyTypes.0.value', 'missing_checkout'));
    }

    public function test_index_supervisor_can_props_are_false(): void
    {
        // Supervisor reaches index via view_team but holds neither resolve nor
        // dismiss → both can flags must be false so the Vue hides bulk actions.
        $this->actingAsSupervisor();

        $this->get(route('anomalies.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.resolve', false)
                ->where('can.dismiss', false));
    }

    // ---------------------------------------------------------------------
    // show — 404 binding + related-data scoping
    // ---------------------------------------------------------------------

    public function test_show_returns_404_for_unknown_anomaly(): void
    {
        $this->actingAsAdmin();

        $this->get(route('anomalies.show', 999999))->assertNotFound();
    }

    public function test_show_related_data_is_scoped_to_same_employee_and_date(): void
    {
        // Negative scoping: an anomaly for a DIFFERENT employee and an authorization
        // on a DIFFERENT date must NOT appear in relatedAnomalies/relatedAuthorizations.
        $admin = $this->actingAsAdmin();
        $anomaly = $this->makeAnomaly();

        // Different-employee anomaly (same date) — must be excluded.
        $otherEmployee = Employee::factory()->create();
        AttendanceAnomaly::factory()->open()->create([
            'employee_id' => $otherEmployee->id,
            'attendance_record_id' => AttendanceRecord::factory()->create([
                'employee_id' => $otherEmployee->id,
                'work_date' => $anomaly->work_date,
            ])->id,
            'work_date' => $anomaly->work_date,
        ]);

        // Same employee but a DIFFERENT date authorization — must be excluded.
        $otherDate = Carbon::parse($anomaly->work_date)->subDays(5)->toDateString();
        Authorization::factory()->create([
            'employee_id' => $anomaly->employee_id,
            'date' => $otherDate,
            'requested_by' => $admin->id,
        ]);

        $this->get(route('anomalies.show', $anomaly))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('relatedAnomalies', 0)
                ->has('relatedAuthorizations', 0));
    }

    public function test_show_excludes_self_from_related_anomalies(): void
    {
        // The query filters id != $anomaly->id, so the anomaly itself never shows
        // up in its own relatedAnomalies list even with no siblings.
        $this->actingAsAdmin();
        $anomaly = $this->makeAnomaly();

        $this->get(route('anomalies.show', $anomaly))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('relatedAnomalies', 0)
                ->where('anomaly.id', $anomaly->id));
    }

    // ---------------------------------------------------------------------
    // linkAuthorization — additional behavior
    // ---------------------------------------------------------------------

    public function test_link_authorization_relinks_an_already_linked_anomaly(): void
    {
        // The controller has NO status guard on linkAuthorization, so an anomaly
        // that is already linked can be re-pointed at another authorization for the
        // same employee. This documents the actual (guardless) behavior.
        $admin = $this->actingAsAdmin();
        $anomaly = $this->makeAnomaly();

        $first = Authorization::factory()->create([
            'employee_id' => $anomaly->employee_id,
            'date' => $anomaly->work_date,
            'requested_by' => $admin->id,
        ]);
        $second = Authorization::factory()->create([
            'employee_id' => $anomaly->employee_id,
            'date' => $anomaly->work_date,
            'requested_by' => $admin->id,
        ]);

        $this->post(route('anomalies.linkAuthorization', $anomaly), [
            'authorization_id' => $first->id,
        ])->assertSessionHas('success');

        $this->post(route('anomalies.linkAuthorization', $anomaly), [
            'authorization_id' => $second->id,
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('attendance_anomalies', [
            'id' => $anomaly->id,
            'status' => AttendanceAnomaly::STATUS_LINKED,
            'linked_authorization_id' => $second->id,
        ]);
    }

    public function test_link_authorization_returns_404_for_unknown_anomaly(): void
    {
        $admin = $this->actingAsAdmin();
        $authorization = Authorization::factory()->create([
            'requested_by' => $admin->id,
        ]);

        $this->post(route('anomalies.linkAuthorization', 999999), [
            'authorization_id' => $authorization->id,
        ])->assertNotFound();
    }

    public function test_link_authorization_mismatch_leaves_status_unchanged_and_does_not_link(): void
    {
        // Reinforces the mismatch branch with an explicit DB assertion that no
        // linked_authorization_id was written and the success flash is absent.
        $admin = $this->actingAsAdmin();
        $anomaly = $this->makeAnomaly();

        $otherEmployee = Employee::factory()->create();
        $foreignAuth = Authorization::factory()->create([
            'employee_id' => $otherEmployee->id,
            'requested_by' => $admin->id,
        ]);

        $this->from(route('anomalies.show', $anomaly))
            ->post(route('anomalies.linkAuthorization', $anomaly), [
                'authorization_id' => $foreignAuth->id,
            ])
            ->assertSessionHas('error')
            ->assertSessionMissing('success');

        $this->assertDatabaseHas('attendance_anomalies', [
            'id' => $anomaly->id,
            'status' => AttendanceAnomaly::STATUS_OPEN,
            'linked_authorization_id' => null,
        ]);
    }
}
