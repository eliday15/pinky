<?php

namespace Tests\Feature\Anomalies;

use App\Models\AttendanceAnomaly;
use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\Employee;
use App\Models\Incident;
use App\Models\IncidentType;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\FeatureTestCase;

/**
 * Coverage for the professional resolution flow: resolution_method on
 * resolve/dismiss, the link-incident endpoint, the linkables JSON endpoint,
 * and the new show() props that feed the resolution modal.
 */
class AnomalyResolutionFlowTest extends FeatureTestCase
{
    /** Build an open anomaly tied to a fresh employee + record. */
    private function makeAnomaly(array $attrs = []): AttendanceAnomaly
    {
        $employee = Employee::factory()->create();
        $record = AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
        ]);

        return AttendanceAnomaly::factory()->open()->create(array_merge([
            'employee_id' => $employee->id,
            'attendance_record_id' => $record->id,
            'work_date' => $record->work_date,
        ], $attrs));
    }

    /** PSA/PEN are seeded by migration — reuse them instead of recreating. */
    private function incidentType(string $code): IncidentType
    {
        return IncidentType::firstWhere('code', $code)
            ?? IncidentType::factory()->permission()->create(['code' => $code]);
    }

    /** Approved permission incident covering the anomaly's work date. */
    private function makeCoveringIncident(AttendanceAnomaly $anomaly, string $code = 'PSA'): Incident
    {
        return Incident::factory()->approved()->create([
            'employee_id' => $anomaly->employee_id,
            'incident_type_id' => $this->incidentType($code)->id,
            'start_date' => $anomaly->work_date->toDateString(),
            'end_date' => $anomaly->work_date->toDateString(),
        ]);
    }

    // ---------------------------------------------------------------------
    // resolve: resolution_method + required notes
    // ---------------------------------------------------------------------

    public function test_resolve_requires_resolution_method(): void
    {
        $this->actingAsAdmin();
        $anomaly = $this->makeAnomaly();

        $this->post(route('anomalies.resolve', $anomaly), [
            'resolution_notes' => 'Notas validas.',
            'two_factor_code' => $this->validTwoFactorCode(),
        ])->assertSessionHasErrors(['resolution_method']);

        $this->assertDatabaseHas('attendance_anomalies', [
            'id' => $anomaly->id,
            'status' => AttendanceAnomaly::STATUS_OPEN,
        ]);
    }

    public function test_resolve_rejects_method_outside_manual_set(): void
    {
        // record_corrected / linked_* are system-assigned, never user-submitted.
        $this->actingAsAdmin();
        $anomaly = $this->makeAnomaly();

        $this->post(route('anomalies.resolve', $anomaly), [
            'resolution_method' => AttendanceAnomaly::METHOD_RECORD_CORRECTED,
            'resolution_notes' => 'Notas validas.',
            'two_factor_code' => $this->validTwoFactorCode(),
        ])->assertSessionHasErrors(['resolution_method']);
    }

    public function test_resolve_requires_notes_of_at_least_five_chars(): void
    {
        $this->actingAsAdmin();
        $anomaly = $this->makeAnomaly();

        $this->post(route('anomalies.resolve', $anomaly), [
            'resolution_method' => AttendanceAnomaly::METHOD_JUSTIFIED,
            'resolution_notes' => 'abc',
            'two_factor_code' => $this->validTwoFactorCode(),
        ])->assertSessionHasErrors(['resolution_notes']);
    }

    public function test_resolve_persists_false_positive_method(): void
    {
        $admin = $this->actingAsAdmin();
        $anomaly = $this->makeAnomaly();

        $this->post(route('anomalies.resolve', $anomaly), [
            'resolution_method' => AttendanceAnomaly::METHOD_FALSE_POSITIVE,
            'resolution_notes' => 'Lectura erronea del dispositivo.',
            'two_factor_code' => $this->validTwoFactorCode(),
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('attendance_anomalies', [
            'id' => $anomaly->id,
            'status' => AttendanceAnomaly::STATUS_RESOLVED,
            'resolved_by' => $admin->id,
            'resolution_method' => AttendanceAnomaly::METHOD_FALSE_POSITIVE,
        ]);
    }

    // ---------------------------------------------------------------------
    // dismiss: required notes + false_positive method
    // ---------------------------------------------------------------------

    public function test_dismiss_requires_notes(): void
    {
        $this->actingAsAdmin();
        $anomaly = $this->makeAnomaly();

        $this->post(route('anomalies.dismiss', $anomaly), [
            'two_factor_code' => $this->validTwoFactorCode(),
        ])->assertSessionHasErrors(['resolution_notes']);
    }

    public function test_dismiss_persists_false_positive_method(): void
    {
        $this->actingAsAdmin();
        $anomaly = $this->makeAnomaly();

        $this->post(route('anomalies.dismiss', $anomaly), [
            'resolution_notes' => 'No aplica para este caso.',
            'two_factor_code' => $this->validTwoFactorCode(),
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('attendance_anomalies', [
            'id' => $anomaly->id,
            'status' => AttendanceAnomaly::STATUS_DISMISSED,
            'resolution_method' => AttendanceAnomaly::METHOD_FALSE_POSITIVE,
        ]);
    }

    // ---------------------------------------------------------------------
    // linkIncident (no 2FA gate, mirrors linkAuthorization)
    // ---------------------------------------------------------------------

    public function test_admin_links_anomaly_to_covering_approved_incident(): void
    {
        $admin = $this->actingAsAdmin();
        $anomaly = $this->makeAnomaly(['anomaly_type' => AttendanceAnomaly::TYPE_EARLY_DEPARTURE]);
        $incident = $this->makeCoveringIncident($anomaly);

        $this->from(route('anomalies.show', $anomaly))
            ->post(route('anomalies.linkIncident', $anomaly), [
                'incident_id' => $incident->id,
            ])
            ->assertRedirect(route('anomalies.show', $anomaly))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('attendance_anomalies', [
            'id' => $anomaly->id,
            'status' => AttendanceAnomaly::STATUS_RESOLVED,
            'linked_incident_id' => $incident->id,
            'resolution_method' => AttendanceAnomaly::METHOD_LINKED_INCIDENT,
            'resolved_by' => $admin->id,
        ]);

        // Record anomaly counters were refreshed.
        $this->assertDatabaseHas('attendance_records', [
            'id' => $anomaly->attendance_record_id,
            'has_anomalies' => false,
            'anomaly_count' => 0,
        ]);
    }

    public function test_link_incident_rejects_mismatched_employee(): void
    {
        $this->actingAsAdmin();
        $anomaly = $this->makeAnomaly();

        $type = $this->incidentType('PSA');
        $incident = Incident::factory()->approved()->create([
            'employee_id' => Employee::factory()->create()->id,
            'incident_type_id' => $type->id,
            'start_date' => $anomaly->work_date->toDateString(),
            'end_date' => $anomaly->work_date->toDateString(),
        ]);

        $this->from(route('anomalies.show', $anomaly))
            ->post(route('anomalies.linkIncident', $anomaly), ['incident_id' => $incident->id])
            ->assertSessionHas('error');

        $this->assertDatabaseHas('attendance_anomalies', [
            'id' => $anomaly->id,
            'status' => AttendanceAnomaly::STATUS_OPEN,
        ]);
    }

    public function test_link_incident_rejects_unapproved_incident(): void
    {
        $this->actingAsAdmin();
        $anomaly = $this->makeAnomaly();

        $type = $this->incidentType('PSA');
        $incident = Incident::factory()->create([ // status: pending
            'employee_id' => $anomaly->employee_id,
            'incident_type_id' => $type->id,
            'start_date' => $anomaly->work_date->toDateString(),
            'end_date' => $anomaly->work_date->toDateString(),
        ]);

        $this->post(route('anomalies.linkIncident', $anomaly), ['incident_id' => $incident->id])
            ->assertSessionHas('error');

        $this->assertDatabaseHas('attendance_anomalies', [
            'id' => $anomaly->id,
            'status' => AttendanceAnomaly::STATUS_OPEN,
        ]);
    }

    public function test_link_incident_rejects_wrong_code_for_anomaly_type(): void
    {
        // early_departure maps to PSA — a PEN permit must be rejected even if
        // it's approved, same employee and covers the date.
        $this->actingAsAdmin();
        $anomaly = $this->makeAnomaly(['anomaly_type' => AttendanceAnomaly::TYPE_EARLY_DEPARTURE]);
        $incident = $this->makeCoveringIncident($anomaly, 'PEN');

        $this->post(route('anomalies.linkIncident', $anomaly), ['incident_id' => $incident->id])
            ->assertSessionHas('error');

        $this->assertDatabaseHas('attendance_anomalies', [
            'id' => $anomaly->id,
            'status' => AttendanceAnomaly::STATUS_OPEN,
        ]);
    }

    public function test_link_incident_rejected_for_type_without_permit_mapping(): void
    {
        // schedule_deviation has no permiso mapping — linking is not allowed.
        $this->actingAsAdmin();
        $anomaly = $this->makeAnomaly(['anomaly_type' => AttendanceAnomaly::TYPE_SCHEDULE_DEVIATION]);
        $incident = $this->makeCoveringIncident($anomaly, 'PSA');

        $this->post(route('anomalies.linkIncident', $anomaly), ['incident_id' => $incident->id])
            ->assertSessionHas('error');

        $this->assertDatabaseHas('attendance_anomalies', [
            'id' => $anomaly->id,
            'status' => AttendanceAnomaly::STATUS_OPEN,
        ]);
    }

    public function test_linkables_returns_no_incidents_for_type_without_mapping(): void
    {
        $this->actingAsAdmin();
        $anomaly = $this->makeAnomaly(['anomaly_type' => AttendanceAnomaly::TYPE_SCHEDULE_DEVIATION]);
        $this->makeCoveringIncident($anomaly, 'PSA');

        $response = $this->getJson(route('anomalies.linkables', $anomaly))->assertOk();

        $this->assertSame([], $response->json('incidents'));
    }

    public function test_link_incident_rejects_incident_not_covering_date(): void
    {
        $this->actingAsAdmin();
        $anomaly = $this->makeAnomaly();

        $type = $this->incidentType('PSA');
        $incident = Incident::factory()->approved()->create([
            'employee_id' => $anomaly->employee_id,
            'incident_type_id' => $type->id,
            'start_date' => $anomaly->work_date->copy()->addDays(5)->toDateString(),
            'end_date' => $anomaly->work_date->copy()->addDays(6)->toDateString(),
        ]);

        $this->post(route('anomalies.linkIncident', $anomaly), ['incident_id' => $incident->id])
            ->assertSessionHas('error');

        $this->assertDatabaseHas('attendance_anomalies', [
            'id' => $anomaly->id,
            'status' => AttendanceAnomaly::STATUS_OPEN,
        ]);
    }

    public function test_link_incident_requires_existing_incident_id(): void
    {
        $this->actingAsAdmin();
        $anomaly = $this->makeAnomaly();

        $this->post(route('anomalies.linkIncident', $anomaly), [])
            ->assertSessionHasErrors(['incident_id']);

        $this->post(route('anomalies.linkIncident', $anomaly), ['incident_id' => 999999])
            ->assertSessionHasErrors(['incident_id']);
    }

    public function test_rrhh_cannot_link_incident(): void
    {
        $this->actingAsRrhh();
        $anomaly = $this->makeAnomaly();

        $this->post(route('anomalies.linkIncident', $anomaly), ['incident_id' => 1])
            ->assertForbidden();
    }

    public function test_supervisor_cannot_link_incident(): void
    {
        $this->actingAsSupervisor();
        $anomaly = $this->makeAnomaly();

        $this->post(route('anomalies.linkIncident', $anomaly), ['incident_id' => 1])
            ->assertForbidden();
    }

    public function test_employee_cannot_link_incident(): void
    {
        $this->actingAsEmployee();
        $anomaly = $this->makeAnomaly();

        $this->post(route('anomalies.linkIncident', $anomaly), ['incident_id' => 1])
            ->assertForbidden();
    }

    public function test_guest_cannot_link_incident(): void
    {
        $anomaly = $this->makeAnomaly();

        $this->post(route('anomalies.linkIncident', $anomaly), ['incident_id' => 1])
            ->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------------
    // linkables (JSON for the index modal)
    // ---------------------------------------------------------------------

    public function test_linkables_returns_matching_authorizations_and_incidents(): void
    {
        $admin = $this->actingAsAdmin();
        $anomaly = $this->makeAnomaly(['anomaly_type' => AttendanceAnomaly::TYPE_UNAUTHORIZED_VELADA]);

        // Matching: approved night_shift authorization on the same date.
        $matching = Authorization::factory()->nightShift()->approved()->create([
            'employee_id' => $anomaly->employee_id,
            'date' => $anomaly->work_date,
            'requested_by' => $admin->id,
        ]);
        // Non-matching: pending one (not approved) must be excluded.
        Authorization::factory()->nightShift()->create([
            'employee_id' => $anomaly->employee_id,
            'date' => $anomaly->work_date,
            'requested_by' => $admin->id,
        ]);

        $response = $this->getJson(route('anomalies.linkables', $anomaly))->assertOk();

        $this->assertSame([$matching->id], array_column($response->json('authorizations'), 'id'));
        $this->assertSame([], $response->json('incidents'));
    }

    public function test_linkables_returns_covering_psa_incident_for_early_departure(): void
    {
        $this->actingAsAdmin();
        $anomaly = $this->makeAnomaly(['anomaly_type' => AttendanceAnomaly::TYPE_EARLY_DEPARTURE]);
        $incident = $this->makeCoveringIncident($anomaly, 'PSA');

        // A PEN permission must NOT appear for an early_departure anomaly.
        $this->makeCoveringIncident($anomaly, 'PEN');

        $response = $this->getJson(route('anomalies.linkables', $anomaly))->assertOk();

        $this->assertSame([$incident->id], array_column($response->json('incidents'), 'id'));
    }

    public function test_linkables_forbidden_without_resolve_permission(): void
    {
        $this->actingAsRrhh();
        $anomaly = $this->makeAnomaly();

        $this->getJson(route('anomalies.linkables', $anomaly))->assertForbidden();
    }

    public function test_link_authorization_rejects_pending_authorization(): void
    {
        // A pending authorization does not justify the anomaly — must be rejected.
        $admin = $this->actingAsAdmin();
        $anomaly = $this->makeAnomaly(['anomaly_type' => AttendanceAnomaly::TYPE_UNAUTHORIZED_OVERTIME]);

        $pending = Authorization::factory()->overtime()->create([ // default: pending
            'employee_id' => $anomaly->employee_id,
            'date' => $anomaly->work_date,
            'requested_by' => $admin->id,
        ]);

        $this->post(route('anomalies.linkAuthorization', $anomaly), ['authorization_id' => $pending->id])
            ->assertSessionHas('error');

        $this->assertDatabaseHas('attendance_anomalies', [
            'id' => $anomaly->id,
            'status' => AttendanceAnomaly::STATUS_OPEN,
        ]);
    }

    // ---------------------------------------------------------------------
    // bulk: stamp resolution_method for badge/audit consistency
    // ---------------------------------------------------------------------

    public function test_bulk_resolve_stamps_justified_method(): void
    {
        $this->actingAsAdmin();
        $a1 = $this->makeAnomaly();
        $a2 = $this->makeAnomaly();

        $this->post(route('anomalies.bulk-resolve'), [
            'anomaly_ids' => [$a1->id, $a2->id],
            'two_factor_code' => $this->validTwoFactorCode(),
        ])->assertSessionHas('success');

        foreach ([$a1, $a2] as $a) {
            $this->assertDatabaseHas('attendance_anomalies', [
                'id' => $a->id,
                'status' => AttendanceAnomaly::STATUS_RESOLVED,
                'resolution_method' => AttendanceAnomaly::METHOD_JUSTIFIED,
            ]);
        }
    }

    public function test_bulk_dismiss_stamps_false_positive_method(): void
    {
        $this->actingAsAdmin();
        $anomaly = $this->makeAnomaly();

        $this->post(route('anomalies.bulk-dismiss'), [
            'anomaly_ids' => [$anomaly->id],
            'two_factor_code' => $this->validTwoFactorCode(),
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('attendance_anomalies', [
            'id' => $anomaly->id,
            'status' => AttendanceAnomaly::STATUS_DISMISSED,
            'resolution_method' => AttendanceAnomaly::METHOD_FALSE_POSITIVE,
        ]);
    }

    // ---------------------------------------------------------------------
    // authorization approval refreshes the record's anomaly counters
    // ---------------------------------------------------------------------

    public function test_approving_authorization_refreshes_record_anomaly_count(): void
    {
        $admin = $this->actingAsAdmin();

        $employee = Employee::factory()->create();
        $record = AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'has_anomalies' => true,
            'anomaly_count' => 1,
        ]);
        $anomaly = AttendanceAnomaly::factory()->open()->create([
            'employee_id' => $employee->id,
            'attendance_record_id' => $record->id,
            'work_date' => $record->work_date,
            'anomaly_type' => AttendanceAnomaly::TYPE_UNAUTHORIZED_OVERTIME,
        ]);

        $authorization = Authorization::factory()->overtime()->create([
            'employee_id' => $employee->id,
            'date' => $record->work_date,
            'requested_by' => $admin->id,
        ]);

        $this->post(route('authorizations.approve', $authorization), [
            'two_factor_code' => $this->validTwoFactorCode(),
        ])->assertSessionHas('success');

        // Anomaly linked, and the record's open-anomaly counters refreshed.
        $this->assertDatabaseHas('attendance_anomalies', [
            'id' => $anomaly->id,
            'status' => AttendanceAnomaly::STATUS_LINKED,
        ]);
        $this->assertDatabaseHas('attendance_records', [
            'id' => $record->id,
            'has_anomalies' => false,
            'anomaly_count' => 0,
        ]);
    }

    // ---------------------------------------------------------------------
    // show: new props for the resolution modal
    // ---------------------------------------------------------------------

    public function test_show_exposes_linkable_props_and_edit_attendance_flag(): void
    {
        $admin = $this->actingAsAdmin();
        $anomaly = $this->makeAnomaly(['anomaly_type' => AttendanceAnomaly::TYPE_UNAUTHORIZED_OVERTIME]);

        Authorization::factory()->overtime()->approved()->create([
            'employee_id' => $anomaly->employee_id,
            'date' => $anomaly->work_date,
            'requested_by' => $admin->id,
        ]);

        $this->get(route('anomalies.show', $anomaly))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Anomalies/Show')
                ->has('linkableAuthorizations', 1)
                ->has('linkableIncidents')
                ->where('can.editAttendance', true)
                ->where('can.createAuthorization', true));
    }
}
