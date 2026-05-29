<?php

namespace Tests\Feature\Config;

use App\Models\Employee;
use App\Models\Schedule;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\FeatureTestCase;

/**
 * Front-to-back contract tests for ScheduleController (Route::resource,
 * gated by schedules.manage -> admin only). Covers RBAC trio, Inertia
 * props consumed by the Vue pages, the rich time/working-day validation
 * rules, and the soft-deactivate destroy guarded by active employees.
 */
class ScheduleControllerTest extends FeatureTestCase
{
    /**
     * Minimal valid payload mirroring the controller's validate([...]) rules.
     *
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Turno Matutino',
            'code' => 'TM-01',
            'description' => 'Horario de manana',
            'entry_time' => '08:00',
            'exit_time' => '17:00',
            'break_minutes' => 60,
            'late_tolerance_minutes' => 10,
            'daily_work_hours' => 8,
            'is_flexible' => false,
            'is_active' => true,
            'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
        ], $overrides);
    }

    // ---------------------------------------------------------------- index

    public function test_admin_sees_schedule_index_with_expected_props(): void
    {
        $this->actingAsAdmin();
        Schedule::factory()->count(2)->create();

        $this->get(route('schedules.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Schedules/Index')
                ->has('schedules')
                ->has('schedules.data')
                ->has('filters'));
    }

    public function test_index_defaults_to_active_only(): void
    {
        $this->actingAsAdmin();
        $active = Schedule::factory()->create();
        Schedule::factory()->inactive()->create();

        $this->get(route('schedules.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('schedules.data', 1)
                ->where('schedules.data.0.id', $active->id));
    }

    public function test_rrhh_cannot_view_schedules(): void
    {
        $this->actingAsRrhh();
        $this->get(route('schedules.index'))->assertForbidden();
    }

    public function test_supervisor_cannot_view_schedules(): void
    {
        $this->actingAsSupervisor();
        $this->get(route('schedules.index'))->assertForbidden();
    }

    public function test_employee_cannot_view_schedules(): void
    {
        $this->actingAsEmployee();
        $this->get(route('schedules.index'))->assertForbidden();
    }

    public function test_guest_is_redirected_to_login_from_index(): void
    {
        $this->get(route('schedules.index'))->assertRedirect(route('login'));
    }

    // --------------------------------------------------------------- create

    public function test_admin_sees_create_form(): void
    {
        $this->actingAsAdmin();
        $this->get(route('schedules.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Schedules/Create'));
    }

    public function test_rrhh_cannot_open_create_form(): void
    {
        $this->actingAsRrhh();
        $this->get(route('schedules.create'))->assertForbidden();
    }

    // ---------------------------------------------------------------- store

    public function test_admin_can_store_schedule(): void
    {
        $this->actingAsAdmin();

        $this->post(route('schedules.store'), $this->validPayload())
            ->assertRedirect(route('schedules.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('schedules', [
            'name' => 'Turno Matutino',
            'code' => 'TM-01',
        ]);
    }

    public function test_store_requires_core_fields(): void
    {
        $this->actingAsAdmin();

        $this->from(route('schedules.create'))
            ->post(route('schedules.store'), [])
            ->assertSessionHasErrors([
                'name', 'code', 'entry_time', 'exit_time',
                'break_minutes', 'late_tolerance_minutes', 'daily_work_hours', 'working_days',
            ])
            ->assertRedirect(route('schedules.create'));
    }

    public function test_store_rejects_bad_time_format(): void
    {
        $this->actingAsAdmin();

        $this->from(route('schedules.create'))
            ->post(route('schedules.store'), $this->validPayload([
                'entry_time' => '8am',
            ]))
            ->assertSessionHasErrors(['entry_time']);
    }

    public function test_store_rejects_invalid_working_day(): void
    {
        $this->actingAsAdmin();

        $this->from(route('schedules.create'))
            ->post(route('schedules.store'), $this->validPayload([
                'working_days' => ['funday'],
            ]))
            ->assertSessionHasErrors(['working_days.0']);
    }

    public function test_store_rejects_empty_working_days(): void
    {
        $this->actingAsAdmin();

        $this->from(route('schedules.create'))
            ->post(route('schedules.store'), $this->validPayload([
                'working_days' => [],
            ]))
            ->assertSessionHasErrors(['working_days']);
    }

    public function test_store_rejects_daily_work_hours_over_24(): void
    {
        $this->actingAsAdmin();

        $this->from(route('schedules.create'))
            ->post(route('schedules.store'), $this->validPayload([
                'daily_work_hours' => 30,
            ]))
            ->assertSessionHasErrors(['daily_work_hours']);
    }

    public function test_store_rejects_duplicate_code(): void
    {
        $this->actingAsAdmin();
        Schedule::factory()->create(['code' => 'DUP-SCH']);

        $this->from(route('schedules.create'))
            ->post(route('schedules.store'), $this->validPayload([
                'code' => 'DUP-SCH',
            ]))
            ->assertSessionHasErrors(['code']);
    }

    public function test_rrhh_cannot_store_schedule(): void
    {
        $this->actingAsRrhh();
        $this->post(route('schedules.store'), $this->validPayload(['code' => 'RH-SCH']))
            ->assertForbidden();

        $this->assertDatabaseMissing('schedules', ['code' => 'RH-SCH']);
    }

    // ----------------------------------------------------------------- show

    public function test_admin_sees_show_with_schedule_prop(): void
    {
        $this->actingAsAdmin();
        $schedule = Schedule::factory()->create();

        $this->get(route('schedules.show', $schedule))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Schedules/Show')
                ->has('schedule')
                ->where('schedule.id', $schedule->id));
    }

    public function test_rrhh_cannot_show_schedule(): void
    {
        $this->actingAsRrhh();
        $schedule = Schedule::factory()->create();
        $this->get(route('schedules.show', $schedule))->assertForbidden();
    }

    // ----------------------------------------------------------------- edit

    public function test_admin_sees_edit_with_schedule_prop(): void
    {
        $this->actingAsAdmin();
        $schedule = Schedule::factory()->create();

        $this->get(route('schedules.edit', $schedule))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Schedules/Edit')
                ->has('schedule')
                ->where('schedule.id', $schedule->id));
    }

    public function test_rrhh_cannot_open_edit_form(): void
    {
        $this->actingAsRrhh();
        $schedule = Schedule::factory()->create();
        $this->get(route('schedules.edit', $schedule))->assertForbidden();
    }

    // --------------------------------------------------------------- update

    public function test_admin_can_update_schedule(): void
    {
        $this->actingAsAdmin();
        $schedule = Schedule::factory()->create(['name' => 'Viejo']);

        $this->put(route('schedules.update', $schedule), $this->validPayload([
            'name' => 'Horario Actualizado',
            'code' => $schedule->code,
        ]))
            ->assertRedirect(route('schedules.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('schedules', [
            'id' => $schedule->id,
            'name' => 'Horario Actualizado',
        ]);
    }

    public function test_update_allows_keeping_own_code(): void
    {
        $this->actingAsAdmin();
        $schedule = Schedule::factory()->create(['code' => 'KEEP-SCH']);

        $this->put(route('schedules.update', $schedule), $this->validPayload([
            'name' => 'Mismo Code',
            'code' => 'KEEP-SCH',
        ]))->assertRedirect(route('schedules.index'));
    }

    public function test_update_rejects_code_used_by_another_schedule(): void
    {
        $this->actingAsAdmin();
        Schedule::factory()->create(['code' => 'TAKEN-SCH']);
        $schedule = Schedule::factory()->create(['code' => 'MINE-SCH']);

        $this->from(route('schedules.edit', $schedule))
            ->put(route('schedules.update', $schedule), $this->validPayload([
                'name' => 'Conflicto',
                'code' => 'TAKEN-SCH',
            ]))
            ->assertSessionHasErrors(['code']);
    }

    public function test_rrhh_cannot_update_schedule(): void
    {
        $this->actingAsRrhh();
        $schedule = Schedule::factory()->create();
        $this->put(route('schedules.update', $schedule), $this->validPayload([
            'code' => $schedule->code,
        ]))->assertForbidden();
    }

    // -------------------------------------------------------------- destroy

    public function test_admin_can_soft_deactivate_empty_schedule(): void
    {
        $this->actingAsAdmin();
        $schedule = Schedule::factory()->create(['is_active' => true]);

        $this->delete(route('schedules.destroy', $schedule))
            ->assertRedirect(route('schedules.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('schedules', [
            'id' => $schedule->id,
            'is_active' => false,
        ]);
    }

    public function test_destroy_is_blocked_when_active_employees_exist(): void
    {
        $this->actingAsAdmin();
        $schedule = Schedule::factory()->create(['is_active' => true]);
        Employee::factory()->create(['schedule_id' => $schedule->id, 'status' => 'active']);

        $this->from(route('schedules.index'))
            ->delete(route('schedules.destroy', $schedule))
            ->assertRedirect(route('schedules.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('schedules', [
            'id' => $schedule->id,
            'is_active' => true,
        ]);
    }

    public function test_rrhh_cannot_destroy_schedule(): void
    {
        $this->actingAsRrhh();
        $schedule = Schedule::factory()->create(['is_active' => true]);
        $this->delete(route('schedules.destroy', $schedule))->assertForbidden();

        $this->assertDatabaseHas('schedules', [
            'id' => $schedule->id,
            'is_active' => true,
        ]);
    }

    public function test_guest_cannot_destroy_schedule(): void
    {
        $schedule = Schedule::factory()->create();
        $this->delete(route('schedules.destroy', $schedule))
            ->assertRedirect(route('login'));
    }

    // ----------------------------------------------- strengthened coverage

    public function test_index_search_filters_by_name_or_code(): void
    {
        $this->actingAsAdmin();
        $match = Schedule::factory()->create(['name' => 'Turno Nocturno', 'code' => 'NOC-77']);
        Schedule::factory()->create(['name' => 'Turno Mixto', 'code' => 'MIX-77']);

        $this->get(route('schedules.index', ['search' => 'Nocturno']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('schedules.data', 1)
                ->where('schedules.data.0.id', $match->id)
                ->where('filters.search', 'Nocturno'));

        $this->get(route('schedules.index', ['search' => 'NOC-77']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('schedules.data', 1));
    }

    public function test_index_rows_expose_employees_count(): void
    {
        $this->actingAsAdmin();
        $schedule = Schedule::factory()->create();

        $this->get(route('schedules.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('schedules.data.0', fn (Assert $row) => $row
                    ->where('id', $schedule->id)
                    ->has('employees_count')
                    ->etc()));
    }

    public function test_store_persists_working_days_payload(): void
    {
        $this->actingAsAdmin();

        $this->post(route('schedules.store'), $this->validPayload([
            'code' => 'WD-01',
            'working_days' => ['saturday', 'sunday'],
        ]))->assertRedirect(route('schedules.index'));

        $schedule = Schedule::where('code', 'WD-01')->firstOrFail();
        $this->assertEqualsCanonicalizing(['saturday', 'sunday'], $schedule->working_days);
    }

    public function test_store_rejects_bad_break_start_time_format(): void
    {
        $this->actingAsAdmin();

        $this->from(route('schedules.create'))
            ->post(route('schedules.store'), $this->validPayload([
                'break_start' => '12pm',
            ]))
            ->assertSessionHasErrors(['break_start']);
    }

    public function test_store_rejects_negative_break_minutes(): void
    {
        $this->actingAsAdmin();

        $this->from(route('schedules.create'))
            ->post(route('schedules.store'), $this->validPayload([
                'break_minutes' => -5,
            ]))
            ->assertSessionHasErrors(['break_minutes']);
    }

    public function test_store_rejects_daily_work_hours_below_one(): void
    {
        $this->actingAsAdmin();

        $this->from(route('schedules.create'))
            ->post(route('schedules.store'), $this->validPayload([
                'daily_work_hours' => 0,
            ]))
            ->assertSessionHasErrors(['daily_work_hours']);
    }

    public function test_store_rejects_invalid_day_schedule_entry_time(): void
    {
        $this->actingAsAdmin();

        $this->from(route('schedules.create'))
            ->post(route('schedules.store'), $this->validPayload([
                'code' => 'DS-BAD',
                'day_schedules' => [
                    'monday' => ['entry_time' => '99:99'],
                ],
            ]))
            ->assertSessionHasErrors(['day_schedules.monday.entry_time']);
    }

    public function test_store_accepts_valid_day_schedules_override(): void
    {
        $this->actingAsAdmin();

        $this->post(route('schedules.store'), $this->validPayload([
            'code' => 'DS-OK',
            'day_schedules' => [
                'monday' => ['entry_time' => '09:00', 'exit_time' => '18:00', 'daily_work_hours' => 8],
            ],
        ]))->assertRedirect(route('schedules.index'));

        $this->assertDatabaseHas('schedules', ['code' => 'DS-OK']);
        $schedule = Schedule::where('code', 'DS-OK')->firstOrFail();
        $this->assertNotNull($schedule->day_schedules);
    }

    public function test_supervisor_and_employee_cannot_store_or_destroy(): void
    {
        $schedule = Schedule::factory()->create(['is_active' => true]);

        $this->actingAsSupervisor();
        $this->post(route('schedules.store'), $this->validPayload(['code' => 'SUP-SCH']))
            ->assertForbidden();
        $this->delete(route('schedules.destroy', $schedule))->assertForbidden();

        $this->actingAsEmployee();
        $this->get(route('schedules.show', $schedule))->assertForbidden();
        $this->post(route('schedules.store'), $this->validPayload(['code' => 'EMP-SCH']))
            ->assertForbidden();

        $this->assertDatabaseMissing('schedules', ['code' => 'SUP-SCH']);
        $this->assertDatabaseMissing('schedules', ['code' => 'EMP-SCH']);
    }

    public function test_guest_is_redirected_from_create_show_edit_and_update(): void
    {
        $schedule = Schedule::factory()->create();

        $this->get(route('schedules.create'))->assertRedirect(route('login'));
        $this->get(route('schedules.show', $schedule))->assertRedirect(route('login'));
        $this->get(route('schedules.edit', $schedule))->assertRedirect(route('login'));
        $this->post(route('schedules.store'), [])->assertRedirect(route('login'));
        $this->put(route('schedules.update', $schedule), [])->assertRedirect(route('login'));
    }

    public function test_show_loads_schedule_relations(): void
    {
        $this->actingAsAdmin();
        $schedule = Schedule::factory()->create();

        $this->get(route('schedules.show', $schedule))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Schedules/Show')
                ->has('schedule.employees')
                ->where('schedule.employees_count', 0));
    }
}
