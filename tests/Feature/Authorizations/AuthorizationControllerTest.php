<?php

namespace Tests\Feature\Authorizations;

use App\Models\Authorization;
use App\Models\AttendanceRecord;
use App\Models\CompensationType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\Position;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;
use Tests\FeatureTestCase;

/**
 * Feature tests for AuthorizationController (resource + bulk + approve/reject/
 * markPaid + suggest endpoints).
 *
 * 2FA NOTE: admin/rrhh/supervisor are forced into 2FA by EnsureTwoFactorSetup.
 * The shared harness device stores a PLAINTEXT secret, which TwoFactorService
 * cannot decrypt — so for the verifyTwoFactorCode-guarded actions
 * (approve/reject/markPaid/bulkApprove/bulkReject) we build a privileged user
 * whose device has a real ENCRYPTED secret and generate a valid TOTP code via
 * twoFactor() below.
 */
class AuthorizationControllerTest extends FeatureTestCase
{
    /**
     * Create a privileged user with a usable (encrypted-secret) confirmed 2FA
     * device. Returns [User, validTotpCode]. Defaults to admin role.
     *
     * @return array{0: User, 1: string}
     */
    private function privilegedWithTwoFactor(string $role = 'admin', array $attrs = []): array
    {
        $user = $this->createUser($role, $attrs, withTwoFactor: false);
        $secret = (new Google2FA())->generateSecretKey();
        $user->twoFactorDevices()->create([
            'name' => 'TestDevice',
            'secret' => Crypt::encryptString($secret),
            'confirmed_at' => now(),
        ]);
        $code = (new Google2FA())->getCurrentOtp($secret);

        return [$user, $code];
    }

    /** A pending overtime authorization for an arbitrary employee. */
    private function pendingOvertime(array $attrs = []): Authorization
    {
        return Authorization::factory()->overtime()->create(array_merge([
            'employee_id' => Employee::factory()->create()->id,
            'requested_by' => User::factory()->create()->id,
            'status' => Authorization::STATUS_PENDING,
        ], $attrs));
    }

    // ─────────────────────────────────────────────────────────────────────
    // index
    // ─────────────────────────────────────────────────────────────────────

    public function test_index_renders_with_all_props_for_admin(): void
    {
        $this->actingAsAdmin();
        $this->pendingOvertime();

        $this->get(route('authorizations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Authorizations/Index')
                ->has('authorizations')
                ->has('authorizations.data')
                ->has('employees')
                ->has('departments')
                ->has('pendingCount')
                ->has('filters')
                ->has('types')
                ->has('can')
                ->has('can.create')
                ->has('can.approve')
                ->has('can.reject'));
    }

    public function test_index_pending_count_reflects_pending_authorizations(): void
    {
        $this->actingAsAdmin();
        $this->pendingOvertime();
        $this->pendingOvertime();
        Authorization::factory()->approved()->create([
            'employee_id' => Employee::factory()->create()->id,
            'requested_by' => User::factory()->create()->id,
        ]);

        $this->get(route('authorizations.index'))
            ->assertInertia(fn (Assert $page) => $page->where('pendingCount', 2));
    }

    public function test_index_status_filter_is_applied(): void
    {
        $this->actingAsAdmin();
        $this->pendingOvertime();
        Authorization::factory()->approved()->create([
            'employee_id' => Employee::factory()->create()->id,
            'requested_by' => User::factory()->create()->id,
        ]);

        $this->get(route('authorizations.index', ['status' => Authorization::STATUS_APPROVED]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('authorizations.data', 1)
                ->where('filters.status', Authorization::STATUS_APPROVED));
    }

    public function test_index_forbidden_for_rrhh(): void
    {
        // rrhh has NO authorizations.* permission → viewAny denies.
        $this->actingAsRrhh();
        $this->get(route('authorizations.index'))->assertForbidden();
    }

    public function test_index_forbidden_for_plain_employee_without_view_own(): void
    {
        // employee role has only attendance.view_own + reports.view_own — no
        // authorizations.view_own → viewAny denies.
        $this->actingAsEmployee();
        $this->get(route('authorizations.index'))->assertForbidden();
    }

    public function test_index_visible_to_supervisor_team_only(): void
    {
        $supUser = $this->supervisorUser();
        $supEmp = $this->attachEmployee($supUser);
        $report = Employee::factory()->create(['supervisor_id' => $supEmp->id]);
        $stranger = Employee::factory()->create();

        $this->pendingOvertime(['employee_id' => $report->id]);
        $this->pendingOvertime(['employee_id' => $stranger->id]);

        $this->actingAs($supUser)
            ->get(route('authorizations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('authorizations.data', 1));
    }

    public function test_index_redirects_guest_to_login(): void
    {
        $this->get(route('authorizations.index'))->assertRedirect(route('login'));
    }

    public function test_index_can_flags_true_for_admin(): void
    {
        $this->actingAsAdmin();
        $this->pendingOvertime();

        $this->get(route('authorizations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.create', true)
                ->where('can.approve', true)
                ->where('can.reject', true));
    }

    public function test_index_can_approve_reject_false_for_supervisor(): void
    {
        // supervisor has create + view_team but NO approve/reject perms.
        $supUser = $this->supervisorUser();
        $this->attachEmployee($supUser);

        $this->actingAs($supUser)
            ->get(route('authorizations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.create', true)
                ->where('can.approve', false)
                ->where('can.reject', false));
    }

    public function test_index_type_filter_is_applied(): void
    {
        $this->actingAsAdmin();
        $this->pendingOvertime();
        Authorization::factory()->special()->create([
            'employee_id' => Employee::factory()->create()->id,
            'requested_by' => User::factory()->create()->id,
            'status' => Authorization::STATUS_PENDING,
        ]);

        $this->get(route('authorizations.index', ['type' => Authorization::TYPE_SPECIAL]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('authorizations.data', 1)
                ->where('filters.type', Authorization::TYPE_SPECIAL)
                ->where('authorizations.data.0.type', Authorization::TYPE_SPECIAL));
    }

    public function test_index_employee_filter_is_applied(): void
    {
        $this->actingAsAdmin();
        $target = Employee::factory()->create();
        $this->pendingOvertime(['employee_id' => $target->id]);
        $this->pendingOvertime(); // a different employee

        $this->get(route('authorizations.index', ['employee' => $target->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('authorizations.data', 1)
                ->where('authorizations.data.0.employee_id', $target->id));
    }

    public function test_index_department_filter_is_applied(): void
    {
        $this->actingAsAdmin();
        $dept = Department::factory()->create();
        $inDept = Employee::factory()->create(['department_id' => $dept->id]);
        $this->pendingOvertime(['employee_id' => $inDept->id]);
        $this->pendingOvertime(); // employee in some other department

        $this->get(route('authorizations.index', ['department' => $dept->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('authorizations.data', 1)
                ->where('authorizations.data.0.employee_id', $inDept->id));
    }

    // ─────────────────────────────────────────────────────────────────────
    // create
    // ─────────────────────────────────────────────────────────────────────

    public function test_create_renders_with_all_props_for_admin(): void
    {
        $this->actingAsAdmin();
        Employee::factory()->create();

        $this->get(route('authorizations.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Authorizations/Create')
                ->has('employees')
                ->has('selectedEmployee')
                ->has('types')
                ->has('prefill')
                ->has('departments')
                ->has('holidays'));
    }

    public function test_create_forbidden_for_rrhh(): void
    {
        $this->actingAsRrhh();
        $this->get(route('authorizations.create'))->assertForbidden();
    }

    public function test_create_allowed_for_supervisor(): void
    {
        $this->actingAsSupervisor();
        $this->get(route('authorizations.create'))->assertOk();
    }

    public function test_create_redirects_guest_to_login(): void
    {
        $this->get(route('authorizations.create'))->assertRedirect(route('login'));
    }

    // ─────────────────────────────────────────────────────────────────────
    // store
    // ─────────────────────────────────────────────────────────────────────

    public function test_store_creates_overtime_authorization(): void
    {
        $admin = $this->actingAsAdmin();
        // Default schedule is Mon-Fri 08:00-17:00; use a Sunday so the
        // overlapsWorkSchedule guard (rest day → no schedule) doesn't fire.
        $emp = Employee::factory()->create();

        $resp = $this->from(route('authorizations.create'))->post(route('authorizations.store'), [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-07', // Sunday — no scheduled work
            'start_time' => '18:00',
            'end_time' => '20:00',
            'hours' => 2,
            'reason' => 'Extra inventory work',
        ]);

        $resp->assertRedirect(route('authorizations.index'));
        $resp->assertSessionHas('success');
        $this->assertDatabaseHas('authorizations', [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'status' => Authorization::STATUS_PENDING,
            'requested_by' => $admin->id,
        ]);
    }

    public function test_store_creates_special_authorization(): void
    {
        $this->actingAsAdmin();
        $emp = Employee::factory()->create();

        $this->from(route('authorizations.create'))->post(route('authorizations.store'), [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_SPECIAL,
            'date' => '2026-06-10',
            'hours' => 3,
            'reason' => 'Special compensation',
        ])->assertRedirect(route('authorizations.index'));

        $this->assertDatabaseHas('authorizations', [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_SPECIAL,
        ]);
    }

    public function test_store_validates_required_fields(): void
    {
        $this->actingAsAdmin();

        $this->from(route('authorizations.create'))
            ->post(route('authorizations.store'), [])
            ->assertRedirect(route('authorizations.create'))
            ->assertSessionHasErrors(['employee_id', 'type', 'date', 'reason']);
    }

    public function test_store_rejects_invalid_type(): void
    {
        $this->actingAsAdmin();
        $emp = Employee::factory()->create();

        $this->from(route('authorizations.create'))
            ->post(route('authorizations.store'), [
                'employee_id' => $emp->id,
                'type' => 'bogus_type',
                'date' => '2026-06-07',
                'reason' => 'x',
            ])
            ->assertSessionHasErrors(['type']);
    }

    public function test_store_rejects_overtime_inside_work_schedule(): void
    {
        $this->actingAsAdmin();
        // Schedule Mon-Fri 08:00-17:00. 2026-06-08 is a Monday; 09:00-11:00
        // falls inside the jornada → overlap guard fires on start_time.
        $emp = Employee::factory()->create();

        $this->from(route('authorizations.create'))
            ->post(route('authorizations.store'), [
                'employee_id' => $emp->id,
                'type' => Authorization::TYPE_OVERTIME,
                'date' => '2026-06-08',
                'start_time' => '09:00',
                'end_time' => '11:00',
                'hours' => 2,
                'reason' => 'inside schedule',
            ])
            ->assertSessionHasErrors(['start_time']);
    }

    public function test_store_rejects_zero_hour_overtime(): void
    {
        $this->actingAsAdmin();
        $emp = Employee::factory()->create();

        // hours explicitly 0 on a per-hour type → 0-hour guard fires.
        $this->from(route('authorizations.create'))
            ->post(route('authorizations.store'), [
                'employee_id' => $emp->id,
                'type' => Authorization::TYPE_OVERTIME,
                'date' => '2026-06-07',
                'start_time' => '18:00',
                'end_time' => '18:10',
                'hours' => 0,
                'reason' => 'too short',
            ])
            ->assertSessionHasErrors(['hours']);
    }

    public function test_store_forbidden_for_rrhh(): void
    {
        $this->actingAsRrhh();
        $emp = Employee::factory()->create();

        $this->post(route('authorizations.store'), [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_SPECIAL,
            'date' => '2026-06-07',
            'hours' => 1,
            'reason' => 'x',
        ])->assertForbidden();
    }

    public function test_store_redirects_guest_to_login(): void
    {
        $this->post(route('authorizations.store'), [])->assertRedirect(route('login'));
    }

    public function test_store_auto_approves_when_range_matches_detected_overtime(): void
    {
        // Controller autoApproveIfDetected(): a per-hour authorization whose
        // (start, end, hours) exactly match a detected late-exit segment is
        // approved without a second human review.
        $admin = $this->actingAsAdmin();
        // Schedule Mon-Fri 08:00-17:00. Worked 08:00-19:00 on a Monday → the
        // detected late segment is 17:00-19:00, 2h.
        $emp = Employee::factory()->create();
        AttendanceRecord::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => '2026-06-08',
            'check_in' => '08:00:00',
            'check_out' => '19:00:00',
            'overtime_hours' => 2,
        ]);

        // 17:00-19:00 is after the jornada, so the overlap guard does not fire.
        $this->from(route('authorizations.create'))->post(route('authorizations.store'), [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-08',
            'start_time' => '17:00',
            'end_time' => '19:00',
            'hours' => 2,
            'reason' => 'Matches detected overtime',
        ])->assertRedirect(route('authorizations.index'));

        $this->assertDatabaseHas('authorizations', [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'status' => Authorization::STATUS_APPROVED,
            'approved_by' => $admin->id,
        ]);
    }

    /**
     * The velada equivalent: a night_shift authorization whose (start, end, hours)
     * match the velada block detected from raw punches (22:00 → 02:00 = 4h) is
     * auto-approved — i.e. the supervisor loads it from checadas and submits it
     * untouched. Closes the loop on the punch-based velada detection.
     */
    public function test_store_auto_approves_when_range_matches_detected_velada(): void
    {
        $admin = $this->actingAsAdmin();
        $emp = Employee::factory()->create();
        AttendanceRecord::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => '2026-06-08',
            'check_in' => '08:00:00',
            'check_out' => '02:00:00',
            'raw_punches' => [
                ['time' => '08:00:00', 'type' => 'in'],
                ['time' => '17:00:00', 'type' => 'punch'],
                ['time' => '22:00:00', 'type' => 'punch'],
                ['time' => '02:00:00', 'type' => 'out'],
            ],
        ]);

        $this->from(route('authorizations.create'))->post(route('authorizations.store'), [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_NIGHT_SHIFT,
            'date' => '2026-06-08',
            'start_time' => '22:00',
            'end_time' => '02:00',
            'hours' => 4,
            'reason' => 'Matches detected velada',
        ])->assertRedirect(route('authorizations.index'));

        $this->assertDatabaseHas('authorizations', [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_NIGHT_SHIFT,
            'status' => Authorization::STATUS_APPROVED,
            'approved_by' => $admin->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // createBulk / storeBulk
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Auditoría #78/88 + DECISIONES §7: la auto-aprobación debe correr el
     * MISMO pipeline que la aprobación manual — recalcular el attendance
     * (horas autorizadas) e invalidar la nómina del periodo que cubre la
     * fecha. Antes hacía un update() crudo y se saltaba todo.
     */
    public function test_auto_approval_recalculates_attendance_and_invalidates_payroll(): void
    {
        $this->actingAsAdmin();
        $emp = Employee::factory()->create();
        $record = AttendanceRecord::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => '2026-06-08',
            'check_in' => '08:00:00',
            'check_out' => '19:00:00',
            'overtime_hours' => 2,
            'overtime_authorized_hours' => 0,
        ]);

        // Periodo APROBADO que cubre la fecha: debe quedar marcado
        // "requiere recálculo" cuando la auto-aprobación surta efecto.
        $period = PayrollPeriod::factory()->weekly()->create([
            'start_date' => '2026-06-08',
            'end_date' => '2026-06-14',
            'status' => 'approved',
            'requires_recalculation' => false,
        ]);

        $this->from(route('authorizations.create'))->post(route('authorizations.store'), [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_OVERTIME,
            'date' => '2026-06-08',
            'start_time' => '17:00',
            'end_time' => '19:00',
            'hours' => 2,
            'reason' => 'Matches detected overtime',
        ])->assertRedirect(route('authorizations.index'));

        $this->assertSame(Authorization::STATUS_APPROVED, Authorization::first()->status);
        $this->assertEqualsWithDelta(2.0, (float) $record->fresh()->overtime_authorized_hours, 0.01, 'la auto-aprobación recalcula las horas autorizadas del attendance');
        $this->assertTrue((bool) $period->fresh()->requires_recalculation, 'la auto-aprobación invalida la nómina (DECISIONES §7)');
    }

    /**
     * Auditoría #78: la auto-aprobación usa Authorization::approve(), que
     * firma al jefe de departamento cuando viene asignado.
     */
    public function test_auto_approval_signs_department_head(): void
    {
        $this->actingAsAdmin();
        $head = Employee::factory()->create();
        $emp = Employee::factory()->create();
        AttendanceRecord::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => '2026-06-08',
            'check_in' => '08:00:00',
            'check_out' => '19:00:00',
            'overtime_hours' => 2,
        ]);

        $this->from(route('authorizations.createBulk'))->post(route('authorizations.storeBulk'), [
            'type' => Authorization::TYPE_OVERTIME,
            'reason' => 'Bulk overtime detectado',
            'department_head_id' => $head->id,
            'entries' => [[
                'employee_id' => $emp->id,
                'date' => '2026-06-08',
                'start_time' => '17:00',
                'end_time' => '19:00',
                'hours' => 2,
            ]],
        ])->assertRedirect(route('authorizations.index'));

        $auth = Authorization::first();
        $this->assertSame(Authorization::STATUS_APPROVED, $auth->status);
        $this->assertNotNull($auth->department_head_signed_at, 'la auto-aprobación firma al jefe de departamento igual que la manual');
    }

    /**
     * DECISIONES §2 (dedup): re-correr el alta masiva no duplica la
     * autorización viva del mismo (empleado, fecha, tipo, concepto).
     */
    public function test_store_bulk_skips_duplicate_active_authorizations(): void
    {
        $this->actingAsAdmin();
        $emp = Employee::factory()->create();

        Authorization::factory()->create([
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => null,
            'date' => '2026-06-10',
            'status' => Authorization::STATUS_PENDING,
        ]);

        $resp = $this->from(route('authorizations.createBulk'))->post(route('authorizations.storeBulk'), [
            'type' => Authorization::TYPE_SPECIAL,
            'reason' => 'Bulk repetido',
            'employee_ids' => [$emp->id],
            'date' => '2026-06-10',
            'hours' => 1,
        ]);

        $resp->assertRedirect(route('authorizations.index'));
        $this->assertSame(1, Authorization::count(), 'la corrida repetida no crea un duplicado');
        $this->assertStringContainsString('omitidas por duplicado', session('success'));
    }

    public function test_create_bulk_renders_with_all_props(): void
    {
        $this->actingAsAdmin();
        Employee::factory()->create();

        $this->get(route('authorizations.createBulk'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Authorizations/CreateBulk')
                ->has('employees')
                ->has('types')
                ->has('departments')
                ->has('departmentHeads')
                ->has('holidays'));
    }

    public function test_store_bulk_with_employee_ids_creates_one_per_employee(): void
    {
        $this->actingAsAdmin();
        $e1 = Employee::factory()->create();
        $e2 = Employee::factory()->create();

        $resp = $this->from(route('authorizations.createBulk'))->post(route('authorizations.storeBulk'), [
            'type' => Authorization::TYPE_SPECIAL,
            'reason' => 'Bulk special',
            'employee_ids' => [$e1->id, $e2->id],
            'date' => '2026-06-10',
            'hours' => 1,
        ]);

        $resp->assertRedirect(route('authorizations.index'));
        $resp->assertSessionHas('success');
        $this->assertDatabaseHas('authorizations', ['employee_id' => $e1->id, 'is_bulk_generated' => true]);
        $this->assertDatabaseHas('authorizations', ['employee_id' => $e2->id, 'is_bulk_generated' => true]);
        $this->assertEquals(2, Authorization::count());
    }

    public function test_store_bulk_with_entries_creates_one_per_row(): void
    {
        $this->actingAsAdmin();
        $e1 = Employee::factory()->create();
        $e2 = Employee::factory()->create();

        // Overtime on a Sunday so the schedule-overlap conflict check passes.
        $this->from(route('authorizations.createBulk'))->post(route('authorizations.storeBulk'), [
            'type' => Authorization::TYPE_OVERTIME,
            'reason' => 'Bulk overtime rows',
            'entries' => [
                ['employee_id' => $e1->id, 'date' => '2026-06-07', 'start_time' => '18:00', 'end_time' => '20:00', 'hours' => 2],
                ['employee_id' => $e2->id, 'date' => '2026-06-07', 'start_time' => '18:00', 'end_time' => '21:00', 'hours' => 3],
            ],
        ])->assertRedirect(route('authorizations.index'));

        $this->assertEquals(2, Authorization::count());
        $this->assertDatabaseHas('authorizations', ['employee_id' => $e1->id, 'hours' => 2.00]);
        $this->assertDatabaseHas('authorizations', ['employee_id' => $e2->id, 'hours' => 3.00]);
    }

    public function test_store_bulk_validates_required_fields(): void
    {
        $this->actingAsAdmin();

        $this->from(route('authorizations.createBulk'))
            ->post(route('authorizations.storeBulk'), [])
            ->assertSessionHasErrors(['type', 'reason']);
    }

    /**
     * per_day / one_time now fold their range/quantity form and any extra manual
     * rows into a single entries[] payload. Each row (no start/end times, just an
     * explicit quantity in `hours`) becomes one authorization. Mirrors what
     * Create.vue / CreateBulk.vue submit for quantity modes.
     */
    public function test_store_bulk_quantity_entries_create_one_auth_per_row(): void
    {
        $this->actingAsAdmin();
        $emp = Employee::factory()->create();

        $this->from(route('authorizations.createBulk'))->post(route('authorizations.storeBulk'), [
            'type' => Authorization::TYPE_SPECIAL,
            'reason' => 'per_day range + manual rows',
            'entries' => [
                // The "range" row (e.g. a 3-day span) plus two extra manual rows.
                ['employee_id' => $emp->id, 'date' => '2026-06-10', 'hours' => 3],
                ['employee_id' => $emp->id, 'date' => '2026-06-15', 'hours' => 1],
                ['employee_id' => $emp->id, 'date' => '2026-06-20', 'hours' => 2],
            ],
        ])->assertRedirect(route('authorizations.index'));

        $this->assertEquals(3, Authorization::count());
        $this->assertDatabaseHas('authorizations', ['employee_id' => $emp->id, 'date' => '2026-06-10', 'hours' => 3.00, 'start_time' => null]);
        $this->assertDatabaseHas('authorizations', ['employee_id' => $emp->id, 'date' => '2026-06-15', 'hours' => 1.00]);
        $this->assertDatabaseHas('authorizations', ['employee_id' => $emp->id, 'date' => '2026-06-20', 'hours' => 2.00]);
    }

    /**
     * A velada (night_shift) that crosses midnight — start 22:00, end 06:00 with
     * an explicit 8-hour value — must be accepted, not falsely rejected as a
     * schedule conflict or a zero-hour row. This is the backend half of the
     * front-end cross-midnight fix (separate end_date in the row model).
     */
    public function test_store_bulk_velada_crossing_midnight_is_accepted(): void
    {
        $this->actingAsAdmin();
        // Default factory schedule is a weekday 08:00-17:00 day shift; 2026-06-08
        // is a Monday. A 22:00->06:00 range sits entirely outside that jornada.
        $emp = Employee::factory()->create();

        $this->from(route('authorizations.createBulk'))->post(route('authorizations.storeBulk'), [
            'type' => Authorization::TYPE_NIGHT_SHIFT,
            'reason' => 'Velada cruzando medianoche',
            'entries' => [
                ['employee_id' => $emp->id, 'date' => '2026-06-08', 'start_time' => '22:00', 'end_time' => '06:00', 'hours' => 8],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertEquals(1, Authorization::count());
        $this->assertDatabaseHas('authorizations', [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_NIGHT_SHIFT,
            'date' => '2026-06-08',
            'start_time' => '22:00',
            'end_time' => '06:00',
            'hours' => 8.00,
        ]);
    }

    /**
     * Guard the explicit-hours trust path: even though 22:00->06:00 read as a
     * literal same-day H:i diff would be a negative/odd span, the backend stores
     * the caller's explicit hours rather than recomputing a wrong value.
     */
    public function test_store_bulk_velada_trusts_explicit_hours_over_time_diff(): void
    {
        $this->actingAsAdmin();
        $emp = Employee::factory()->create();

        $this->from(route('authorizations.createBulk'))->post(route('authorizations.storeBulk'), [
            'type' => Authorization::TYPE_NIGHT_SHIFT,
            'reason' => 'Velada con horas explícitas',
            'entries' => [
                ['employee_id' => $emp->id, 'date' => '2026-06-08', 'start_time' => '23:30', 'end_time' => '05:30', 'hours' => 6],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('authorizations', [
            'employee_id' => $emp->id,
            'hours' => 6.00,
        ]);
    }

    public function test_store_bulk_rejects_entry_inside_work_schedule(): void
    {
        $this->actingAsAdmin();
        $emp = Employee::factory()->create();

        // Monday inside 08:00-17:00 → conflict on entries.0
        $this->from(route('authorizations.createBulk'))
            ->post(route('authorizations.storeBulk'), [
                'type' => Authorization::TYPE_OVERTIME,
                'reason' => 'conflict',
                'entries' => [
                    ['employee_id' => $emp->id, 'date' => '2026-06-08', 'start_time' => '09:00', 'end_time' => '11:00', 'hours' => 2],
                ],
            ])
            ->assertSessionHasErrors(['entries.0']);
    }

    public function test_store_bulk_forbidden_for_rrhh(): void
    {
        $this->actingAsRrhh();
        $emp = Employee::factory()->create();

        $this->post(route('authorizations.storeBulk'), [
            'type' => Authorization::TYPE_SPECIAL,
            'reason' => 'x',
            'employee_ids' => [$emp->id],
            'date' => '2026-06-10',
            'hours' => 1,
        ])->assertForbidden();
    }

    public function test_create_bulk_forbidden_for_rrhh(): void
    {
        $this->actingAsRrhh();
        $this->get(route('authorizations.createBulk'))->assertForbidden();
    }

    public function test_create_bulk_redirects_guest_to_login(): void
    {
        $this->get(route('authorizations.createBulk'))->assertRedirect(route('login'));
    }

    public function test_store_bulk_redirects_guest_to_login(): void
    {
        $this->post(route('authorizations.storeBulk'), [])->assertRedirect(route('login'));
    }

    public function test_store_bulk_creates_holiday_worked_type(): void
    {
        $this->actingAsAdmin();
        $e1 = Employee::factory()->create();

        // holiday_worked is per-hour but the conflict scan only runs for
        // overtime/night_shift, so a plain employee_ids batch creates rows.
        $this->from(route('authorizations.createBulk'))->post(route('authorizations.storeBulk'), [
            'type' => Authorization::TYPE_HOLIDAY_WORKED,
            'reason' => 'Worked the holiday',
            'employee_ids' => [$e1->id],
            'date' => '2026-06-10',
            'hours' => 8,
        ])->assertRedirect(route('authorizations.index'));

        $this->assertDatabaseHas('authorizations', [
            'employee_id' => $e1->id,
            'type' => Authorization::TYPE_HOLIDAY_WORKED,
            'is_bulk_generated' => true,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // show
    // ─────────────────────────────────────────────────────────────────────

    public function test_show_renders_with_all_props(): void
    {
        $this->actingAsAdmin();
        $auth = $this->pendingOvertime();

        $this->get(route('authorizations.show', $auth))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Authorizations/Show')
                ->has('authorization')
                ->has('can')
                ->has('can.edit')
                ->has('can.delete')
                ->has('can.approve')
                ->has('can.reject'));
    }

    public function test_show_forbidden_for_rrhh(): void
    {
        $this->actingAsRrhh();
        $auth = $this->pendingOvertime();
        $this->get(route('authorizations.show', $auth))->assertForbidden();
    }

    public function test_show_forbidden_for_supervisor_outside_team(): void
    {
        $supUser = $this->supervisorUser();
        $this->attachEmployee($supUser);
        $auth = $this->pendingOvertime(); // stranger employee

        $this->actingAs($supUser)
            ->get(route('authorizations.show', $auth))
            ->assertForbidden();
    }

    public function test_show_can_values_for_admin_pending(): void
    {
        $this->actingAsAdmin();
        $auth = $this->pendingOvertime();

        // Admin: pending → can edit/delete; not own → can approve/reject.
        $this->get(route('authorizations.show', $auth))
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.edit', true)
                ->where('can.delete', true)
                ->where('can.approve', true)
                ->where('can.reject', true));
    }

    public function test_show_can_values_for_supervisor_team_member(): void
    {
        // Supervisor viewing a team member's auth they did NOT create:
        // view passes (team), but update/delete require creator-or-admin and
        // approve/reject require approve/reject perms supervisor lacks.
        $supUser = $this->supervisorUser();
        $supEmp = $this->attachEmployee($supUser);
        $report = Employee::factory()->create(['supervisor_id' => $supEmp->id]);
        $auth = $this->pendingOvertime(['employee_id' => $report->id]);

        $this->actingAs($supUser)
            ->get(route('authorizations.show', $auth))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.edit', false)
                ->where('can.delete', false)
                ->where('can.approve', false)
                ->where('can.reject', false));
    }

    public function test_show_redirects_guest_to_login(): void
    {
        $auth = $this->pendingOvertime();
        $this->get(route('authorizations.show', $auth))->assertRedirect(route('login'));
    }

    // ─────────────────────────────────────────────────────────────────────
    // edit / update
    // ─────────────────────────────────────────────────────────────────────

    public function test_edit_renders_with_all_props_for_pending(): void
    {
        $this->actingAsAdmin();
        $auth = $this->pendingOvertime();

        $this->get(route('authorizations.edit', $auth))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Authorizations/Edit')
                ->has('authorization')
                ->has('employees')
                ->has('types'));
    }

    public function test_edit_forbidden_for_approved_authorization(): void
    {
        // Policy update() denies non-pending → authorize() aborts 403.
        $this->actingAsAdmin();
        $auth = Authorization::factory()->approved()->create([
            'employee_id' => Employee::factory()->create()->id,
            'requested_by' => User::factory()->create()->id,
        ]);

        $this->get(route('authorizations.edit', $auth))->assertForbidden();
    }

    public function test_update_modifies_pending_authorization(): void
    {
        $this->actingAsAdmin();
        $emp = Employee::factory()->create();
        $auth = $this->pendingOvertime(['employee_id' => $emp->id]);

        $this->from(route('authorizations.edit', $auth))->put(route('authorizations.update', $auth), [
            'employee_id' => $emp->id,
            'type' => Authorization::TYPE_SPECIAL,
            'date' => '2026-06-10',
            'hours' => 5,
            'reason' => 'Updated reason',
        ])->assertRedirect(route('authorizations.index'));

        $this->assertDatabaseHas('authorizations', [
            'id' => $auth->id,
            'type' => Authorization::TYPE_SPECIAL,
            'reason' => 'Updated reason',
        ]);
    }

    public function test_update_validates_required_fields(): void
    {
        $this->actingAsAdmin();
        $auth = $this->pendingOvertime();

        $this->from(route('authorizations.edit', $auth))
            ->put(route('authorizations.update', $auth), [])
            ->assertSessionHasErrors(['employee_id', 'type', 'date', 'reason']);
    }

    public function test_update_forbidden_for_rrhh(): void
    {
        $this->actingAsRrhh();
        $auth = $this->pendingOvertime();

        $this->put(route('authorizations.update', $auth), [
            'employee_id' => $auth->employee_id,
            'type' => Authorization::TYPE_SPECIAL,
            'date' => '2026-06-10',
            'hours' => 1,
            'reason' => 'x',
        ])->assertForbidden();
    }

    public function test_edit_forbidden_for_supervisor_not_creator(): void
    {
        // Policy update(): only creator or admin (view_all). A supervisor who
        // did NOT create the (pending) team auth cannot edit it → 403.
        $supUser = $this->supervisorUser();
        $supEmp = $this->attachEmployee($supUser);
        $report = Employee::factory()->create(['supervisor_id' => $supEmp->id]);
        $auth = $this->pendingOvertime(['employee_id' => $report->id]); // requested_by = a stranger

        $this->actingAs($supUser)
            ->get(route('authorizations.edit', $auth))
            ->assertForbidden();
    }

    public function test_edit_allowed_for_supervisor_creator(): void
    {
        // The supervisor IS the creator and the auth is pending → update policy
        // grants access via requested_by === $user->id.
        $supUser = $this->supervisorUser();
        $supEmp = $this->attachEmployee($supUser);
        $report = Employee::factory()->create(['supervisor_id' => $supEmp->id]);
        $auth = $this->pendingOvertime([
            'employee_id' => $report->id,
            'requested_by' => $supUser->id,
        ]);

        $this->actingAs($supUser)
            ->get(route('authorizations.edit', $auth))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Authorizations/Edit'));
    }

    public function test_edit_redirects_guest_to_login(): void
    {
        $auth = $this->pendingOvertime();
        $this->get(route('authorizations.edit', $auth))->assertRedirect(route('login'));
    }

    public function test_update_redirects_guest_to_login(): void
    {
        $auth = $this->pendingOvertime();
        $this->put(route('authorizations.update', $auth), [])->assertRedirect(route('login'));
    }

    public function test_update_rejects_overtime_inside_work_schedule(): void
    {
        $this->actingAsAdmin();
        $emp = Employee::factory()->create();
        $auth = $this->pendingOvertime(['employee_id' => $emp->id]);

        // 2026-06-08 Monday 09:00-11:00 falls inside the 08:00-17:00 jornada.
        $this->from(route('authorizations.edit', $auth))
            ->put(route('authorizations.update', $auth), [
                'employee_id' => $emp->id,
                'type' => Authorization::TYPE_OVERTIME,
                'date' => '2026-06-08',
                'start_time' => '09:00',
                'end_time' => '11:00',
                'hours' => 2,
                'reason' => 'inside schedule',
            ])
            ->assertSessionHasErrors(['start_time']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // destroy
    // ─────────────────────────────────────────────────────────────────────

    public function test_destroy_deletes_pending_authorization(): void
    {
        $this->actingAsAdmin();
        $auth = $this->pendingOvertime();

        $this->delete(route('authorizations.destroy', $auth))
            ->assertRedirect(route('authorizations.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('authorizations', ['id' => $auth->id]);
    }

    public function test_destroy_forbidden_for_approved_authorization(): void
    {
        // Policy delete() denies non-pending → authorize() aborts 403.
        $this->actingAsAdmin();
        $auth = Authorization::factory()->approved()->create([
            'employee_id' => Employee::factory()->create()->id,
            'requested_by' => User::factory()->create()->id,
        ]);

        $this->delete(route('authorizations.destroy', $auth))->assertForbidden();
        $this->assertDatabaseHas('authorizations', ['id' => $auth->id]);
    }

    public function test_destroy_forbidden_for_rrhh(): void
    {
        $this->actingAsRrhh();
        $auth = $this->pendingOvertime();
        $this->delete(route('authorizations.destroy', $auth))->assertForbidden();
    }

    public function test_destroy_redirects_guest_to_login(): void
    {
        $auth = $this->pendingOvertime();
        $this->delete(route('authorizations.destroy', $auth))->assertRedirect(route('login'));
    }

    public function test_destroy_forbidden_for_supervisor_not_creator(): void
    {
        // delete policy mirrors update: creator-or-admin only.
        $supUser = $this->supervisorUser();
        $supEmp = $this->attachEmployee($supUser);
        $report = Employee::factory()->create(['supervisor_id' => $supEmp->id]);
        $auth = $this->pendingOvertime(['employee_id' => $report->id]); // stranger requested_by

        $this->actingAs($supUser)
            ->delete(route('authorizations.destroy', $auth))
            ->assertForbidden();
        $this->assertDatabaseHas('authorizations', ['id' => $auth->id]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // approve (2FA-guarded)
    // ─────────────────────────────────────────────────────────────────────

    public function test_approve_sets_approved_by_and_status(): void
    {
        [$admin, $code] = $this->privilegedWithTwoFactor();
        $this->actingAs($admin);
        $auth = $this->pendingOvertime();

        $this->from(route('authorizations.index'))
            ->post(route('authorizations.approve', $auth), ['two_factor_code' => $code])
            ->assertRedirect(route('authorizations.index'))
            ->assertSessionHas('success');

        $auth->refresh();
        $this->assertEquals(Authorization::STATUS_APPROVED, $auth->status);
        $this->assertEquals($admin->id, $auth->approved_by);
        $this->assertNotNull($auth->approved_at);
    }

    public function test_approve_with_partial_hours_override(): void
    {
        [$admin, $code] = $this->privilegedWithTwoFactor();
        $this->actingAs($admin);
        $auth = $this->pendingOvertime(['hours' => 5]);

        $this->from(route('authorizations.index'))
            ->post(route('authorizations.approve', $auth), ['two_factor_code' => $code, 'hours' => 3])
            ->assertRedirect(route('authorizations.index'));

        $auth->refresh();
        $this->assertEquals(Authorization::STATUS_APPROVED, $auth->status);
        $this->assertEquals('3.00', (string) $auth->hours);
    }

    public function test_approve_requires_two_factor_code(): void
    {
        // The harness admin device has a confirmed 2FA device → code required.
        $this->actingAsAdmin();
        $auth = $this->pendingOvertime();

        $this->from(route('authorizations.index'))
            ->post(route('authorizations.approve', $auth))
            ->assertSessionHasErrors(['two_factor_code']);

        $this->assertEquals(Authorization::STATUS_PENDING, $auth->fresh()->status);
    }

    public function test_approve_forbidden_for_rrhh(): void
    {
        // rrhh lacks authorizations.approve → policy denies → 403.
        $this->actingAsRrhh();
        $auth = $this->pendingOvertime();
        $this->post(route('authorizations.approve', $auth))->assertForbidden();
    }

    public function test_supervisor_cannot_approve_team_authorization(): void
    {
        // supervisor has view_team+create but NO authorizations.approve.
        $supUser = $this->supervisorUser();
        $supEmp = $this->attachEmployee($supUser);
        $report = Employee::factory()->create(['supervisor_id' => $supEmp->id]);
        $auth = $this->pendingOvertime(['employee_id' => $report->id]);

        $this->actingAs($supUser)
            ->post(route('authorizations.approve', $auth))
            ->assertForbidden();
    }

    public function test_admin_cannot_approve_already_paid(): void
    {
        [$admin, $code] = $this->privilegedWithTwoFactor();
        $this->actingAs($admin);
        $auth = Authorization::factory()->paid()->create([
            'employee_id' => Employee::factory()->create()->id,
            'requested_by' => User::factory()->create()->id,
        ]);

        // Policy approve(): full-access can act unless paid → paid is denied 403.
        $this->post(route('authorizations.approve', $auth), ['two_factor_code' => $code])
            ->assertForbidden();
    }

    public function test_admin_can_approve_own_authorization(): void
    {
        // Policy: can't approve own UNLESS admin. The admin's own auth approves.
        [$admin, $code] = $this->privilegedWithTwoFactor();
        $adminEmp = $this->attachEmployee($admin);
        $this->actingAs($admin);
        $auth = $this->pendingOvertime(['employee_id' => $adminEmp->id]);

        $this->from(route('authorizations.index'))
            ->post(route('authorizations.approve', $auth), ['two_factor_code' => $code])
            ->assertRedirect(route('authorizations.index'))
            ->assertSessionHas('success');

        $this->assertEquals(Authorization::STATUS_APPROVED, $auth->fresh()->status);
    }

    public function test_admin_can_modify_existing_approval(): void
    {
        // Policy approve(): full-access can re-approve a non-paid auth (post-hoc
        // partial adjustment). An already-approved auth re-approves with new hours.
        [$admin, $code] = $this->privilegedWithTwoFactor();
        $this->actingAs($admin);
        $auth = Authorization::factory()->approved()->create([
            'employee_id' => Employee::factory()->create()->id,
            'requested_by' => User::factory()->create()->id,
            'hours' => 5,
        ]);

        $this->from(route('authorizations.index'))
            ->post(route('authorizations.approve', $auth), ['two_factor_code' => $code, 'hours' => 2])
            ->assertRedirect(route('authorizations.index'))
            ->assertSessionHas('success');

        $auth->refresh();
        $this->assertEquals(Authorization::STATUS_APPROVED, $auth->status);
        $this->assertEquals('2.00', (string) $auth->hours);
    }

    public function test_approve_redirects_guest_to_login(): void
    {
        $auth = $this->pendingOvertime();
        $this->post(route('authorizations.approve', $auth))->assertRedirect(route('login'));
    }

    // ─────────────────────────────────────────────────────────────────────
    // reject (2FA-guarded)
    // ─────────────────────────────────────────────────────────────────────

    public function test_reject_sets_status_and_reason(): void
    {
        [$admin, $code] = $this->privilegedWithTwoFactor();
        $this->actingAs($admin);
        $auth = $this->pendingOvertime();

        $this->from(route('authorizations.index'))
            ->post(route('authorizations.reject', $auth), [
                'two_factor_code' => $code,
                'rejection_reason' => 'Not justified',
            ])
            ->assertRedirect(route('authorizations.index'))
            ->assertSessionHas('success');

        $auth->refresh();
        $this->assertEquals(Authorization::STATUS_REJECTED, $auth->status);
        $this->assertEquals('Not justified', $auth->rejection_reason);
    }

    public function test_reject_requires_reason(): void
    {
        [$admin, $code] = $this->privilegedWithTwoFactor();
        $this->actingAs($admin);
        $auth = $this->pendingOvertime();

        $this->from(route('authorizations.index'))
            ->post(route('authorizations.reject', $auth), ['two_factor_code' => $code])
            ->assertSessionHasErrors(['rejection_reason']);
    }

    public function test_reject_already_processed_returns_error(): void
    {
        [$admin, $code] = $this->privilegedWithTwoFactor();
        $this->actingAs($admin);
        $auth = Authorization::factory()->approved()->create([
            'employee_id' => Employee::factory()->create()->id,
            'requested_by' => User::factory()->create()->id,
        ]);

        // Policy reject() requires pending → already-approved denies at authorize → 403.
        $this->post(route('authorizations.reject', $auth), [
            'two_factor_code' => $code,
            'rejection_reason' => 'late',
        ])->assertForbidden();
    }

    public function test_reject_forbidden_for_rrhh(): void
    {
        $this->actingAsRrhh();
        $auth = $this->pendingOvertime();
        $this->post(route('authorizations.reject', $auth), ['rejection_reason' => 'x'])
            ->assertForbidden();
    }

    public function test_reject_requires_two_factor_code(): void
    {
        // Harness admin has a confirmed device → code required before reason.
        $this->actingAsAdmin();
        $auth = $this->pendingOvertime();

        $this->from(route('authorizations.index'))
            ->post(route('authorizations.reject', $auth), ['rejection_reason' => 'Not justified'])
            ->assertSessionHasErrors(['two_factor_code']);

        $this->assertEquals(Authorization::STATUS_PENDING, $auth->fresh()->status);
    }

    public function test_reject_redirects_guest_to_login(): void
    {
        $auth = $this->pendingOvertime();
        $this->post(route('authorizations.reject', $auth), ['rejection_reason' => 'x'])
            ->assertRedirect(route('login'));
    }

    public function test_supervisor_cannot_reject_team_authorization(): void
    {
        // supervisor lacks authorizations.reject → policy denies → 403.
        $supUser = $this->supervisorUser();
        $supEmp = $this->attachEmployee($supUser);
        $report = Employee::factory()->create(['supervisor_id' => $supEmp->id]);
        $auth = $this->pendingOvertime(['employee_id' => $report->id]);

        $this->actingAs($supUser)
            ->post(route('authorizations.reject', $auth), ['rejection_reason' => 'x'])
            ->assertForbidden();
    }

    // ─────────────────────────────────────────────────────────────────────
    // markPaid (2FA-guarded)
    // ─────────────────────────────────────────────────────────────────────

    public function test_mark_paid_transitions_approved_to_paid(): void
    {
        [$admin, $code] = $this->privilegedWithTwoFactor();
        $this->actingAs($admin);
        $auth = Authorization::factory()->approved()->create([
            'employee_id' => Employee::factory()->create()->id,
            'requested_by' => User::factory()->create()->id,
        ]);

        $this->from(route('authorizations.index'))
            ->post(route('authorizations.markPaid', $auth), ['two_factor_code' => $code])
            ->assertRedirect(route('authorizations.index'))
            ->assertSessionHas('success');

        $this->assertEquals(Authorization::STATUS_PAID, $auth->fresh()->status);
    }

    public function test_mark_paid_rejects_pending_authorization(): void
    {
        [$admin, $code] = $this->privilegedWithTwoFactor();
        $this->actingAs($admin);
        $auth = $this->pendingOvertime();

        // Controller: only approved can be marked paid → error flash, no change.
        $this->from(route('authorizations.index'))
            ->post(route('authorizations.markPaid', $auth), ['two_factor_code' => $code])
            ->assertRedirect(route('authorizations.index'))
            ->assertSessionHas('error');

        $this->assertEquals(Authorization::STATUS_PENDING, $auth->fresh()->status);
    }

    public function test_mark_paid_rejects_already_paid_authorization(): void
    {
        [$admin, $code] = $this->privilegedWithTwoFactor();
        $this->actingAs($admin);
        $auth = Authorization::factory()->paid()->create([
            'employee_id' => Employee::factory()->create()->id,
            'requested_by' => User::factory()->create()->id,
        ]);

        // markPaid authorizes via approve policy; paid is locked → 403.
        $this->post(route('authorizations.markPaid', $auth), ['two_factor_code' => $code])
            ->assertForbidden();
    }

    public function test_mark_paid_forbidden_for_rrhh(): void
    {
        $this->actingAsRrhh();
        $auth = Authorization::factory()->approved()->create([
            'employee_id' => Employee::factory()->create()->id,
            'requested_by' => User::factory()->create()->id,
        ]);
        $this->post(route('authorizations.markPaid', $auth))->assertForbidden();
    }

    public function test_mark_paid_requires_two_factor_code(): void
    {
        // approve policy passes for harness admin, then 2FA gate fires.
        $this->actingAsAdmin();
        $auth = Authorization::factory()->approved()->create([
            'employee_id' => Employee::factory()->create()->id,
            'requested_by' => User::factory()->create()->id,
        ]);

        $this->from(route('authorizations.index'))
            ->post(route('authorizations.markPaid', $auth))
            ->assertSessionHasErrors(['two_factor_code']);

        $this->assertEquals(Authorization::STATUS_APPROVED, $auth->fresh()->status);
    }

    public function test_mark_paid_redirects_guest_to_login(): void
    {
        $auth = Authorization::factory()->approved()->create([
            'employee_id' => Employee::factory()->create()->id,
            'requested_by' => User::factory()->create()->id,
        ]);
        $this->post(route('authorizations.markPaid', $auth))->assertRedirect(route('login'));
    }

    // ─────────────────────────────────────────────────────────────────────
    // bulkApprove / bulkReject (2FA-guarded)
    // ─────────────────────────────────────────────────────────────────────

    public function test_bulk_approve_approves_pending_ids(): void
    {
        [$admin, $code] = $this->privilegedWithTwoFactor();
        $this->actingAs($admin);
        $a1 = $this->pendingOvertime();
        $a2 = $this->pendingOvertime();

        $this->from(route('authorizations.index'))
            ->post(route('authorizations.bulkApprove'), [
                'two_factor_code' => $code,
                'ids' => [$a1->id, $a2->id],
            ])
            ->assertRedirect(route('authorizations.index'))
            ->assertSessionHas('success');

        $this->assertEquals(Authorization::STATUS_APPROVED, $a1->fresh()->status);
        $this->assertEquals(Authorization::STATUS_APPROVED, $a2->fresh()->status);
    }

    public function test_bulk_approve_skips_non_pending(): void
    {
        [$admin, $code] = $this->privilegedWithTwoFactor();
        $this->actingAs($admin);
        $pending = $this->pendingOvertime();
        $alreadyPaid = Authorization::factory()->paid()->create([
            'employee_id' => Employee::factory()->create()->id,
            'requested_by' => User::factory()->create()->id,
        ]);

        $this->from(route('authorizations.index'))
            ->post(route('authorizations.bulkApprove'), [
                'two_factor_code' => $code,
                'ids' => [$pending->id, $alreadyPaid->id],
            ])
            ->assertRedirect(route('authorizations.index'));

        $this->assertEquals(Authorization::STATUS_APPROVED, $pending->fresh()->status);
        $this->assertEquals(Authorization::STATUS_PAID, $alreadyPaid->fresh()->status);
    }

    public function test_bulk_approve_validates_ids_required(): void
    {
        [$admin, $code] = $this->privilegedWithTwoFactor();
        $this->actingAs($admin);

        $this->from(route('authorizations.index'))
            ->post(route('authorizations.bulkApprove'), ['two_factor_code' => $code, 'ids' => []])
            ->assertSessionHasErrors(['ids']);
    }

    public function test_bulk_reject_rejects_pending_ids(): void
    {
        [$admin, $code] = $this->privilegedWithTwoFactor();
        $this->actingAs($admin);
        $a1 = $this->pendingOvertime();
        $a2 = $this->pendingOvertime();

        $this->from(route('authorizations.index'))
            ->post(route('authorizations.bulkReject'), [
                'two_factor_code' => $code,
                'ids' => [$a1->id, $a2->id],
                'rejection_reason' => 'Batch rejected',
            ])
            ->assertRedirect(route('authorizations.index'))
            ->assertSessionHas('success');

        $this->assertEquals(Authorization::STATUS_REJECTED, $a1->fresh()->status);
        $this->assertEquals(Authorization::STATUS_REJECTED, $a2->fresh()->status);
    }

    public function test_bulk_reject_requires_reason(): void
    {
        [$admin, $code] = $this->privilegedWithTwoFactor();
        $this->actingAs($admin);
        $a1 = $this->pendingOvertime();

        $this->from(route('authorizations.index'))
            ->post(route('authorizations.bulkReject'), [
                'two_factor_code' => $code,
                'ids' => [$a1->id],
            ])
            ->assertSessionHasErrors(['rejection_reason']);
    }

    public function test_bulk_approve_requires_two_factor_code(): void
    {
        // bulkApprove() verifies 2FA before anything else; harness admin has a
        // confirmed device → missing code errors and nothing is approved.
        $this->actingAsAdmin();
        $a1 = $this->pendingOvertime();

        $this->from(route('authorizations.index'))
            ->post(route('authorizations.bulkApprove'), ['ids' => [$a1->id]])
            ->assertSessionHasErrors(['two_factor_code']);

        $this->assertEquals(Authorization::STATUS_PENDING, $a1->fresh()->status);
    }

    public function test_bulk_approve_redirects_guest_to_login(): void
    {
        $this->post(route('authorizations.bulkApprove'), [])->assertRedirect(route('login'));
    }

    public function test_bulk_reject_redirects_guest_to_login(): void
    {
        $this->post(route('authorizations.bulkReject'), [])->assertRedirect(route('login'));
    }

    public function test_bulk_approve_skips_all_for_user_without_approve_permission(): void
    {
        // bulkApprove() has NO controller-level authorize() — it relies on the
        // per-row policy. rrhh has a valid 2FA code here but lacks
        // authorizations.approve, so every row is skipped (none approved) and
        // the request still succeeds with a redirect (no authorization leak).
        [$rrhh, $code] = $this->privilegedWithTwoFactor('rrhh');
        $this->actingAs($rrhh);
        $a1 = $this->pendingOvertime();
        $a2 = $this->pendingOvertime();

        $this->from(route('authorizations.index'))
            ->post(route('authorizations.bulkApprove'), [
                'two_factor_code' => $code,
                'ids' => [$a1->id, $a2->id],
            ])
            ->assertRedirect(route('authorizations.index'))
            ->assertSessionHas('success');

        $this->assertEquals(Authorization::STATUS_PENDING, $a1->fresh()->status);
        $this->assertEquals(Authorization::STATUS_PENDING, $a2->fresh()->status);
    }

    public function test_bulk_reject_skips_all_for_user_without_reject_permission(): void
    {
        // Same as above for bulkReject: rrhh lacks authorizations.reject → every
        // row skipped, statuses unchanged, request still redirects with success.
        [$rrhh, $code] = $this->privilegedWithTwoFactor('rrhh');
        $this->actingAs($rrhh);
        $a1 = $this->pendingOvertime();

        $this->from(route('authorizations.index'))
            ->post(route('authorizations.bulkReject'), [
                'two_factor_code' => $code,
                'ids' => [$a1->id],
                'rejection_reason' => 'batch',
            ])
            ->assertRedirect(route('authorizations.index'))
            ->assertSessionHas('success');

        $this->assertEquals(Authorization::STATUS_PENDING, $a1->fresh()->status);
    }

    public function test_bulk_approve_validates_ids_exist(): void
    {
        [$admin, $code] = $this->privilegedWithTwoFactor();
        $this->actingAs($admin);

        $this->from(route('authorizations.index'))
            ->post(route('authorizations.bulkApprove'), [
                'two_factor_code' => $code,
                'ids' => [999999],
            ])
            ->assertSessionHasErrors(['ids.0']);
    }

    public function test_bulk_reject_validates_ids_required(): void
    {
        [$admin, $code] = $this->privilegedWithTwoFactor();
        $this->actingAs($admin);

        $this->from(route('authorizations.index'))
            ->post(route('authorizations.bulkReject'), [
                'two_factor_code' => $code,
                'ids' => [],
                'rejection_reason' => 'x',
            ])
            ->assertSessionHasErrors(['ids']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // suggest (GET, JSON)
    // ─────────────────────────────────────────────────────────────────────

    public function test_suggest_returns_not_found_without_records(): void
    {
        $this->actingAsAdmin();
        $emp = Employee::factory()->create();

        $this->getJson(route('authorizations.suggest', [
            'employee_id' => $emp->id,
            'date' => '2026-06-07',
            'type' => Authorization::TYPE_OVERTIME,
        ]))
            ->assertOk()
            ->assertJson(['found' => false]);
    }

    public function test_suggest_detects_late_exit_overtime(): void
    {
        $this->actingAsAdmin();
        // Schedule Mon-Fri 08:00-17:00. Worked 08:00-19:00 on a Monday → 2h late OT.
        $emp = Employee::factory()->create();
        AttendanceRecord::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => '2026-06-08',
            'check_in' => '08:00:00',
            'check_out' => '19:00:00',
            'overtime_hours' => 2,
        ]);

        $this->getJson(route('authorizations.suggest', [
            'employee_id' => $emp->id,
            'date' => '2026-06-08',
            'type' => Authorization::TYPE_OVERTIME,
        ]))
            ->assertOk()
            ->assertJson(['found' => true]);
    }

    public function test_suggest_validates_required_params(): void
    {
        $this->actingAsAdmin();

        $this->getJson(route('authorizations.suggest'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['employee_id', 'date', 'type']);
    }

    public function test_suggest_forbidden_for_rrhh(): void
    {
        // suggest authorizes 'create' on Authorization → rrhh lacks it → 403.
        $this->actingAsRrhh();
        $emp = Employee::factory()->create();

        $this->getJson(route('authorizations.suggest', [
            'employee_id' => $emp->id,
            'date' => '2026-06-07',
            'type' => Authorization::TYPE_OVERTIME,
        ]))->assertForbidden();
    }

    public function test_suggest_rejects_non_pull_type(): void
    {
        // suggest only accepts overtime/night_shift → 'special' fails validation.
        $this->actingAsAdmin();
        $emp = Employee::factory()->create();

        $this->getJson(route('authorizations.suggest', [
            'employee_id' => $emp->id,
            'date' => '2026-06-07',
            'type' => Authorization::TYPE_SPECIAL,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_suggest_returns_not_found_for_velada_without_velada_hours(): void
    {
        $this->actingAsAdmin();
        $emp = Employee::factory()->create();
        AttendanceRecord::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => '2026-06-08',
            'check_in' => '08:00:00',
            'check_out' => '17:00:00',
            'velada_hours' => 0,
        ]);

        $this->getJson(route('authorizations.suggest', [
            'employee_id' => $emp->id,
            'date' => '2026-06-08',
            'type' => Authorization::TYPE_NIGHT_SHIFT,
        ]))
            ->assertOk()
            ->assertJson(['found' => false]);
    }

    public function test_suggest_scoped_403_for_supervisor_outside_team(): void
    {
        // suggest mirrors create() scoping: a supervisor querying an employee
        // outside their team gets a JSON 403 with found=false.
        $supUser = $this->supervisorUser();
        $this->attachEmployee($supUser);
        $stranger = Employee::factory()->create();

        $this->actingAs($supUser)
            ->getJson(route('authorizations.suggest', [
                'employee_id' => $stranger->id,
                'date' => '2026-06-08',
                'type' => Authorization::TYPE_OVERTIME,
            ]))
            ->assertStatus(403)
            ->assertJson(['found' => false]);
    }

    public function test_suggest_redirects_guest_to_login(): void
    {
        $emp = Employee::factory()->create();
        // A plain (non-JSON) GET from a guest hits auth middleware → redirect.
        $this->get(route('authorizations.suggest', [
            'employee_id' => $emp->id,
            'date' => '2026-06-07',
            'type' => Authorization::TYPE_OVERTIME,
        ]))->assertRedirect(route('login'));
    }

    // ─────────────────────────────────────────────────────────────────────
    // suggestBulk (GET, JSON)
    // ─────────────────────────────────────────────────────────────────────

    public function test_suggest_bulk_returns_suggestions_shape(): void
    {
        $this->actingAsAdmin();
        $emp = Employee::factory()->create();
        AttendanceRecord::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => '2026-06-08',
            'check_in' => '08:00:00',
            'check_out' => '19:00:00',
            'overtime_hours' => 2,
        ]);

        $this->getJson(route('authorizations.suggestBulk', [
            'employee_ids' => [$emp->id],
            'start_date' => '2026-06-08',
            'end_date' => '2026-06-08',
            'type' => Authorization::TYPE_OVERTIME,
        ]))
            ->assertOk()
            ->assertJsonStructure(['suggestions', 'eligible_count', 'eligible_employee_count', 'skipped_count']);
    }

    public function test_suggest_bulk_rejects_non_pull_type(): void
    {
        $this->actingAsAdmin();
        $emp = Employee::factory()->create();

        // 'special' without a pull-rule compensation type → 422.
        $this->getJson(route('authorizations.suggestBulk', [
            'employee_ids' => [$emp->id],
            'start_date' => '2026-06-08',
            'end_date' => '2026-06-08',
            'type' => Authorization::TYPE_SPECIAL,
        ]))->assertStatus(422);
    }

    public function test_suggest_bulk_rejects_range_over_31_days(): void
    {
        $this->actingAsAdmin();
        $emp = Employee::factory()->create();

        $this->getJson(route('authorizations.suggestBulk', [
            'employee_ids' => [$emp->id],
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-01',
            'type' => Authorization::TYPE_OVERTIME,
        ]))->assertStatus(422);
    }

    public function test_suggest_bulk_validates_required_params(): void
    {
        $this->actingAsAdmin();

        $this->getJson(route('authorizations.suggestBulk'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['employee_ids', 'start_date', 'end_date', 'type']);
    }

    public function test_suggest_bulk_forbidden_for_rrhh(): void
    {
        $this->actingAsRrhh();
        $emp = Employee::factory()->create();

        $this->getJson(route('authorizations.suggestBulk', [
            'employee_ids' => [$emp->id],
            'start_date' => '2026-06-08',
            'end_date' => '2026-06-08',
            'type' => Authorization::TYPE_OVERTIME,
        ]))->assertForbidden();
    }

    public function test_suggest_bulk_rejects_end_before_start(): void
    {
        // after_or_equal:start_date validation rule on end_date.
        $this->actingAsAdmin();
        $emp = Employee::factory()->create();

        $this->getJson(route('authorizations.suggestBulk', [
            'employee_ids' => [$emp->id],
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-08',
            'type' => Authorization::TYPE_OVERTIME,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['end_date']);
    }

    public function test_suggest_bulk_excludes_employee_outside_supervisor_team(): void
    {
        // suggestBulk scopes by team: a stranger employee yields no rows and
        // is counted as skipped, never leaking their attendance segments.
        $supUser = $this->supervisorUser();
        $this->attachEmployee($supUser);
        $stranger = Employee::factory()->create();
        AttendanceRecord::factory()->create([
            'employee_id' => $stranger->id,
            'work_date' => '2026-06-08',
            'check_in' => '08:00:00',
            'check_out' => '19:00:00',
            'overtime_hours' => 2,
        ]);

        $this->actingAs($supUser)
            ->getJson(route('authorizations.suggestBulk', [
                'employee_ids' => [$stranger->id],
                'start_date' => '2026-06-08',
                'end_date' => '2026-06-08',
                'type' => Authorization::TYPE_OVERTIME,
            ]))
            ->assertOk()
            ->assertJson(['eligible_count' => 0, 'eligible_employee_count' => 0]);
    }

    public function test_suggest_bulk_redirects_guest_to_login(): void
    {
        $emp = Employee::factory()->create();
        $this->get(route('authorizations.suggestBulk', [
            'employee_ids' => [$emp->id],
            'start_date' => '2026-06-08',
            'end_date' => '2026-06-08',
            'type' => Authorization::TYPE_OVERTIME,
        ]))->assertRedirect(route('login'));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Velada pull-from-checadas (raw_punches → real night block)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * A velada is pulled as the REAL night block from the day's punches: the
     * employee worked their normal shift, clocked out, then clocked back in for
     * the velada and out after midnight. The suggestion must be the actual velada
     * entry→exit (22:00 → 02:00 = 4h), not a synthetic range back-extended from
     * velada_hours. Drives "Cargar desde checadas" for night_shift in both forms.
     */
    public function test_suggest_bulk_velada_pulls_real_block_from_punches(): void
    {
        $this->actingAsAdmin();
        $emp = Employee::factory()->create();
        AttendanceRecord::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => '2026-06-08',
            // check_out is the post-midnight velada exit (time-only, wraps past 00:00).
            'check_in' => '08:00:00',
            'check_out' => '02:00:00',
            // Velada split would be irrelevant here — the block comes from punches.
            'velada_hours' => 0,
            'raw_punches' => [
                ['time' => '08:00:00', 'type' => 'in'],
                ['time' => '17:00:00', 'type' => 'punch'],   // salida del turno normal
                ['time' => '22:00:00', 'type' => 'punch'],   // entrada de la velada
                ['time' => '02:00:00', 'type' => 'out'],     // salida de la velada (+1 día)
            ],
        ]);

        $this->getJson(route('authorizations.suggestBulk', [
            'employee_ids' => [$emp->id],
            'start_date' => '2026-06-08',
            'end_date' => '2026-06-08',
            'type' => Authorization::TYPE_NIGHT_SHIFT,
        ]))
            ->assertOk()
            ->assertJsonPath('suggestions.0.kind', 'velada')
            ->assertJsonPath('suggestions.0.start_time', '22:00')
            ->assertJsonPath('suggestions.0.end_time', '02:00')
            ->assertJsonPath('suggestions.0.hours', '4.00');
    }

    /**
     * Same real-block detection through the single-employee `suggest` endpoint,
     * so the Create.vue velada form (Image #3) gets the actual entry/exit too.
     */
    public function test_suggest_velada_pulls_real_block_from_punches(): void
    {
        $this->actingAsAdmin();
        $emp = Employee::factory()->create();
        AttendanceRecord::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => '2026-06-08',
            'check_in' => '08:00:00',
            'check_out' => '02:00:00',
            'velada_hours' => 0,
            'raw_punches' => [
                ['time' => '08:00:00', 'type' => 'in'],
                ['time' => '17:00:00', 'type' => 'punch'],
                ['time' => '22:00:00', 'type' => 'punch'],
                ['time' => '02:00:00', 'type' => 'out'],
            ],
        ]);

        $this->getJson(route('authorizations.suggest', [
            'employee_id' => $emp->id,
            'date' => '2026-06-08',
            'type' => Authorization::TYPE_NIGHT_SHIFT,
        ]))
            ->assertOk()
            ->assertJson([
                'found' => true,
                'kind' => 'velada',
                'start_time' => '22:00',
                'end_time' => '02:00',
                'hours' => '4.00',
            ]);
    }

    /**
     * Lunch punches sit inside the normal shift and must not be mistaken for the
     * velada boundary. A full day [in, lunch_out, lunch_in, normal_out, velada_in,
     * velada_out] still resolves the velada as the last real block (23:00 → 03:30).
     */
    public function test_suggest_bulk_velada_ignores_lunch_punches(): void
    {
        $this->actingAsAdmin();
        $emp = Employee::factory()->create();
        AttendanceRecord::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => '2026-06-08',
            'check_in' => '08:00:00',
            'check_out' => '03:30:00',
            'raw_punches' => [
                ['time' => '08:00:00', 'type' => 'in'],
                ['time' => '13:00:00', 'type' => 'lunch_out'],
                ['time' => '14:00:00', 'type' => 'lunch_in'],
                ['time' => '18:00:00', 'type' => 'punch'],
                ['time' => '23:00:00', 'type' => 'punch'],
                ['time' => '03:30:00', 'type' => 'out'],
            ],
        ]);

        $this->getJson(route('authorizations.suggestBulk', [
            'employee_ids' => [$emp->id],
            'start_date' => '2026-06-08',
            'end_date' => '2026-06-08',
            'type' => Authorization::TYPE_NIGHT_SHIFT,
        ]))
            ->assertOk()
            ->assertJsonPath('suggestions.0.start_time', '23:00')
            ->assertJsonPath('suggestions.0.end_time', '03:30')
            ->assertJsonPath('suggestions.0.hours', '4.50');
    }

    /**
     * A plain day shift with punches (in → out, no night re-entry) is NOT a velada,
     * even though raw_punches are present: the last block must land in the velada
     * window or cross midnight to qualify.
     */
    public function test_suggest_bulk_velada_skips_normal_day_with_punches(): void
    {
        $this->actingAsAdmin();
        $emp = Employee::factory()->create();
        AttendanceRecord::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => '2026-06-08',
            'check_in' => '08:00:00',
            'check_out' => '17:00:00',
            'velada_hours' => 0,
            'raw_punches' => [
                ['time' => '08:00:00', 'type' => 'in'],
                ['time' => '17:00:00', 'type' => 'out'],
            ],
        ]);

        $this->getJson(route('authorizations.suggestBulk', [
            'employee_ids' => [$emp->id],
            'start_date' => '2026-06-08',
            'end_date' => '2026-06-08',
            'type' => Authorization::TYPE_NIGHT_SHIFT,
        ]))
            ->assertOk()
            ->assertJson(['eligible_count' => 0]);
    }

    /**
     * Legacy records synced before raw_punches existed still work: with no punches
     * the detector falls back to back-extending the velada block from the stored
     * velada_hours (here 3h, from a 06:00 check_out → 03:00 start).
     */
    public function test_suggest_bulk_velada_falls_back_to_velada_hours_without_punches(): void
    {
        $this->actingAsAdmin();
        $emp = Employee::factory()->create();
        AttendanceRecord::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => '2026-06-08',
            'check_in' => '20:00:00',
            'check_out' => '06:00:00',
            'velada_hours' => 3,
            'raw_punches' => null,
        ]);

        $this->getJson(route('authorizations.suggestBulk', [
            'employee_ids' => [$emp->id],
            'start_date' => '2026-06-08',
            'end_date' => '2026-06-08',
            'type' => Authorization::TYPE_NIGHT_SHIFT,
        ]))
            ->assertOk()
            ->assertJsonPath('suggestions.0.kind', 'velada')
            ->assertJsonPath('suggestions.0.end_time', '06:00')
            ->assertJsonPath('suggestions.0.hours', '3.00');
    }

    /**
     * Guard against a phantom 24-hour velada: two equal-timestamp boundary punches
     * (a duplicate that slipped through) must be treated as a zero-length block and
     * fall back, NOT computed as a full day that could auto-approve into payroll.
     */
    public function test_suggest_bulk_velada_duplicate_punch_does_not_phantom_24h(): void
    {
        $this->actingAsAdmin();
        $emp = Employee::factory()->create();
        AttendanceRecord::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => '2026-06-08',
            'check_in' => '08:00:00',
            'check_out' => '22:00:00',
            'velada_hours' => 0, // no fallback hours either → no suggestion at all
            'raw_punches' => [
                ['time' => '08:00:00', 'type' => 'in'],
                ['time' => '22:00:00', 'type' => 'punch'],   // same minute as the last
                ['time' => '22:00:00', 'type' => 'out'],     // duplicate boundary punch
            ],
        ]);

        $this->getJson(route('authorizations.suggestBulk', [
            'employee_ids' => [$emp->id],
            'start_date' => '2026-06-08',
            'end_date' => '2026-06-08',
            'type' => Authorization::TYPE_NIGHT_SHIFT,
        ]))
            ->assertOk()
            ->assertJson(['eligible_count' => 0]);
    }

    /**
     * A single boundary punch left after filtering lunch can't bound a velada;
     * the detector must fall back rather than index a missing entry.
     */
    public function test_suggest_bulk_velada_single_boundary_punch_falls_back(): void
    {
        $this->actingAsAdmin();
        $emp = Employee::factory()->create();
        AttendanceRecord::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => '2026-06-08',
            'check_in' => '13:00:00',
            'check_out' => '14:00:00',
            'velada_hours' => 0,
            'raw_punches' => [
                ['time' => '13:00:00', 'type' => 'lunch_out'],
                ['time' => '14:00:00', 'type' => 'lunch_in'],
            ],
        ]);

        $this->getJson(route('authorizations.suggestBulk', [
            'employee_ids' => [$emp->id],
            'start_date' => '2026-06-08',
            'end_date' => '2026-06-08',
            'type' => Authorization::TYPE_NIGHT_SHIFT,
        ]))
            ->assertOk()
            ->assertJson(['eligible_count' => 0]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Comida pull-from-checadas (lunch given only for weekend work)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * A "Comida" compensation type (attendance_pull_rule = comida) pulls one
     * per-day lunch from checadas for each weekend day the employee worked
     * (is_weekend_work), modeled on the Cena rule but weekend-only.
     */
    public function test_suggest_bulk_comida_pulls_weekend_worked_day(): void
    {
        $this->actingAsAdmin();
        $comida = CompensationType::factory()->create([
            'application_mode' => CompensationType::APPLICATION_PER_DAY,
            'authorization_type' => Authorization::TYPE_SPECIAL,
            'attendance_pull_rule' => CompensationType::PULL_RULE_COMIDA,
        ]);
        $emp = Employee::factory()->create();
        // 2026-06-06 is a Saturday; flagged as weekend work (outside schedule).
        AttendanceRecord::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => '2026-06-06',
            'check_in' => '09:00:00',
            'check_out' => '15:00:00',
            'is_weekend_work' => true,
        ]);

        $this->getJson(route('authorizations.suggestBulk', [
            'employee_ids' => [$emp->id],
            'start_date' => '2026-06-06',
            'end_date' => '2026-06-06',
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $comida->id,
        ]))
            ->assertOk()
            ->assertJsonPath('suggestions.0.kind', 'comida')
            ->assertJsonPath('suggestions.0.hours', '1')
            ->assertJsonPath('eligible_count', 1);
    }

    /**
     * The comida rule fires ONLY on weekend work: a regular weekday with a long
     * shift (which WOULD earn a cena) yields no comida.
     */
    public function test_suggest_bulk_comida_skips_non_weekend_day(): void
    {
        $this->actingAsAdmin();
        $comida = CompensationType::factory()->create([
            'application_mode' => CompensationType::APPLICATION_PER_DAY,
            'authorization_type' => Authorization::TYPE_SPECIAL,
            'attendance_pull_rule' => CompensationType::PULL_RULE_COMIDA,
        ]);
        $emp = Employee::factory()->create();
        // 2026-06-08 is a Monday worked long, but NOT weekend work → no comida.
        AttendanceRecord::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => '2026-06-08',
            'check_in' => '08:00:00',
            'check_out' => '22:00:00',
            'worked_hours' => 13,
            'is_weekend_work' => false,
        ]);

        $this->getJson(route('authorizations.suggestBulk', [
            'employee_ids' => [$emp->id],
            'start_date' => '2026-06-08',
            'end_date' => '2026-06-08',
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $comida->id,
        ]))
            ->assertOk()
            ->assertJson(['eligible_count' => 0]);
    }
}
