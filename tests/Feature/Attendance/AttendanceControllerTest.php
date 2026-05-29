<?php

namespace Tests\Feature\Attendance;

use App\Jobs\SyncZktecoJob;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Schedule;
use App\Models\SyncLog;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\FeatureTestCase;

/**
 * Feature tests for AttendanceController covering the front-to-back contract:
 * index, calendar, export, syncLogs, sync, edit, and update — including RBAC,
 * Inertia props that the matching Vue pages consume, validation, and DB effects.
 */
class AttendanceControllerTest extends FeatureTestCase
{
    // ------------------------------------------------------------------
    // index
    // ------------------------------------------------------------------

    public function test_index_renders_inertia_page_with_all_props_for_admin(): void
    {
        $this->actingAsAdmin();
        Employee::factory()->create();

        $this->get(route('attendance.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Index')
                ->has('employees')
                ->has('employees.data')
                ->has('dates')
                ->has('startDate')
                ->has('endDate')
                ->has('summary')
                ->has('lastSync')
                ->has('departments')
                ->has('filters')
                ->has('can', fn (Assert $can) => $can
                    ->where('sync', true)
                    ->where('edit', true)
                    ->where('export', true)
                    ->where('viewOvertimeDetails', true)));
    }

    public function test_index_summary_has_status_buckets(): void
    {
        $this->actingAsAdmin();
        $today = now()->toDateString();
        $emp = Employee::factory()->create();
        AttendanceRecord::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => $today,
            'status' => 'present',
        ]);

        $this->get(route('attendance.index', ['start_date' => $today, 'end_date' => $today]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Index')
                ->has('summary', fn (Assert $s) => $s
                    ->where('present', 1)
                    ->where('late', 0)
                    ->where('absent', 0)
                    ->where('partial', 0)));
    }

    public function test_index_lastSync_reflects_completed_sync_log(): void
    {
        $this->actingAsAdmin();
        SyncLog::factory()->create([
            'status' => 'completed',
            'completed_at' => now()->subMinutes(5),
        ]);

        $this->get(route('attendance.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('lastSync', fn ($v) => is_string($v) && $v !== 'Nunca'));
    }

    public function test_index_lastSync_is_nunca_when_no_completed_sync(): void
    {
        $this->actingAsAdmin();

        $this->get(route('attendance.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('lastSync', 'Nunca'));
    }

    public function test_index_can_flags_for_rrhh_read_only(): void
    {
        // rrhh has attendance.view_all but NOT edit/sync.
        $this->actingAsRrhh();

        $this->get(route('attendance.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Index')
                ->has('can', fn (Assert $can) => $can
                    ->where('sync', false)
                    ->where('edit', false)
                    ->where('export', true) // view_all grants export
                    ->where('viewOvertimeDetails', true)));
    }

    public function test_index_employee_sees_only_own_records(): void
    {
        $employeeUser = $this->employeeUser();
        $ownEmployee = $this->attachEmployee($employeeUser);
        $otherEmployee = Employee::factory()->create();
        $this->actingAs($employeeUser);

        $this->get(route('attendance.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Index')
                ->has('employees.data', 1)
                ->where('employees.data.0.id', $ownEmployee->id)
                ->has('can', fn (Assert $can) => $can
                    ->where('sync', false)
                    ->where('edit', false)
                    ->where('export', false)
                    ->where('viewOvertimeDetails', false)));
    }

    public function test_index_supervisor_sees_only_team_records(): void
    {
        $supervisorUser = $this->supervisorUser();
        $supervisorEmployee = $this->attachEmployee($supervisorUser);
        $subordinate = Employee::factory()->create(['supervisor_id' => $supervisorEmployee->id]);
        Employee::factory()->create(); // unrelated employee
        $this->actingAs($supervisorUser);

        $this->get(route('attendance.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Index')
                ->has('employees.data', 1)
                ->where('employees.data.0.id', $subordinate->id));
    }

    public function test_index_search_filter_narrows_results(): void
    {
        $this->actingAsAdmin();
        $matching = Employee::factory()->create(['full_name' => 'Zoraida Buscable']);
        Employee::factory()->create(['full_name' => 'Alguien Mas']);

        $this->get(route('attendance.index', ['search' => 'Zoraida']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('employees.data', 1)
                ->where('employees.data.0.id', $matching->id)
                ->where('filters.search', 'Zoraida'));
    }

    public function test_index_department_filter_narrows_results(): void
    {
        $this->actingAsAdmin();
        $dept = Department::factory()->create();
        $inDept = Employee::factory()->create(['department_id' => $dept->id]);
        Employee::factory()->create(); // different department

        $this->get(route('attendance.index', ['department' => $dept->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('employees.data', 1)
                ->where('employees.data.0.id', $inDept->id)
                ->where('filters.department', (string) $dept->id));
    }

    public function test_index_guest_redirected_to_login(): void
    {
        $this->get(route('attendance.index'))->assertRedirect(route('login'));
    }

    public function test_index_supervisor_without_employee_record_sees_nothing(): void
    {
        // view_team but user has NO linked employee -> whereRaw('1 = 0') branch.
        $supervisorUser = $this->supervisorUser();
        Employee::factory()->create(); // exists but unrelated
        $this->actingAs($supervisorUser);

        $this->get(route('attendance.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Index')
                ->has('employees.data', 0));
    }

    public function test_index_employee_without_linked_employee_sees_nothing(): void
    {
        // view_own but user has NO linked employee -> where('id', null) yields none.
        $employeeUser = $this->employeeUser();
        Employee::factory()->create();
        $this->actingAs($employeeUser);

        $this->get(route('attendance.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Index')
                ->has('employees.data', 0));
    }

    public function test_index_builds_full_date_range_array(): void
    {
        // A 3-day range should produce 3 consecutive ISO dates in `dates`.
        $this->actingAsAdmin();
        $start = '2026-05-04';
        $end = '2026-05-06';

        $this->get(route('attendance.index', ['start_date' => $start, 'end_date' => $end]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('startDate', $start)
                ->where('endDate', $end)
                ->where('dates', ['2026-05-04', '2026-05-05', '2026-05-06']));
    }

    public function test_index_end_before_start_is_clamped_to_start(): void
    {
        // Controller forces endDate = startDate when end < start.
        $this->actingAsAdmin();

        $this->get(route('attendance.index', [
            'start_date' => '2026-05-10',
            'end_date' => '2026-05-01',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('startDate', '2026-05-10')
                ->where('endDate', '2026-05-10')
                ->where('dates', ['2026-05-10']));
    }

    public function test_index_summary_counts_late_and_absent_buckets(): void
    {
        $this->actingAsAdmin();
        $day = '2026-05-04';
        $e1 = Employee::factory()->create();
        $e2 = Employee::factory()->create();
        $e3 = Employee::factory()->create();
        AttendanceRecord::factory()->create(['employee_id' => $e1->id, 'work_date' => $day, 'status' => 'late']);
        AttendanceRecord::factory()->create(['employee_id' => $e2->id, 'work_date' => $day, 'status' => 'absent']);
        AttendanceRecord::factory()->create(['employee_id' => $e3->id, 'work_date' => $day, 'status' => 'partial']);

        $this->get(route('attendance.index', ['start_date' => $day, 'end_date' => $day]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('summary', fn (Assert $s) => $s
                    ->where('present', 0)
                    ->where('late', 1)
                    ->where('absent', 1)
                    ->where('partial', 1)));
    }

    public function test_index_supervisor_can_flags_export_true_edit_sync_false(): void
    {
        // supervisor has view_team (export=true via OR) but no edit/sync and
        // viewOvertimeDetails requires view_all (false for supervisor).
        $supervisorUser = $this->supervisorUser();
        $this->attachEmployee($supervisorUser);
        $this->actingAs($supervisorUser);

        $this->get(route('attendance.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('can', fn (Assert $can) => $can
                    ->where('sync', false)
                    ->where('edit', false)
                    ->where('export', true)
                    ->where('viewOvertimeDetails', false)));
    }

    // ------------------------------------------------------------------
    // calendar
    // ------------------------------------------------------------------

    public function test_calendar_renders_with_props_and_no_employee_selected(): void
    {
        $this->actingAsAdmin();
        Employee::factory()->create();

        $this->get(route('attendance.calendar'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Calendar')
                ->has('employees')
                ->where('selectedEmployee', null)
                ->has('month')
                ->where('calendarData', []));
    }

    public function test_calendar_builds_data_for_selected_employee(): void
    {
        $this->actingAsAdmin();
        $emp = Employee::factory()->create();
        $month = now()->format('Y-m');
        AttendanceRecord::factory()->create([
            'employee_id' => $emp->id,
            'work_date' => now()->startOfMonth()->toDateString(),
            'status' => 'present',
        ]);

        $this->get(route('attendance.calendar', ['employee' => $emp->id, 'month' => $month]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Calendar')
                ->where('selectedEmployee', (string) $emp->id)
                ->where('month', $month)
                ->has('calendarData')
                ->has('calendarData.0', fn (Assert $day) => $day
                    ->has('date')
                    ->has('day')
                    ->has('dayName')
                    ->has('isWeekend')
                    ->has('record')));
    }

    public function test_calendar_guest_redirected_to_login(): void
    {
        $this->get(route('attendance.calendar'))->assertRedirect(route('login'));
    }

    public function test_calendar_day_without_record_has_null_record(): void
    {
        // Selected employee with NO attendance in the month: every day's `record`
        // key is present and null. Use a controlled past month to avoid records.
        $this->actingAsAdmin();
        $emp = Employee::factory()->create();
        $month = '2026-04';

        $this->get(route('attendance.calendar', ['employee' => $emp->id, 'month' => $month]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Calendar')
                ->where('month', $month)
                ->has('calendarData', 30) // April has 30 days
                ->has('calendarData.0', fn (Assert $day) => $day
                    ->where('date', '2026-04-01')
                    ->where('day', 1)
                    ->has('dayName')
                    ->has('isWeekend')
                    ->where('record', null)));
    }

    public function test_calendar_is_accessible_by_employee_role_for_own_record(): void
    {
        // calendar() now authorizes viewAny and scopes the employee param the same
        // way index() does. An employee (view_own) reaching the route renders the
        // page and may view THEIR OWN calendar.
        $employeeUser = $this->employeeUser();
        $own = $this->attachEmployee($employeeUser);
        $this->actingAs($employeeUser);

        $this->get(route('attendance.calendar', ['employee' => $own->id, 'month' => '2026-04']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Calendar')
                ->where('selectedEmployee', (string) $own->id)
                ->has('employees', 1)
                ->has('calendarData', 30)); // April 2026 has 30 days
    }

    public function test_calendar_does_not_leak_out_of_scope_employee_to_view_own_viewer(): void
    {
        // FIX #8: calendar() now applies index()'s permission-based scoping. An
        // employee (view_own) requesting a colleague's calendar gets the page but
        // NO out-of-scope data: selectedEmployee is nulled, calendarData empty,
        // and the colleague is absent from the employees list.
        $employeeUser = $this->employeeUser();
        $this->attachEmployee($employeeUser);
        $other = Employee::factory()->create();
        $this->actingAs($employeeUser);

        $this->get(route('attendance.calendar', ['employee' => $other->id, 'month' => '2026-04']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Calendar')
                ->where('selectedEmployee', null)
                ->where('calendarData', [])
                ->has('employees', 1) // only the viewer's own employee record
                ->where('employees.0.id', auth()->user()->employee->id));
    }

    // ------------------------------------------------------------------
    // export
    // ------------------------------------------------------------------

    public function test_export_downloads_xlsx_for_admin(): void
    {
        $this->actingAsAdmin();
        Employee::factory()->create();
        $start = now()->subDays(7)->toDateString();
        $end = now()->toDateString();

        $response = $this->get(route('attendance.export', ['start_date' => $start, 'end_date' => $end]));
        $response->assertOk();
        $this->assertStringContainsString(
            "asistencia_{$start}_{$end}.xlsx",
            $response->headers->get('content-disposition')
        );
    }

    public function test_export_requires_start_and_end_dates(): void
    {
        $this->actingAsAdmin();

        $this->get(route('attendance.export'))
            ->assertSessionHasErrors(['start_date', 'end_date']);
    }

    public function test_export_rejects_end_before_start(): void
    {
        $this->actingAsAdmin();

        $this->get(route('attendance.export', [
            'start_date' => now()->toDateString(),
            'end_date' => now()->subDays(3)->toDateString(),
        ]))->assertSessionHasErrors(['end_date']);
    }

    public function test_export_allowed_for_supervisor_team_scope(): void
    {
        // export 'can' is granted by view_all OR view_team; viewAny policy passes for supervisor.
        $supervisorUser = $this->supervisorUser();
        $this->attachEmployee($supervisorUser);
        $this->actingAs($supervisorUser);

        $this->get(route('attendance.export', [
            'start_date' => now()->subDays(2)->toDateString(),
            'end_date' => now()->toDateString(),
        ]))->assertOk();
    }

    public function test_export_guest_redirected_to_login(): void
    {
        $this->get(route('attendance.export', [
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->toDateString(),
        ]))->assertRedirect(route('login'));
    }

    public function test_export_allowed_for_employee_view_own_scope(): void
    {
        // export() only authorizes viewAny, which view_own satisfies. So an
        // employee CAN download an export (scoped to their own records).
        $employeeUser = $this->employeeUser();
        $this->attachEmployee($employeeUser);
        $this->actingAs($employeeUser);

        $this->get(route('attendance.export', [
            'start_date' => now()->subDays(2)->toDateString(),
            'end_date' => now()->toDateString(),
        ]))->assertOk();
    }

    public function test_export_with_department_filter_downloads(): void
    {
        $this->actingAsAdmin();
        $dept = Department::factory()->create();
        Employee::factory()->create(['department_id' => $dept->id]);
        $start = now()->subDays(3)->toDateString();
        $end = now()->toDateString();

        $response = $this->get(route('attendance.export', [
            'start_date' => $start,
            'end_date' => $end,
            'department' => $dept->id,
        ]));
        $response->assertOk();
        $this->assertStringContainsString(
            "asistencia_{$start}_{$end}.xlsx",
            $response->headers->get('content-disposition')
        );
    }

    public function test_export_rejects_non_date_start(): void
    {
        $this->actingAsAdmin();

        $this->get(route('attendance.export', [
            'start_date' => 'not-a-date',
            'end_date' => now()->toDateString(),
        ]))->assertSessionHasErrors(['start_date']);
    }

    // ------------------------------------------------------------------
    // syncLogs
    // ------------------------------------------------------------------

    public function test_sync_logs_renders_for_admin(): void
    {
        $this->actingAsAdmin();
        SyncLog::factory()->create(['status' => 'completed']);

        $this->get(route('attendance.sync-logs'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/SyncLogs')
                ->has('logs'));
    }

    public function test_sync_logs_forbidden_for_rrhh_without_sync_permission(): void
    {
        // rrhh lacks attendance.sync -> abort(403).
        $this->actingAsRrhh();

        $this->get(route('attendance.sync-logs'))->assertForbidden();
    }

    public function test_sync_logs_forbidden_for_employee(): void
    {
        $this->actingAsEmployee();

        $this->get(route('attendance.sync-logs'))->assertForbidden();
    }

    public function test_sync_logs_guest_redirected_to_login(): void
    {
        $this->get(route('attendance.sync-logs'))->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------
    // sync (POST)
    // ------------------------------------------------------------------

    public function test_sync_dispatches_for_admin_and_redirects_back(): void
    {
        // Fake the queue so the local-mode SyncZktecoJob is recorded but not
        // executed (the real job queries an external ZKTeco device DB that does
        // not exist in tests). This verifies the controller's contract: dispatch
        // the job + redirect back with a success flash.
        Queue::fake();
        $this->actingAsAdmin();

        $this->from(route('attendance.index'))
            ->post(route('attendance.sync'))
            ->assertRedirect(route('attendance.index'))
            ->assertSessionHas('success');

        Queue::assertPushed(SyncZktecoJob::class);
    }

    public function test_sync_warns_when_sync_already_running(): void
    {
        $this->actingAsAdmin();
        SyncLog::factory()->create([
            'status' => 'running',
            'started_at' => now()->subMinutes(2),
        ]);

        $this->from(route('attendance.index'))
            ->post(route('attendance.sync'))
            ->assertRedirect(route('attendance.index'))
            ->assertSessionHas('warning');
    }

    public function test_sync_forbidden_for_rrhh(): void
    {
        $this->actingAsRrhh();

        $this->post(route('attendance.sync'))->assertForbidden();
    }

    public function test_sync_forbidden_for_supervisor(): void
    {
        $supervisorUser = $this->supervisorUser();
        $this->attachEmployee($supervisorUser);
        $this->actingAs($supervisorUser);

        $this->post(route('attendance.sync'))->assertForbidden();
    }

    public function test_sync_forbidden_for_employee(): void
    {
        $this->actingAsEmployee();

        $this->post(route('attendance.sync'))->assertForbidden();
    }

    public function test_sync_guest_redirected_to_login(): void
    {
        $this->post(route('attendance.sync'))->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------
    // edit
    // ------------------------------------------------------------------

    public function test_edit_renders_inertia_page_with_record_for_admin(): void
    {
        $this->actingAsAdmin();
        $record = AttendanceRecord::factory()->create();

        $this->get(route('attendance.edit', $record))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Edit')
                ->has('record', fn (Assert $r) => $r
                    ->where('id', $record->id)
                    ->etc()));
    }

    public function test_edit_forbidden_for_rrhh_without_edit_permission(): void
    {
        // rrhh has only attendance.view_all; policy update() needs attendance.edit.
        $this->actingAsRrhh();
        $record = AttendanceRecord::factory()->create();

        $this->get(route('attendance.edit', $record))->assertForbidden();
    }

    public function test_edit_forbidden_for_supervisor(): void
    {
        $supervisorUser = $this->supervisorUser();
        $this->attachEmployee($supervisorUser);
        $this->actingAs($supervisorUser);
        $record = AttendanceRecord::factory()->create();

        $this->get(route('attendance.edit', $record))->assertForbidden();
    }

    public function test_edit_forbidden_for_employee(): void
    {
        $this->actingAsEmployee();
        $record = AttendanceRecord::factory()->create();

        $this->get(route('attendance.edit', $record))->assertForbidden();
    }

    public function test_edit_guest_redirected_to_login(): void
    {
        $record = AttendanceRecord::factory()->create();

        $this->get(route('attendance.edit', $record))->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------
    // update (PUT)
    // ------------------------------------------------------------------

    public function test_update_status_only_persists_and_redirects(): void
    {
        // Update WITHOUT both times so the recalculation branch (which hits the
        // undefined $schedule variable bug) is not exercised here.
        $this->actingAsAdmin();
        $record = AttendanceRecord::factory()->create([
            'check_in' => null,
            'check_out' => null,
            'status' => 'present',
        ]);

        $this->put(route('attendance.update', $record), [
            'check_in' => null,
            'check_out' => null,
            'status' => 'absent',
            'manual_edit_reason' => 'Empleado no se presentó al turno',
        ])
            ->assertRedirect(route('attendance.index', ['date' => $record->work_date->toDateString()]))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('attendance_records', [
            'id' => $record->id,
            'status' => 'absent',
            'requires_review' => false,
            'manually_edited_by' => $this->user()->id ?? auth()->id(),
        ]);
    }

    public function test_update_persists_notes_and_edit_metadata(): void
    {
        // Status-only update (no times) also persists notes, resets requires_review,
        // and stamps manually_edited_by/at. Avoids the $schedule recalc bug branch.
        $admin = $this->actingAsAdmin();
        $record = AttendanceRecord::factory()->create([
            'check_in' => null,
            'check_out' => null,
            'status' => 'present',
            'requires_review' => true,
        ]);

        $this->put(route('attendance.update', $record), [
            'check_in' => null,
            'check_out' => null,
            'status' => 'permission',
            'notes' => 'Permiso autorizado por RRHH',
            'manual_edit_reason' => 'Correccion solicitada por el empleado',
        ])
            ->assertRedirect(route('attendance.index', ['date' => $record->work_date->toDateString()]))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('attendance_records', [
            'id' => $record->id,
            'status' => 'permission',
            'notes' => 'Permiso autorizado por RRHH',
            'requires_review' => false,
            'manually_edited_by' => $admin->id,
        ]);

        $record->refresh();
        $this->assertNotNull($record->manually_edited_at);
    }

    public function test_update_rejects_notes_exceeding_max_length(): void
    {
        $this->actingAsAdmin();
        $record = AttendanceRecord::factory()->create();

        $this->from(route('attendance.edit', $record))
            ->put(route('attendance.update', $record), [
                'status' => 'present',
                'notes' => str_repeat('a', 501), // max:500
                'manual_edit_reason' => 'Razon valida de edicion',
            ])
            ->assertSessionHasErrors(['notes']);
    }

    public function test_update_rejects_manual_edit_reason_exceeding_max_length(): void
    {
        $this->actingAsAdmin();
        $record = AttendanceRecord::factory()->create();

        $this->from(route('attendance.edit', $record))
            ->put(route('attendance.update', $record), [
                'status' => 'present',
                'manual_edit_reason' => str_repeat('b', 501), // max:500
            ])
            ->assertSessionHasErrors(['manual_edit_reason']);
    }

    public function test_update_requires_manual_edit_reason_and_status(): void
    {
        $this->actingAsAdmin();
        $record = AttendanceRecord::factory()->create();

        $this->from(route('attendance.edit', $record))
            ->put(route('attendance.update', $record), [])
            ->assertSessionHasErrors(['status', 'manual_edit_reason']);
    }

    public function test_update_rejects_short_manual_edit_reason(): void
    {
        $this->actingAsAdmin();
        $record = AttendanceRecord::factory()->create();

        $this->from(route('attendance.edit', $record))
            ->put(route('attendance.update', $record), [
                'status' => 'present',
                'manual_edit_reason' => 'no', // < 5 chars
            ])
            ->assertSessionHasErrors(['manual_edit_reason']);
    }

    public function test_update_rejects_invalid_status_enum(): void
    {
        $this->actingAsAdmin();
        $record = AttendanceRecord::factory()->create();

        $this->from(route('attendance.edit', $record))
            ->put(route('attendance.update', $record), [
                'status' => 'not_a_real_status',
                'manual_edit_reason' => 'Razon valida de edicion',
            ])
            ->assertSessionHasErrors(['status']);
    }

    public function test_update_rejects_malformed_time_format(): void
    {
        $this->actingAsAdmin();
        $record = AttendanceRecord::factory()->create();

        $this->from(route('attendance.edit', $record))
            ->put(route('attendance.update', $record), [
                'check_in' => '9am', // not H:i
                'status' => 'present',
                'manual_edit_reason' => 'Razon valida de edicion',
            ])
            ->assertSessionHasErrors(['check_in']);
    }

    public function test_update_forbidden_for_rrhh(): void
    {
        $this->actingAsRrhh();
        $record = AttendanceRecord::factory()->create();

        $this->put(route('attendance.update', $record), [
            'status' => 'present',
            'manual_edit_reason' => 'Razon valida de edicion',
        ])->assertForbidden();
    }

    public function test_update_forbidden_for_employee(): void
    {
        $this->actingAsEmployee();
        $record = AttendanceRecord::factory()->create();

        $this->put(route('attendance.update', $record), [
            'status' => 'present',
            'manual_edit_reason' => 'Razon valida de edicion',
        ])->assertForbidden();
    }

    public function test_update_guest_redirected_to_login(): void
    {
        $record = AttendanceRecord::factory()->create();

        $this->put(route('attendance.update', $record), [
            'status' => 'present',
            'manual_edit_reason' => 'Razon valida de edicion',
        ])->assertRedirect(route('login'));
    }

    public function test_update_with_both_times_recalculates_hours(): void
    {
        // Exercises the time-recalculation branch in update(): provides both
        // check_in and check_out for an employee WITH a schedule. This was the
        // path that referenced the undefined local variable $schedule
        // (AttendanceController::update, line ~336). Now that $dailyHours is
        // computed from $daySchedule inside the schedule branch, it should
        // recalc worked / overtime hours and persist them instead of 500ing.
        $this->actingAsAdmin();
        $schedule = Schedule::factory()->create();
        $employee = Employee::factory()->create(['schedule_id' => $schedule->id]);
        $record = AttendanceRecord::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-05-04', // Monday
        ]);

        $response = $this->put(route('attendance.update', $record), [
            'check_in' => '09:00',
            'check_out' => '18:00',
            'status' => 'present',
            'manual_edit_reason' => 'Ajuste de horario por correccion',
        ]);

        $response->assertStatus(302);
        $response->assertRedirect(route('attendance.index', ['date' => $record->work_date->toDateString()]));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('attendance_records', [
            'id' => $record->id,
            'check_in' => '09:00',
            'status' => 'present',
            'requires_review' => false,
        ]);

        // 09:00-18:00 = 9h gross; with a >5h shift a default 60-min break is
        // deducted, leaving 8h worked and 0 overtime against an 8h day.
        $record->refresh();
        $this->assertEqualsWithDelta(8.0, (float) $record->worked_hours, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $record->overtime_hours, 0.01);
    }

    /** Convenience accessor for the currently authenticated user. */
    private function user(): ?\App\Models\User
    {
        return auth()->user();
    }
}
