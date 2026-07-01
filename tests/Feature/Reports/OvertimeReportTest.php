<?php

namespace Tests\Feature\Reports;

use App\Models\Department;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\FeatureTestCase;

/**
 * Front-to-back contract tests for OvertimeReportController
 * (Formato de Tiempo Extra — weekly per-department).
 *
 * Unlike the other report controllers, this one is gated on
 * reports.view_all|view_team ONLY (no view_own). Per the seeder:
 *   - admin      => view_all  (allowed)
 *   - supervisor => view_team (allowed through the gate)
 *   - rrhh       => NO reports.* permission       => 403
 *   - employee   => only reports.view_own          => 403 (no team/all)
 *   - guest      => redirect to login
 *
 * index   -> Inertia 'Reports/OvertimeWeekly/Index'   (departments, defaultWeekStart)
 * preview -> Inertia 'Reports/OvertimeWeekly/Preview' (report, layout)
 * export.pdf   -> DomPDF download
 * export.excel -> Maatwebsite download
 *
 * preview/export require department_id (exists) + week_start (date).
 */
class OvertimeReportTest extends FeatureTestCase
{
    private const WEEK_START = '2026-03-09'; // Monday

    // ------------------------------------------------------------------
    // index: RBAC + Inertia prop contract
    // ------------------------------------------------------------------

    public function test_admin_can_view_index_with_props(): void
    {
        $this->actingAsAdmin();

        $dept = Department::factory()->create(['name' => 'Corte', 'code' => 'CORTE']);

        $this->get(route('reports.overtime-weekly.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/OvertimeWeekly/Index')
                ->has('departments')
                ->has('defaultWeekStart')
                ->where('departments', fn ($departments) => collect($departments)
                    ->contains(fn ($d) => $d['id'] === $dept->id)));
    }

    /**
     * Prop-shape contract: each department object the Index page consumes must
     * carry id, name and code (the Vue picker binds option value=id, text=name,
     * and the controller selects exactly these three columns).
     */
    public function test_index_department_items_expose_id_name_code(): void
    {
        $this->actingAsAdmin();

        Department::factory()->create(['name' => 'Acabado', 'code' => 'ACAB']);

        $this->get(route('reports.overtime-weekly.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/OvertimeWeekly/Index')
                ->has('defaultWeekStart')
                ->has('departments.0', fn (Assert $d) => $d
                    ->has('id')
                    ->has('name')
                    ->has('code')));
    }

    public function test_index_only_lists_active_departments(): void
    {
        $this->actingAsAdmin();

        $active = Department::factory()->create(['is_active' => true]);
        $inactive = Department::factory()->inactive()->create();

        $this->get(route('reports.overtime-weekly.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/OvertimeWeekly/Index')
                ->where('departments', fn ($departments) => collect($departments)->pluck('id')->contains($active->id)
                    && ! collect($departments)->pluck('id')->contains($inactive->id)));
    }

    /**
     * Supervisors are granted reports.view_team by RolesPermissionsSeeder, so
     * they pass the OvertimeReportController gate (which accepts
     * reports.view_all|view_team) and reach the selector page with 200.
     */
    public function test_supervisor_can_view_overtime_index(): void
    {
        $user = $this->actingAsSupervisor();
        $this->attachEmployee($user);

        $this->get(route('reports.overtime-weekly.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/OvertimeWeekly/Index'));
    }

    public function test_rrhh_is_forbidden_from_index(): void
    {
        $this->actingAsRrhh();

        $this->get(route('reports.overtime-weekly.index'))->assertForbidden();
    }

    public function test_employee_is_forbidden_from_index(): void
    {
        // employee only has reports.view_own, which this controller does NOT accept.
        $user = $this->actingAsEmployee();
        $this->attachEmployee($user);

        $this->get(route('reports.overtime-weekly.index'))->assertForbidden();
    }

    public function test_guest_is_redirected_from_index(): void
    {
        $this->get(route('reports.overtime-weekly.index'))->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------
    // preview: Inertia prop contract (report + layout)
    // ------------------------------------------------------------------

    public function test_admin_can_preview_report_with_props(): void
    {
        $this->actingAsAdmin();

        $dept = Department::factory()->create(['name' => 'Producción', 'code' => 'PROD']);

        $this->get(route('reports.overtime-weekly.preview', [
            'department_id' => $dept->id,
            'week_start' => self::WEEK_START,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/OvertimeWeekly/Preview')
                ->where('layout', 'default') // unknown code => DefaultTemplate
                ->has('report', fn (Assert $r) => $r
                    ->where('department.id', $dept->id)
                    ->where('department.name', 'Producción')
                    ->where('week_start', self::WEEK_START)
                    ->has('week_end')
                    ->where('weekend_unit_hours', null) // depto sin regla de unidades
                    ->has('dates', 7)
                    ->has('rows')
                    ->has('totals')));
    }

    /**
     * Rango libre ("de qué día a qué día"): al mandar end_date el reporte cubre
     * el rango literal [week_start, end_date], sin normalizar a la semana lun–dom.
     */
    public function test_preview_supports_custom_date_range(): void
    {
        $this->actingAsAdmin();

        $dept = Department::factory()->create(['name' => 'Almacén PT', 'code' => 'ALMACENPT']);

        // Miércoles a viernes: 3 días exactos.
        $this->get(route('reports.overtime-weekly.preview', [
            'department_id' => $dept->id,
            'week_start' => '2026-03-11', // miércoles
            'end_date' => '2026-03-13',   // viernes
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/OvertimeWeekly/Preview')
                ->where('report.week_start', '2026-03-11')
                ->where('report.week_end', '2026-03-13')
                ->has('report.dates', 3));
    }

    /**
     * Sin end_date se conserva el comportamiento semanal: cualquier fecha se
     * normaliza a su semana lun–dom (7 días).
     */
    public function test_preview_without_end_date_falls_back_to_week(): void
    {
        $this->actingAsAdmin();

        $dept = Department::factory()->create();

        $this->get(route('reports.overtime-weekly.preview', [
            'department_id' => $dept->id,
            'week_start' => '2026-03-11', // miércoles → semana 09–15 mar
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.week_start', '2026-03-09')
                ->where('report.week_end', '2026-03-15')
                ->has('report.dates', 7));
    }

    /**
     * end_date anterior a week_start se rechaza (regla after_or_equal).
     */
    public function test_preview_rejects_end_date_before_start(): void
    {
        $this->actingAsAdmin();

        $dept = Department::factory()->create();

        $this->from(route('reports.overtime-weekly.index'))
            ->get(route('reports.overtime-weekly.preview', [
                'department_id' => $dept->id,
                'week_start' => '2026-03-13',
                'end_date' => '2026-03-11',
            ]))
            ->assertSessionHasErrors(['end_date']);
    }

    /**
     * Preview with an active employee in the department: the report.rows array
     * must carry one row whose nested shape matches what the Vue table consumes
     * (employee.full_name, days map, totals with total_hours/weekend_hours/
     * velada_count/cena_count/comida_count), and grand totals must reflect the
     * employee_count. Guards the front-to-back DTO contract for a populated report.
     */
    public function test_preview_includes_employee_rows_and_totals(): void
    {
        $this->actingAsAdmin();

        $dept = \App\Models\Department::factory()->create(['name' => 'Empaque', 'code' => 'EMP']);
        \App\Models\Employee::factory()->create([
            'department_id' => $dept->id,
            'status' => 'active',
            'full_name' => 'Rosa Empaque',
        ]);

        $this->get(route('reports.overtime-weekly.preview', [
            'department_id' => $dept->id,
            'week_start' => self::WEEK_START,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/OvertimeWeekly/Preview')
                ->where('report.totals.employee_count', 1)
                ->has('report.totals.total_hours')
                ->has('report.totals.weekend_hours')
                ->has('report.totals.velada_count')
                ->has('report.totals.cena_count')
                ->has('report.totals.comida_count')
                ->has('report.rows', 1)
                ->has('report.rows.0', fn (Assert $r) => $r
                    ->where('employee.full_name', 'Rosa Empaque')
                    ->has('employee.id')
                    ->has('employee.employee_number')
                    ->has('employee.has_night_shift')
                    ->has('days')
                    ->has('extra_concepts')
                    ->has('observations')
                    ->has('totals', fn (Assert $t) => $t
                        ->has('total_hours')
                        ->has('weekend_hours')
                        ->has('velada_count')
                        ->has('cena_count')
                        ->has('comida_count')
                        ->etc())));
    }

    /**
     * week_start must be a valid date — a non-date string is rejected by the
     * controller's resolveInputs() ['week_start' => 'date'] rule.
     */
    public function test_preview_rejects_invalid_week_start(): void
    {
        $this->actingAsAdmin();

        $dept = Department::factory()->create();

        $this->from(route('reports.overtime-weekly.index'))
            ->get(route('reports.overtime-weekly.preview', [
                'department_id' => $dept->id,
                'week_start' => 'not-a-date',
            ]))
            ->assertSessionHasErrors(['week_start']);
    }

    public function test_preview_requires_department_and_week(): void
    {
        $this->actingAsAdmin();

        $this->from(route('reports.overtime-weekly.index'))
            ->get(route('reports.overtime-weekly.preview'))
            ->assertSessionHasErrors(['department_id', 'week_start']);
    }

    public function test_preview_rejects_nonexistent_department(): void
    {
        $this->actingAsAdmin();

        $this->from(route('reports.overtime-weekly.index'))
            ->get(route('reports.overtime-weekly.preview', [
                'department_id' => 999999,
                'week_start' => self::WEEK_START,
            ]))
            ->assertSessionHasErrors(['department_id']);
    }

    /**
     * Same gate as index: a supervisor (reports.view_team) reaches the HTML
     * preview with 200 and the Preview component.
     */
    public function test_supervisor_can_view_overtime_preview(): void
    {
        $user = $this->actingAsSupervisor();
        $this->attachEmployee($user);

        $dept = Department::factory()->create();

        $this->get(route('reports.overtime-weekly.preview', [
            'department_id' => $dept->id,
            'week_start' => self::WEEK_START,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/OvertimeWeekly/Preview')
                ->where('report.department.id', $dept->id));
    }

    /**
     * A supervisor (reports.view_team) can pull the weekly overtime report for
     * the department their team works in. We link the supervisor to an Employee,
     * add a subordinate (supervisor_id = supervisor's employee id) in the same
     * department with a complete attendance record + an APPROVED overtime
     * authorization (HE code) that day, and assert the supervisor sees the
     * subordinate's populated row. The report is built per-department, so an
     * employee in a different department does NOT appear in this view.
     */
    public function test_supervisor_should_be_able_to_view_team_overtime_report(): void
    {
        $user = $this->actingAsSupervisor();

        $teamDept = Department::factory()->create(['name' => 'Costura', 'code' => 'COS']);
        $supervisorEmployee = $this->attachEmployee($user, [
            'department_id' => $teamDept->id,
            'status' => 'active',
        ]);

        // Supervisor now holds the seeded team-scoped report permission.
        $this->assertTrue($user->hasPermissionTo('reports.view_team'));

        $subordinate = \App\Models\Employee::factory()->create([
            'department_id' => $teamDept->id,
            'supervisor_id' => $supervisorEmployee->id,
            'status' => 'active',
            'full_name' => 'Subordinado Costura',
        ]);

        // Employee in a different department: must NOT appear in this report.
        $otherDept = Department::factory()->create(['name' => 'Bodega', 'code' => 'BOD']);
        \App\Models\Employee::factory()->create([
            'department_id' => $otherDept->id,
            'status' => 'active',
            'full_name' => 'Ajeno Bodega',
        ]);

        // Wednesday inside the WEEK_START (2026-03-09) week.
        $overtimeDate = '2026-03-11';

        $heType = \App\Models\CompensationType::factory()->create([
            'code' => 'HE',
            'name' => 'Horas Extra',
        ]);

        // Complete attendance record (check_in + check_out) so buildDay counts hours.
        \App\Models\AttendanceRecord::factory()->create([
            'employee_id' => $subordinate->id,
            'work_date' => $overtimeDate,
            'check_in' => '09:00:00',
            'check_out' => '20:00:00',
        ]);

        // Approved overtime authorization backing the daily extra-hours cell.
        \App\Models\Authorization::factory()->approved()->create([
            'employee_id' => $subordinate->id,
            'type' => \App\Models\Authorization::TYPE_OVERTIME,
            'compensation_type_id' => $heType->id,
            'date' => $overtimeDate,
            'hours' => 3.00,
        ]);

        $this->get(route('reports.overtime-weekly.preview', [
            'department_id' => $teamDept->id,
            'week_start' => self::WEEK_START,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/OvertimeWeekly/Preview')
                ->where('report.department.id', $teamDept->id)
                ->where('report.totals.total_hours', fn ($total) => (float) $total === 3.0)
                // Subordinate row is present with its approved overtime; the
                // out-of-department employee is excluded.
                ->where('report.rows', fn ($rows) => collect($rows)
                    ->contains(fn ($row) => $row['employee']['id'] === $subordinate->id
                        && (float) $row['totals']['total_hours'] === 3.0)
                    && ! collect($rows)->contains(fn ($row) => $row['employee']['full_name'] === 'Ajeno Bodega')));
    }

    public function test_rrhh_is_forbidden_from_preview(): void
    {
        $this->actingAsRrhh();

        $dept = Department::factory()->create();

        $this->get(route('reports.overtime-weekly.preview', [
            'department_id' => $dept->id,
            'week_start' => self::WEEK_START,
        ]))->assertForbidden();
    }

    public function test_guest_is_redirected_from_preview(): void
    {
        $dept = Department::factory()->create();

        $this->get(route('reports.overtime-weekly.preview', [
            'department_id' => $dept->id,
            'week_start' => self::WEEK_START,
        ]))->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------
    // export.pdf: DomPDF download
    // ------------------------------------------------------------------

    public function test_admin_can_export_pdf(): void
    {
        $this->actingAsAdmin();

        $dept = Department::factory()->create(['code' => 'CORTE']);

        $response = $this->get(route('reports.overtime-weekly.export.pdf', [
            'department_id' => $dept->id,
            'week_start' => self::WEEK_START,
        ]));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('content-type'));
        // Filename mirrors the department code + week start (buildFilename()).
        $this->assertStringContainsString(
            'tiempo_extra_corte_'.self::WEEK_START.'.pdf',
            (string) $response->headers->get('content-disposition'),
        );
    }

    public function test_export_pdf_rejects_invalid_week_start(): void
    {
        $this->actingAsAdmin();

        $dept = Department::factory()->create();

        $this->from(route('reports.overtime-weekly.index'))
            ->get(route('reports.overtime-weekly.export.pdf', [
                'department_id' => $dept->id,
                'week_start' => 'nope',
            ]))
            ->assertSessionHasErrors(['week_start']);
    }

    public function test_export_pdf_requires_inputs(): void
    {
        $this->actingAsAdmin();

        $this->from(route('reports.overtime-weekly.index'))
            ->get(route('reports.overtime-weekly.export.pdf'))
            ->assertSessionHasErrors(['department_id', 'week_start']);
    }

    public function test_rrhh_is_forbidden_from_export_pdf(): void
    {
        $this->actingAsRrhh();

        $dept = Department::factory()->create();

        $this->get(route('reports.overtime-weekly.export.pdf', [
            'department_id' => $dept->id,
            'week_start' => self::WEEK_START,
        ]))->assertForbidden();
    }

    public function test_guest_is_redirected_from_export_pdf(): void
    {
        $dept = Department::factory()->create();

        $this->get(route('reports.overtime-weekly.export.pdf', [
            'department_id' => $dept->id,
            'week_start' => self::WEEK_START,
        ]))->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------
    // export.excel: Maatwebsite download
    // ------------------------------------------------------------------

    public function test_admin_can_export_excel(): void
    {
        $this->actingAsAdmin();

        $dept = Department::factory()->create(['code' => 'CORTE']);

        $response = $this->get(route('reports.overtime-weekly.export.excel', [
            'department_id' => $dept->id,
            'week_start' => self::WEEK_START,
        ]));

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml',
            (string) $response->headers->get('content-type'),
        );
        $this->assertStringContainsString(
            'tiempo_extra_corte_'.self::WEEK_START.'.xlsx',
            (string) $response->headers->get('content-disposition'),
        );
    }

    public function test_export_excel_rejects_invalid_week_start(): void
    {
        $this->actingAsAdmin();

        $dept = Department::factory()->create();

        $this->from(route('reports.overtime-weekly.index'))
            ->get(route('reports.overtime-weekly.export.excel', [
                'department_id' => $dept->id,
                'week_start' => 'nope',
            ]))
            ->assertSessionHasErrors(['week_start']);
    }

    public function test_export_excel_requires_inputs(): void
    {
        $this->actingAsAdmin();

        $this->from(route('reports.overtime-weekly.index'))
            ->get(route('reports.overtime-weekly.export.excel'))
            ->assertSessionHasErrors(['department_id', 'week_start']);
    }

    public function test_employee_is_forbidden_from_export_excel(): void
    {
        $user = $this->actingAsEmployee();
        $this->attachEmployee($user);

        $dept = Department::factory()->create();

        $this->get(route('reports.overtime-weekly.export.excel', [
            'department_id' => $dept->id,
            'week_start' => self::WEEK_START,
        ]))->assertForbidden();
    }

    public function test_guest_is_redirected_from_export_excel(): void
    {
        $dept = Department::factory()->create();

        $this->get(route('reports.overtime-weekly.export.excel', [
            'department_id' => $dept->id,
            'week_start' => self::WEEK_START,
        ]))->assertRedirect(route('login'));
    }
}
