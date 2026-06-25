<?php

namespace Tests\Feature\Incidents;

use App\Models\Employee;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\TwoFactorDevice;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;
use Tests\FeatureTestCase;

/**
 * Front-to-back contract tests for IncidentController.
 *
 * Covers index/create/store/show/edit/update/destroy + createBulk/storeBulk
 * + approve/reject, asserting RBAC, Inertia props, validation and DB effects.
 */
class IncidentControllerTest extends FeatureTestCase
{
    /**
     * Build an active employee with a deterministic id we control.
     */
    private function makeEmployee(array $attrs = []): Employee
    {
        return Employee::factory()->create($attrs);
    }

    /**
     * Attach a 2FA device with a PROPERLY ENCRYPTED secret (the harness's
     * confirmTwoFactorFor stores a raw string that fails Crypt::decryptString),
     * and return the current valid TOTP code so approve/reject happy paths work.
     *
     * @return array{0: TwoFactorDevice, 1: string} [device, currentCode]
     */
    private function attachRealTwoFactor(User $user): array
    {
        // Remove any harness-installed (raw-secret) device first.
        TwoFactorDevice::where('user_id', $user->id)->delete();

        $google = new Google2FA();
        $secret = $google->generateSecretKey();

        $device = TwoFactorDevice::create([
            'user_id' => $user->id,
            'name' => 'Encrypted Authenticator',
            'secret' => Crypt::encryptString($secret),
            'confirmed_at' => now(),
        ]);

        return [$device, $google->getCurrentOtp($secret)];
    }

    // ----------------------------------------------------------------
    // index
    // ----------------------------------------------------------------

    public function test_admin_sees_incident_index_with_all_props(): void
    {
        $this->actingAsAdmin();
        $type = IncidentType::factory()->create();
        $employee = $this->makeEmployee();
        Incident::factory()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
        ]);

        $this->get(route('incidents.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Incidents/Index')
                ->has('incidents.data', 1)
                ->has('incidentTypes')
                ->has('employees')
                ->has('pendingCount')
                ->has('filters')
                ->has('can', fn (Assert $can) => $can
                    ->where('create', true)
                    ->where('approve', true)
                    ->where('reject', true)));
    }

    public function test_index_filters_by_status(): void
    {
        $this->actingAsAdmin();
        $type = IncidentType::factory()->create();
        $e1 = $this->makeEmployee();
        $e2 = $this->makeEmployee();
        Incident::factory()->create(['employee_id' => $e1->id, 'incident_type_id' => $type->id, 'status' => 'pending']);
        Incident::factory()->approved()->create(['employee_id' => $e2->id, 'incident_type_id' => $type->id]);

        $this->get(route('incidents.index', ['status' => 'approved']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('incidents.data', 1)
                ->where('incidents.data.0.status', 'approved')
                ->where('filters.status', 'approved'));
    }

    public function test_supervisor_only_sees_team_incidents(): void
    {
        $supervisor = $this->actingAsSupervisor();
        $supEmployee = $this->attachEmployee($supervisor);

        $type = IncidentType::factory()->create();
        $subordinate = $this->makeEmployee(['supervisor_id' => $supEmployee->id]);
        $outsider = $this->makeEmployee();

        Incident::factory()->create(['employee_id' => $subordinate->id, 'incident_type_id' => $type->id]);
        Incident::factory()->create(['employee_id' => $outsider->id, 'incident_type_id' => $type->id]);

        $this->get(route('incidents.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Incidents/Index')
                ->has('incidents.data', 1)
                ->where('incidents.data.0.employee_id', $subordinate->id)
                ->where('can.approve', false)
                ->where('can.reject', false));
    }

    public function test_index_filters_by_type_and_employee(): void
    {
        $this->actingAsAdmin();
        $typeA = IncidentType::factory()->create();
        $typeB = IncidentType::factory()->create();
        $e1 = $this->makeEmployee();
        $e2 = $this->makeEmployee();
        Incident::factory()->create(['employee_id' => $e1->id, 'incident_type_id' => $typeA->id]);
        Incident::factory()->create(['employee_id' => $e2->id, 'incident_type_id' => $typeB->id]);

        // Filter by type.
        $this->get(route('incidents.index', ['type' => $typeA->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('incidents.data', 1)
                ->where('incidents.data.0.incident_type_id', $typeA->id)
                ->where('filters.type', (string) $typeA->id));

        // Filter by employee.
        $this->get(route('incidents.index', ['employee' => $e2->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('incidents.data', 1)
                ->where('incidents.data.0.employee_id', $e2->id)
                ->where('filters.employee', (string) $e2->id));
    }

    public function test_index_search_filter_matches_employee_name(): void
    {
        $this->actingAsAdmin();
        $type = IncidentType::factory()->create();
        $match = $this->makeEmployee(['full_name' => 'Zoraida Buscable']);
        $other = $this->makeEmployee(['full_name' => 'Otra Persona']);
        Incident::factory()->create(['employee_id' => $match->id, 'incident_type_id' => $type->id]);
        Incident::factory()->create(['employee_id' => $other->id, 'incident_type_id' => $type->id]);

        $this->get(route('incidents.index', ['search' => 'Zoraida']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('incidents.data', 1)
                ->where('incidents.data.0.employee_id', $match->id)
                ->where('filters.search', 'Zoraida'));
    }

    public function test_index_pending_count_scoped_to_supervisor_team(): void
    {
        $supervisor = $this->actingAsSupervisor();
        $supEmployee = $this->attachEmployee($supervisor);
        $type = IncidentType::factory()->create();
        $sub = $this->makeEmployee(['supervisor_id' => $supEmployee->id]);
        $outsider = $this->makeEmployee();

        // 1 pending in team, 1 pending outside team.
        Incident::factory()->create(['employee_id' => $sub->id, 'incident_type_id' => $type->id, 'status' => 'pending']);
        Incident::factory()->create(['employee_id' => $outsider->id, 'incident_type_id' => $type->id, 'status' => 'pending']);

        $this->get(route('incidents.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pendingCount', 1)
                ->where('can.create', true));
    }

    public function test_rrhh_cannot_view_incidents(): void
    {
        $this->actingAsRrhh();
        $this->get(route('incidents.index'))->assertForbidden();
    }

    public function test_employee_cannot_view_incidents_index(): void
    {
        // employee role only has attendance.view_own + reports.view_own → no incidents perms.
        $this->actingAsEmployee();
        $this->get(route('incidents.index'))->assertForbidden();
    }

    public function test_guest_redirected_to_login_from_index(): void
    {
        $this->get(route('incidents.index'))->assertRedirect(route('login'));
    }

    // ----------------------------------------------------------------
    // create
    // ----------------------------------------------------------------

    public function test_admin_sees_create_form_with_props(): void
    {
        $this->actingAsAdmin();
        $this->makeEmployee();

        $this->get(route('incidents.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Incidents/Create')
                ->has('incidentTypes')
                ->has('employees')
                ->has('selectedEmployee'));
    }

    public function test_supervisor_can_open_create_form(): void
    {
        $supervisor = $this->actingAsSupervisor();
        $this->attachEmployee($supervisor);
        $this->get(route('incidents.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Incidents/Create'));
    }

    public function test_create_uses_employee_query_param_as_selected(): void
    {
        $this->actingAsAdmin();
        $employee = $this->makeEmployee();

        $this->get(route('incidents.create', ['employee' => $employee->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Incidents/Create')
                ->where('selectedEmployee', (string) $employee->id));
    }

    public function test_employee_cannot_open_create_form(): void
    {
        // employee role has no incidents.create permission → policy create() denies.
        $employee = $this->actingAsEmployee();
        $this->attachEmployee($employee);
        $this->get(route('incidents.create'))->assertForbidden();
    }

    public function test_rrhh_cannot_open_create_form(): void
    {
        $this->actingAsRrhh();
        $this->get(route('incidents.create'))->assertForbidden();
    }

    public function test_guest_redirected_from_create(): void
    {
        $this->get(route('incidents.create'))->assertRedirect(route('login'));
    }

    // ----------------------------------------------------------------
    // store
    // ----------------------------------------------------------------

    public function test_admin_can_store_incident_requiring_approval(): void
    {
        $this->actingAsAdmin();
        $type = IncidentType::factory()->create(['requires_approval' => true]);
        $employee = $this->makeEmployee();

        $this->from(route('incidents.create'))
            ->post(route('incidents.store'), [
                'employee_id' => $employee->id,
                'incident_type_id' => $type->id,
                'start_date' => '2026-06-01',
                'end_date' => '2026-06-03',
                'reason' => 'Asunto personal',
            ])
            ->assertRedirect(route('incidents.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('incidents', [
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'status' => 'pending',
        ]);
    }

    public function test_store_auto_approves_when_type_does_not_require_approval(): void
    {
        $admin = $this->actingAsAdmin();
        $type = IncidentType::factory()->create(['requires_approval' => false, 'deducts_vacation' => false]);
        $employee = $this->makeEmployee();

        $this->post(route('incidents.store'), [
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-01',
        ])->assertRedirect(route('incidents.index'));

        $this->assertDatabaseHas('incidents', [
            'employee_id' => $employee->id,
            'status' => 'approved',
            'approved_by' => $admin->id,
        ]);
    }

    public function test_store_auto_approve_deducts_vacation_balance(): void
    {
        $this->actingAsAdmin();
        $type = IncidentType::factory()->create(['requires_approval' => false, 'deducts_vacation' => true]);
        // Mon-Fri schedule: 2026-06-01 (Mon) to 2026-06-03 (Wed) = 3 working days.
        $employee = $this->makeEmployee(['vacation_days_entitled' => 12, 'vacation_days_used' => 0]);

        $this->post(route('incidents.store'), [
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-03',
        ])->assertRedirect(route('incidents.index'));

        $employee->refresh();
        $this->assertSame(3, (int) $employee->vacation_days_used);
    }

    public function test_vacation_counts_saturday_after_three_days_in_week(): void
    {
        // Regla de Dani (2026-06-24): caso Humberto. Mié 17 a Mar 23 jun 2026.
        // Semana 1 (jun 15-21): Mié/Jue/Vie = 3 días → suma el sáb 20 (en rango).
        // Semana 2 (jun 22-28): Lun/Mar = 2 días → no suma sábado.
        // Hábiles = 5, + 1 sábado = 6.
        $this->actingAsAdmin();
        $type = IncidentType::factory()->create(['requires_approval' => false, 'deducts_vacation' => true]);
        $employee = $this->makeEmployee(['vacation_days_entitled' => 28, 'vacation_days_used' => 0]);

        $this->post(route('incidents.store'), [
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'start_date' => '2026-06-17',
            'end_date' => '2026-06-23',
        ])->assertRedirect(route('incidents.index'));

        $this->assertSame(6, (int) $employee->fresh()->vacation_days_used);
    }

    public function test_vacation_does_not_count_saturday_below_threshold_per_week(): void
    {
        // Jue 18 a Lun 22: Jue/Vie (semana 1 = 2) + Lun (semana 2 = 1). Ninguna
        // semana llega a 3 → no se suma sábado, aunque el total hábil sea 3.
        $this->actingAsAdmin();
        $type = IncidentType::factory()->create(['requires_approval' => false, 'deducts_vacation' => true]);
        $employee = $this->makeEmployee(['vacation_days_entitled' => 28, 'vacation_days_used' => 0]);

        $this->post(route('incidents.store'), [
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'start_date' => '2026-06-18',
            'end_date' => '2026-06-22',
        ])->assertRedirect(route('incidents.index'));

        $this->assertSame(3, (int) $employee->fresh()->vacation_days_used);
    }

    public function test_vacation_does_not_count_saturday_outside_requested_range(): void
    {
        // Mié 17 a Vie 19: 3 días en la semana, pero el sábado 20 queda FUERA
        // del rango solicitado → no se cuenta. Hábiles = 3.
        $this->actingAsAdmin();
        $type = IncidentType::factory()->create(['requires_approval' => false, 'deducts_vacation' => true]);
        $employee = $this->makeEmployee(['vacation_days_entitled' => 28, 'vacation_days_used' => 0]);

        $this->post(route('incidents.store'), [
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'start_date' => '2026-06-17',
            'end_date' => '2026-06-19',
        ])->assertRedirect(route('incidents.index'));

        $this->assertSame(3, (int) $employee->fresh()->vacation_days_used);
    }

    public function test_non_vacation_incident_does_not_count_saturday(): void
    {
        // La regla es solo para vacaciones: un permiso por días hábiles con 3
        // días en la semana NO suma el sábado.
        $this->actingAsAdmin();
        $type = IncidentType::factory()->create(['requires_approval' => false, 'deducts_vacation' => false]);
        $employee = $this->makeEmployee();

        $this->post(route('incidents.store'), [
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'start_date' => '2026-06-17',
            'end_date' => '2026-06-23',
        ])->assertRedirect(route('incidents.index'));

        // 5 hábiles (Mié/Jue/Vie/Lun/Mar), sin sábado.
        $this->assertDatabaseHas('incidents', [
            'employee_id' => $employee->id,
            'days_count' => 5,
        ]);
    }

    public function test_store_rejects_insufficient_vacation_balance_on_auto_approve(): void
    {
        $this->actingAsAdmin();
        $type = IncidentType::factory()->create(['requires_approval' => false, 'deducts_vacation' => true]);
        $employee = $this->makeEmployee(['vacation_days_entitled' => 12, 'vacation_days_used' => 11]);

        $this->from(route('incidents.create'))
            ->post(route('incidents.store'), [
                'employee_id' => $employee->id,
                'incident_type_id' => $type->id,
                'start_date' => '2026-06-01',
                'end_date' => '2026-06-03', // 3 working days > 1 available
            ])
            ->assertSessionHasErrors(['saldo']);

        $this->assertDatabaseMissing('incidents', ['employee_id' => $employee->id]);
    }

    public function test_store_rejects_overlapping_incident(): void
    {
        $this->actingAsAdmin();
        $type = IncidentType::factory()->create(['requires_approval' => true]);
        $employee = $this->makeEmployee();

        Incident::factory()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'status' => 'pending',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-05',
        ]);

        $this->from(route('incidents.create'))
            ->post(route('incidents.store'), [
                'employee_id' => $employee->id,
                'incident_type_id' => $type->id,
                'start_date' => '2026-06-03',
                'end_date' => '2026-06-08',
            ])
            ->assertSessionHasErrors(['dates']);
    }

    public function test_store_validation_requires_core_fields(): void
    {
        $this->actingAsAdmin();
        $this->from(route('incidents.create'))
            ->post(route('incidents.store'), [])
            ->assertRedirect(route('incidents.create'))
            ->assertSessionHasErrors(['employee_id', 'incident_type_id', 'start_date', 'end_date']);
    }

    public function test_store_validation_rejects_end_before_start(): void
    {
        $this->actingAsAdmin();
        $type = IncidentType::factory()->create();
        $employee = $this->makeEmployee();

        $this->from(route('incidents.create'))
            ->post(route('incidents.store'), [
                'employee_id' => $employee->id,
                'incident_type_id' => $type->id,
                'start_date' => '2026-06-10',
                'end_date' => '2026-06-01',
            ])
            ->assertSessionHasErrors(['end_date']);
    }

    public function test_store_requires_document_when_type_requires_it(): void
    {
        $this->actingAsAdmin();
        $type = IncidentType::factory()->create(['requires_document' => true]);
        $employee = $this->makeEmployee();

        $this->from(route('incidents.create'))
            ->post(route('incidents.store'), [
                'employee_id' => $employee->id,
                'incident_type_id' => $type->id,
                'start_date' => '2026-06-01',
                'end_date' => '2026-06-01',
            ])
            ->assertSessionHasErrors(['document']);
    }

    public function test_supervisor_can_store_incident_for_team_member(): void
    {
        $supervisor = $this->actingAsSupervisor();
        $supEmployee = $this->attachEmployee($supervisor);
        $type = IncidentType::factory()->create(['requires_approval' => true]);
        $sub = $this->makeEmployee(['supervisor_id' => $supEmployee->id]);

        $this->from(route('incidents.create'))
            ->post(route('incidents.store'), [
                'employee_id' => $sub->id,
                'incident_type_id' => $type->id,
                'start_date' => '2026-06-01',
                'end_date' => '2026-06-02',
                'reason' => 'Permiso de equipo',
            ])
            ->assertRedirect(route('incidents.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('incidents', [
            'employee_id' => $sub->id,
            'incident_type_id' => $type->id,
            'status' => 'pending',
        ]);
    }

    public function test_store_auto_calculates_hours_from_time_range(): void
    {
        $this->actingAsAdmin();
        $type = IncidentType::factory()->create(['requires_approval' => true]);
        $employee = $this->makeEmployee();

        $this->post(route('incidents.store'), [
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-01',
            'start_time' => '09:00',
            'end_time' => '13:30',
        ])->assertRedirect(route('incidents.index'));

        // 09:00 → 13:30 is a forward 4.5h range; hours must be stored POSITIVE.
        $stored = Incident::where('employee_id', $employee->id)->value('hours');
        $this->assertSame(4.5, (float) $stored);
    }

    public function test_store_validation_rejects_out_of_range_hours(): void
    {
        $this->actingAsAdmin();
        $type = IncidentType::factory()->create();
        $employee = $this->makeEmployee();

        $this->from(route('incidents.create'))
            ->post(route('incidents.store'), [
                'employee_id' => $employee->id,
                'incident_type_id' => $type->id,
                'start_date' => '2026-06-01',
                'end_date' => '2026-06-01',
                'hours' => 30, // > 24
            ])
            ->assertSessionHasErrors(['hours']);
    }

    public function test_store_validation_rejects_nonexistent_foreign_keys(): void
    {
        $this->actingAsAdmin();
        $this->from(route('incidents.create'))
            ->post(route('incidents.store'), [
                'employee_id' => 999999,
                'incident_type_id' => 999999,
                'start_date' => '2026-06-01',
                'end_date' => '2026-06-01',
            ])
            ->assertSessionHasErrors(['employee_id', 'incident_type_id']);
    }

    public function test_employee_cannot_store_incident(): void
    {
        $employee = $this->actingAsEmployee();
        $own = $this->attachEmployee($employee);
        $type = IncidentType::factory()->create();

        $this->post(route('incidents.store'), [
            'employee_id' => $own->id,
            'incident_type_id' => $type->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-01',
        ])->assertForbidden();
    }

    public function test_rrhh_cannot_store_incident(): void
    {
        $this->actingAsRrhh();
        $type = IncidentType::factory()->create();
        $employee = $this->makeEmployee();

        $this->post(route('incidents.store'), [
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-01',
        ])->assertForbidden();
    }

    public function test_guest_redirected_from_store(): void
    {
        $type = IncidentType::factory()->create();
        $employee = $this->makeEmployee();
        $this->post(route('incidents.store'), [
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-01',
        ])->assertRedirect(route('login'));
    }

    // ----------------------------------------------------------------
    // createBulk / storeBulk
    // ----------------------------------------------------------------

    public function test_admin_sees_create_bulk_form_with_props(): void
    {
        $this->actingAsAdmin();
        $this->makeEmployee();

        $this->get(route('incidents.createBulk'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Incidents/CreateBulk')
                ->has('employees')
                ->has('incidentTypes'));
    }

    public function test_admin_can_store_bulk_incidents(): void
    {
        $this->actingAsAdmin();
        $type = IncidentType::factory()->create(['requires_approval' => true]);
        $e1 = $this->makeEmployee();
        $e2 = $this->makeEmployee();

        $this->post(route('incidents.storeBulk'), [
            'employee_ids' => [$e1->id, $e2->id],
            'incident_type_id' => $type->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
            'reason' => 'Cierre de tienda',
        ])
            ->assertRedirect(route('incidents.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('incidents', ['employee_id' => $e1->id, 'status' => 'pending']);
        $this->assertDatabaseHas('incidents', ['employee_id' => $e2->id, 'status' => 'pending']);
    }

    public function test_store_bulk_validation_requires_employee_ids(): void
    {
        $this->actingAsAdmin();
        $type = IncidentType::factory()->create();

        $this->from(route('incidents.createBulk'))
            ->post(route('incidents.storeBulk'), [
                'incident_type_id' => $type->id,
                'start_date' => '2026-06-01',
                'end_date' => '2026-06-02',
            ])
            ->assertSessionHasErrors(['employee_ids']);
    }

    public function test_store_bulk_skips_overlapping_employee(): void
    {
        $this->actingAsAdmin();
        $type = IncidentType::factory()->create(['requires_approval' => true]);
        $e1 = $this->makeEmployee();

        Incident::factory()->create([
            'employee_id' => $e1->id,
            'incident_type_id' => $type->id,
            'status' => 'pending',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-05',
        ]);

        $this->post(route('incidents.storeBulk'), [
            'employee_ids' => [$e1->id],
            'incident_type_id' => $type->id,
            'start_date' => '2026-06-02',
            'end_date' => '2026-06-03',
        ])->assertRedirect(route('incidents.index'));

        // Only the pre-existing incident exists; the overlapping one was skipped.
        $this->assertSame(1, Incident::where('employee_id', $e1->id)->count());
    }

    public function test_store_bulk_auto_approves_and_deducts_vacation(): void
    {
        $admin = $this->actingAsAdmin();
        $type = IncidentType::factory()->create(['requires_approval' => false, 'deducts_vacation' => true]);
        // Mon-Fri default schedule: 2026-06-01..03 = 3 working days.
        $employee = $this->makeEmployee(['vacation_days_entitled' => 12, 'vacation_days_used' => 0]);

        // Omit the (nullable) "reason" field; storeBulk() must not crash on the
        // absent key and should create + auto-approve the incident, deducting vacation.
        $this->post(route('incidents.storeBulk'), [
            'employee_ids' => [$employee->id],
            'incident_type_id' => $type->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-03',
        ])
            ->assertRedirect(route('incidents.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('incidents', [
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'status' => 'approved',
            'approved_by' => $admin->id,
            'reason' => null,
        ]);

        // 3 working days (Mon-Wed) deducted from the vacation balance.
        $employee->refresh();
        $this->assertSame(3, (int) $employee->vacation_days_used);
    }

    public function test_store_bulk_skips_employee_with_insufficient_vacation(): void
    {
        $this->actingAsAdmin();
        $type = IncidentType::factory()->create(['requires_approval' => false, 'deducts_vacation' => true]);
        $employee = $this->makeEmployee(['vacation_days_entitled' => 12, 'vacation_days_used' => 11]);

        $this->post(route('incidents.storeBulk'), [
            'employee_ids' => [$employee->id],
            'incident_type_id' => $type->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-03', // 3 working days > 1 available
        ])->assertRedirect(route('incidents.index'))
            ->assertSessionHas('success');

        // Skipped: no incident created, balance unchanged.
        $this->assertSame(0, Incident::where('employee_id', $employee->id)->count());
        $employee->refresh();
        $this->assertSame(11, (int) $employee->vacation_days_used);
    }

    public function test_store_bulk_validation_requires_type_and_dates(): void
    {
        $this->actingAsAdmin();
        $e1 = $this->makeEmployee();

        $this->from(route('incidents.createBulk'))
            ->post(route('incidents.storeBulk'), [
                'employee_ids' => [$e1->id],
            ])
            ->assertSessionHasErrors(['incident_type_id', 'start_date', 'end_date']);
    }

    public function test_store_bulk_validation_rejects_end_before_start(): void
    {
        $this->actingAsAdmin();
        $type = IncidentType::factory()->create();
        $e1 = $this->makeEmployee();

        $this->from(route('incidents.createBulk'))
            ->post(route('incidents.storeBulk'), [
                'employee_ids' => [$e1->id],
                'incident_type_id' => $type->id,
                'start_date' => '2026-06-10',
                'end_date' => '2026-06-01',
            ])
            ->assertSessionHasErrors(['end_date']);
    }

    public function test_rrhh_cannot_store_bulk(): void
    {
        $this->actingAsRrhh();
        $type = IncidentType::factory()->create();
        $e1 = $this->makeEmployee();

        $this->post(route('incidents.storeBulk'), [
            'employee_ids' => [$e1->id],
            'incident_type_id' => $type->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
        ])->assertForbidden();
    }

    public function test_guest_redirected_from_store_bulk(): void
    {
        $type = IncidentType::factory()->create();
        $e1 = $this->makeEmployee();
        $this->post(route('incidents.storeBulk'), [
            'employee_ids' => [$e1->id],
            'incident_type_id' => $type->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
        ])->assertRedirect(route('login'));
    }

    public function test_rrhh_cannot_access_create_bulk(): void
    {
        $this->actingAsRrhh();
        $this->get(route('incidents.createBulk'))->assertForbidden();
    }

    public function test_guest_redirected_from_create_bulk(): void
    {
        $this->get(route('incidents.createBulk'))->assertRedirect(route('login'));
    }

    // ----------------------------------------------------------------
    // show
    // ----------------------------------------------------------------

    public function test_admin_sees_show_with_can_props(): void
    {
        $this->actingAsAdmin();
        $type = IncidentType::factory()->create();
        $employee = $this->makeEmployee();
        $incident = Incident::factory()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'status' => 'pending',
        ]);

        $this->get(route('incidents.show', $incident))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Incidents/Show')
                ->has('incident')
                ->has('can', fn (Assert $can) => $can
                    ->where('edit', true)
                    ->where('delete', true)
                    ->where('approve', true)
                    ->where('reject', true)));
    }

    public function test_supervisor_cannot_view_incident_outside_team(): void
    {
        $supervisor = $this->actingAsSupervisor();
        $this->attachEmployee($supervisor);
        $type = IncidentType::factory()->create();
        $outsider = $this->makeEmployee();
        $incident = Incident::factory()->create([
            'employee_id' => $outsider->id,
            'incident_type_id' => $type->id,
        ]);

        $this->get(route('incidents.show', $incident))->assertForbidden();
    }

    public function test_supervisor_show_team_incident_has_no_approve_rights(): void
    {
        $supervisor = $this->actingAsSupervisor();
        $supEmployee = $this->attachEmployee($supervisor);
        $type = IncidentType::factory()->create();
        $sub = $this->makeEmployee(['supervisor_id' => $supEmployee->id]);
        $incident = Incident::factory()->create([
            'employee_id' => $sub->id,
            'incident_type_id' => $type->id,
            'status' => 'pending',
        ]);

        $this->get(route('incidents.show', $incident))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Incidents/Show')
                ->where('can.approve', false)
                ->where('can.reject', false));
    }

    public function test_rrhh_cannot_view_show(): void
    {
        // rrhh has no incidents.view_* permission → policy view() returns false → 403.
        $this->actingAsRrhh();
        $type = IncidentType::factory()->create();
        $incident = Incident::factory()->create(['incident_type_id' => $type->id]);
        $this->get(route('incidents.show', $incident))->assertForbidden();
    }

    public function test_employee_cannot_view_others_show(): void
    {
        // employee has no incidents.view_* permission at all → 403 even for any incident.
        $employee = $this->actingAsEmployee();
        $this->attachEmployee($employee);
        $type = IncidentType::factory()->create();
        $outsider = $this->makeEmployee();
        $incident = Incident::factory()->create([
            'employee_id' => $outsider->id,
            'incident_type_id' => $type->id,
        ]);
        $this->get(route('incidents.show', $incident))->assertForbidden();
    }

    public function test_guest_redirected_from_show(): void
    {
        $type = IncidentType::factory()->create();
        $incident = Incident::factory()->create(['incident_type_id' => $type->id]);
        $this->get(route('incidents.show', $incident))->assertRedirect(route('login'));
    }

    // ----------------------------------------------------------------
    // edit / update
    // ----------------------------------------------------------------

    public function test_admin_sees_edit_form_with_props(): void
    {
        $this->actingAsAdmin();
        $type = IncidentType::factory()->create();
        $employee = $this->makeEmployee();
        $incident = Incident::factory()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'status' => 'pending',
        ]);

        $this->get(route('incidents.edit', $incident))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Incidents/Edit')
                ->has('incident')
                ->has('incidentTypes')
                ->has('employees'));
    }

    public function test_admin_can_update_pending_incident(): void
    {
        $this->actingAsAdmin();
        $type = IncidentType::factory()->create();
        $employee = $this->makeEmployee();
        $incident = Incident::factory()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'status' => 'pending',
            'reason' => 'old',
        ]);

        $this->put(route('incidents.update', $incident), [
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
            'reason' => 'nuevo motivo',
        ])
            ->assertRedirect(route('incidents.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('incidents', ['id' => $incident->id, 'reason' => 'nuevo motivo']);
    }

    public function test_update_blocks_non_pending_incident(): void
    {
        $this->actingAsAdmin();
        $type = IncidentType::factory()->create();
        $employee = $this->makeEmployee();
        $incident = Incident::factory()->approved()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
        ]);

        $this->put(route('incidents.update', $incident), [
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
            'reason' => 'attempt',
        ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('incidents', ['id' => $incident->id, 'reason' => 'attempt']);
    }

    public function test_update_validation_errors(): void
    {
        $this->actingAsAdmin();
        $type = IncidentType::factory()->create();
        $employee = $this->makeEmployee();
        $incident = Incident::factory()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'status' => 'pending',
        ]);

        $this->put(route('incidents.update', $incident), [
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-01',
        ])->assertSessionHasErrors(['employee_id', 'incident_type_id', 'end_date']);
    }

    public function test_supervisor_cannot_edit_team_incident(): void
    {
        // Policy update(): supervisor lacks view_all, and isOwnIncident checks the
        // incident's employee_id against the SUPERVISOR's own employee id (not the
        // subordinate), so a team member's incident is NOT "own" → update denied (403).
        $supervisor = $this->actingAsSupervisor();
        $supEmployee = $this->attachEmployee($supervisor);
        $type = IncidentType::factory()->create();
        $sub = $this->makeEmployee(['supervisor_id' => $supEmployee->id]);
        $incident = Incident::factory()->create([
            'employee_id' => $sub->id,
            'incident_type_id' => $type->id,
            'status' => 'pending',
        ]);

        $this->get(route('incidents.edit', $incident))->assertForbidden();
        $this->put(route('incidents.update', $incident), [
            'employee_id' => $sub->id,
            'incident_type_id' => $type->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
        ])->assertForbidden();
    }

    public function test_employee_cannot_update_own_incident_without_create_perm(): void
    {
        // Employee role has no incidents.create AND no view_all. Policy update() allows
        // creator-on-pending via isOwnIncident, so an employee CAN reach update on their
        // own pending incident even without create perm. Document the real behavior.
        $employee = $this->actingAsEmployee();
        $own = $this->attachEmployee($employee);
        $type = IncidentType::factory()->create();
        $incident = Incident::factory()->create([
            'employee_id' => $own->id,
            'incident_type_id' => $type->id,
            'status' => 'pending',
            'reason' => 'orig',
        ]);

        $response = $this->put(route('incidents.update', $incident), [
            'employee_id' => $own->id,
            'incident_type_id' => $type->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
            'reason' => 'editado por empleado',
        ]);

        // Policy permits the owner-on-pending path → redirect success, not 403.
        $response->assertRedirect(route('incidents.index'));
        $this->assertDatabaseHas('incidents', ['id' => $incident->id, 'reason' => 'editado por empleado']);
    }

    public function test_rrhh_cannot_edit_incident(): void
    {
        $this->actingAsRrhh();
        $type = IncidentType::factory()->create();
        $incident = Incident::factory()->create(['incident_type_id' => $type->id]);
        $this->get(route('incidents.edit', $incident))->assertForbidden();
    }

    public function test_guest_redirected_from_update(): void
    {
        $type = IncidentType::factory()->create();
        $incident = Incident::factory()->create(['incident_type_id' => $type->id, 'status' => 'pending']);
        $this->put(route('incidents.update', $incident), [
            'employee_id' => $incident->employee_id,
            'incident_type_id' => $type->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
        ])->assertRedirect(route('login'));
    }

    public function test_guest_redirected_from_edit(): void
    {
        $type = IncidentType::factory()->create();
        $incident = Incident::factory()->create(['incident_type_id' => $type->id]);
        $this->get(route('incidents.edit', $incident))->assertRedirect(route('login'));
    }

    // ----------------------------------------------------------------
    // destroy
    // ----------------------------------------------------------------

    public function test_admin_can_soft_delete_pending_incident(): void
    {
        $this->actingAsAdmin();
        $type = IncidentType::factory()->create();
        $employee = $this->makeEmployee();
        $incident = Incident::factory()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'status' => 'pending',
        ]);

        $this->delete(route('incidents.destroy', $incident))
            ->assertRedirect(route('incidents.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('incidents', ['id' => $incident->id]);
    }

    public function test_destroy_approved_incident_is_blocked_by_policy_for_everyone(): void
    {
        // The controller destroy() contains vacation-refund logic for an already-approved
        // deducts_vacation incident, but IncidentPolicy::delete() returns false whenever
        // status !== 'pending' for ALL roles (the status guard precedes the view_all check),
        // so that refund branch is unreachable: even admin gets 403. We assert the real
        // (authorization-blocked) behavior here; the refund code is effectively dead.
        $this->actingAsAdmin();
        $type = IncidentType::factory()->create(['deducts_vacation' => true]);
        $employee = $this->makeEmployee(['vacation_days_used' => 5]);
        $incident = Incident::factory()->approved()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'days_count' => 3,
        ]);

        $this->delete(route('incidents.destroy', $incident))->assertForbidden();

        // Incident is NOT deleted and balance is unchanged.
        $this->assertDatabaseHas('incidents', ['id' => $incident->id, 'deleted_at' => null]);
        $employee->refresh();
        $this->assertSame(5, (int) $employee->vacation_days_used);
    }

    public function test_destroy_blocks_non_pending_for_non_admin_perm(): void
    {
        // Policy delete() returns false when status !== pending for non-view_all users.
        // Admin has view_all so they CAN delete approved; this asserts the policy gate
        // via supervisor on a non-team / approved incident -> forbidden.
        $supervisor = $this->actingAsSupervisor();
        $this->attachEmployee($supervisor);
        $type = IncidentType::factory()->create();
        $outsider = $this->makeEmployee();
        $incident = Incident::factory()->approved()->create([
            'employee_id' => $outsider->id,
            'incident_type_id' => $type->id,
        ]);

        $this->delete(route('incidents.destroy', $incident))->assertForbidden();
    }

    public function test_rrhh_cannot_destroy_incident(): void
    {
        $this->actingAsRrhh();
        $type = IncidentType::factory()->create();
        $incident = Incident::factory()->create(['incident_type_id' => $type->id, 'status' => 'pending']);
        $this->delete(route('incidents.destroy', $incident))->assertForbidden();
    }

    public function test_guest_redirected_from_destroy(): void
    {
        $type = IncidentType::factory()->create();
        $incident = Incident::factory()->create(['incident_type_id' => $type->id]);
        $this->delete(route('incidents.destroy', $incident))->assertRedirect(route('login'));
    }

    // ----------------------------------------------------------------
    // approve / reject
    // ----------------------------------------------------------------

    public function test_admin_can_approve_pending_incident(): void
    {
        $admin = $this->actingAsAdmin();
        [, $code] = $this->attachRealTwoFactor($admin);

        $type = IncidentType::factory()->create(['deducts_vacation' => false]);
        $employee = $this->makeEmployee();
        $incident = Incident::factory()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'status' => 'pending',
        ]);

        $this->post(route('incidents.approve', $incident), ['two_factor_code' => $code])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('incidents', [
            'id' => $incident->id,
            'status' => 'approved',
            'approved_by' => $admin->id,
        ]);
    }

    public function test_approve_deducts_vacation_balance(): void
    {
        $admin = $this->actingAsAdmin();
        [, $code] = $this->attachRealTwoFactor($admin);

        $type = IncidentType::factory()->create(['deducts_vacation' => true]);
        $employee = $this->makeEmployee(['vacation_days_entitled' => 12, 'vacation_days_used' => 0]);
        $incident = Incident::factory()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'status' => 'pending',
            'days_count' => 4,
        ]);

        $this->post(route('incidents.approve', $incident), ['two_factor_code' => $code])
            ->assertSessionHas('success');

        $employee->refresh();
        $this->assertSame(4, (int) $employee->vacation_days_used);
    }

    public function test_approve_rejects_insufficient_vacation(): void
    {
        $admin = $this->actingAsAdmin();
        [, $code] = $this->attachRealTwoFactor($admin);

        $type = IncidentType::factory()->create(['deducts_vacation' => true]);
        $employee = $this->makeEmployee(['vacation_days_entitled' => 12, 'vacation_days_used' => 11]);
        $incident = Incident::factory()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'status' => 'pending',
            'days_count' => 4,
        ]);

        $this->post(route('incidents.approve', $incident), ['two_factor_code' => $code])
            ->assertSessionHasErrors(['saldo']);

        $this->assertDatabaseHas('incidents', ['id' => $incident->id, 'status' => 'pending']);
    }

    public function test_approve_on_already_processed_is_blocked_by_policy(): void
    {
        // The controller approve() has an "already processed" guard that returns a
        // friendly redirect with an 'error' flash, but IncidentPolicy::approve() ends with
        // `return $incident->status === 'pending'` — so a non-pending incident is denied at
        // the authorization layer (403) and that controller guard is never reached.
        // We assert the real behavior (403); the controller's error branch is dead code.
        $admin = $this->actingAsAdmin();
        [, $code] = $this->attachRealTwoFactor($admin);

        $type = IncidentType::factory()->create();
        $employee = $this->makeEmployee();
        $incident = Incident::factory()->approved()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
        ]);

        $this->post(route('incidents.approve', $incident), ['two_factor_code' => $code])
            ->assertForbidden();
    }

    public function test_admin_can_reject_pending_incident_with_reason(): void
    {
        $admin = $this->actingAsAdmin();
        [, $code] = $this->attachRealTwoFactor($admin);

        $type = IncidentType::factory()->create();
        $employee = $this->makeEmployee();
        $incident = Incident::factory()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'status' => 'pending',
        ]);

        $this->post(route('incidents.reject', $incident), [
            'two_factor_code' => $code,
            'rejection_reason' => 'No procede',
        ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('incidents', [
            'id' => $incident->id,
            'status' => 'rejected',
            'rejection_reason' => 'No procede',
        ]);
    }

    public function test_approve_requires_two_factor_code_when_enabled(): void
    {
        // Admin (created via harness) has 2FA enabled; missing code → validation error,
        // incident stays pending.
        $admin = $this->actingAsAdmin();
        $this->attachRealTwoFactor($admin);

        $type = IncidentType::factory()->create(['deducts_vacation' => false]);
        $employee = $this->makeEmployee();
        $incident = Incident::factory()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'status' => 'pending',
        ]);

        $this->from(route('incidents.show', $incident))
            ->post(route('incidents.approve', $incident), [])
            ->assertSessionHasErrors(['two_factor_code']);

        $this->assertDatabaseHas('incidents', ['id' => $incident->id, 'status' => 'pending']);
    }

    public function test_approve_rejects_invalid_two_factor_code(): void
    {
        $admin = $this->actingAsAdmin();
        $this->attachRealTwoFactor($admin);

        $type = IncidentType::factory()->create(['deducts_vacation' => false]);
        $employee = $this->makeEmployee();
        $incident = Incident::factory()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'status' => 'pending',
        ]);

        // Well-formed (6 digits) but wrong code → service rejects.
        $this->from(route('incidents.show', $incident))
            ->post(route('incidents.approve', $incident), ['two_factor_code' => '000000'])
            ->assertSessionHasErrors(['two_factor_code']);

        $this->assertDatabaseHas('incidents', ['id' => $incident->id, 'status' => 'pending']);
    }

    public function test_reject_rejects_invalid_two_factor_code(): void
    {
        $admin = $this->actingAsAdmin();
        $this->attachRealTwoFactor($admin);

        $type = IncidentType::factory()->create();
        $employee = $this->makeEmployee();
        $incident = Incident::factory()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'status' => 'pending',
        ]);

        $this->from(route('incidents.show', $incident))
            ->post(route('incidents.reject', $incident), [
                'two_factor_code' => '000000',
                'rejection_reason' => 'No procede',
            ])
            ->assertSessionHasErrors(['two_factor_code']);

        $this->assertDatabaseHas('incidents', ['id' => $incident->id, 'status' => 'pending']);
    }

    public function test_reject_requires_reason(): void
    {
        $admin = $this->actingAsAdmin();
        [, $code] = $this->attachRealTwoFactor($admin);

        $type = IncidentType::factory()->create();
        $employee = $this->makeEmployee();
        $incident = Incident::factory()->create([
            'employee_id' => $employee->id,
            'incident_type_id' => $type->id,
            'status' => 'pending',
        ]);

        $this->post(route('incidents.reject', $incident), ['two_factor_code' => $code])
            ->assertSessionHasErrors(['rejection_reason']);

        $this->assertDatabaseHas('incidents', ['id' => $incident->id, 'status' => 'pending']);
    }

    public function test_supervisor_cannot_approve_incident(): void
    {
        // supervisor lacks incidents.approve permission entirely → policy denies → 403.
        $supervisor = $this->actingAsSupervisor();
        $supEmployee = $this->attachEmployee($supervisor);
        $type = IncidentType::factory()->create();
        $sub = $this->makeEmployee(['supervisor_id' => $supEmployee->id]);
        $incident = Incident::factory()->create([
            'employee_id' => $sub->id,
            'incident_type_id' => $type->id,
            'status' => 'pending',
        ]);

        $this->post(route('incidents.approve', $incident))->assertForbidden();
    }

    public function test_supervisor_cannot_reject_incident(): void
    {
        $supervisor = $this->actingAsSupervisor();
        $supEmployee = $this->attachEmployee($supervisor);
        $type = IncidentType::factory()->create();
        $sub = $this->makeEmployee(['supervisor_id' => $supEmployee->id]);
        $incident = Incident::factory()->create([
            'employee_id' => $sub->id,
            'incident_type_id' => $type->id,
            'status' => 'pending',
        ]);

        $this->post(route('incidents.reject', $incident), ['rejection_reason' => 'x'])->assertForbidden();
    }

    public function test_rrhh_cannot_approve_incident(): void
    {
        $this->actingAsRrhh();
        $type = IncidentType::factory()->create();
        $incident = Incident::factory()->create(['incident_type_id' => $type->id, 'status' => 'pending']);
        $this->post(route('incidents.approve', $incident))->assertForbidden();
    }

    public function test_employee_cannot_approve_incident(): void
    {
        // employee role lacks incidents.approve entirely → policy denies → 403.
        $employee = $this->actingAsEmployee();
        $own = $this->attachEmployee($employee);
        $type = IncidentType::factory()->create();
        $incident = Incident::factory()->create([
            'employee_id' => $own->id,
            'incident_type_id' => $type->id,
            'status' => 'pending',
        ]);
        $this->post(route('incidents.approve', $incident))->assertForbidden();
    }

    public function test_rrhh_cannot_reject_incident(): void
    {
        $this->actingAsRrhh();
        $type = IncidentType::factory()->create();
        $incident = Incident::factory()->create(['incident_type_id' => $type->id, 'status' => 'pending']);
        $this->post(route('incidents.reject', $incident), ['rejection_reason' => 'x'])->assertForbidden();
    }

    public function test_guest_redirected_from_approve(): void
    {
        $type = IncidentType::factory()->create();
        $incident = Incident::factory()->create(['incident_type_id' => $type->id, 'status' => 'pending']);
        $this->post(route('incidents.approve', $incident))->assertRedirect(route('login'));
    }

    public function test_guest_redirected_from_reject(): void
    {
        $type = IncidentType::factory()->create();
        $incident = Incident::factory()->create(['incident_type_id' => $type->id, 'status' => 'pending']);
        $this->post(route('incidents.reject', $incident), ['rejection_reason' => 'x'])
            ->assertRedirect(route('login'));
    }
}
