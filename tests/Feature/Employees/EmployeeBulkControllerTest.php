<?php

namespace Tests\Feature\Employees;

use App\Models\CompensationType;
use App\Models\Employee;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\FeatureTestCase;

/**
 * Front-to-back contract tests for EmployeeBulkController.
 *
 * Covers the four bulk endpoints (export, showImport, preview, confirm),
 * the RBAC trio for each, file-upload validation, the Inertia props the
 * Employees/BulkImport.vue page consumes, and the confirm/session flow.
 */
class EmployeeBulkControllerTest extends FeatureTestCase
{
    // ------------------------------------------------------------------
    // export — GET employees/export  (employees.export)
    // ------------------------------------------------------------------

    public function test_admin_can_download_employee_export(): void
    {
        $this->actingAsAdmin();
        Employee::factory()->count(2)->create(['status' => 'active']);

        $response = $this->get(route('employees.export'));

        $response->assertOk();
        $this->assertStringContainsString(
            'attachment',
            $response->headers->get('content-disposition') ?? ''
        );
    }

    public function test_export_filename_is_xlsx(): void
    {
        $this->actingAsAdmin();

        $response = $this->get(route('employees.export'));

        $response->assertOk();
        $disposition = $response->headers->get('content-disposition') ?? '';
        $this->assertStringContainsString('.xlsx', $disposition);
    }

    public function test_rrhh_cannot_export_employees_lacking_bulk_edit(): void
    {
        // rrhh has employees.view_all but NOT employees.bulk_edit → 403.
        $this->actingAsRrhh();

        $this->get(route('employees.export'))->assertForbidden();
    }

    public function test_supervisor_cannot_export_employees(): void
    {
        $this->actingAsSupervisor();

        $this->get(route('employees.export'))->assertForbidden();
    }

    public function test_employee_cannot_export_employees(): void
    {
        $this->actingAsEmployee();

        $this->get(route('employees.export'))->assertForbidden();
    }

    public function test_guest_redirected_to_login_from_export(): void
    {
        $this->get(route('employees.export'))->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------
    // showImport — GET employees/import  (employees.import)
    // ------------------------------------------------------------------

    public function test_admin_sees_import_page_with_expected_props(): void
    {
        $this->actingAsAdmin();

        $this->get(route('employees.import'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Employees/BulkImport')
                // Assert EVERY prop the BulkImport.vue page declares.
                ->where('preview', null)
                ->where('errors', [])
                ->where('summary', null));
    }

    public function test_rrhh_cannot_view_import_page(): void
    {
        $this->actingAsRrhh();

        $this->get(route('employees.import'))->assertForbidden();
    }

    public function test_supervisor_cannot_view_import_page(): void
    {
        $this->actingAsSupervisor();

        $this->get(route('employees.import'))->assertForbidden();
    }

    public function test_employee_cannot_view_import_page(): void
    {
        $this->actingAsEmployee();

        $this->get(route('employees.import'))->assertForbidden();
    }

    public function test_guest_redirected_to_login_from_import_page(): void
    {
        $this->get(route('employees.import'))->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------
    // preview — POST employees/import/preview  (employees.import.preview)
    // ------------------------------------------------------------------

    public function test_preview_requires_a_file(): void
    {
        $this->actingAsAdmin();

        $this->from(route('employees.import'))
            ->post(route('employees.import.preview'), [])
            ->assertSessionHasErrors(['file']);
    }

    public function test_preview_rejects_non_excel_mime(): void
    {
        $this->actingAsAdmin();

        // A .txt fake should fail the mimes:xlsx,xls rule.
        $file = UploadedFile::fake()->create('empleados.txt', 5, 'text/plain');

        $this->from(route('employees.import'))
            ->post(route('employees.import.preview'), ['file' => $file])
            ->assertSessionHasErrors(['file']);
    }

    public function test_preview_rejects_oversized_file(): void
    {
        $this->actingAsAdmin();

        // 11 MB exceeds the max:10240 (KB) rule.
        $file = UploadedFile::fake()->create('empleados.xlsx', 11 * 1024, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->from(route('employees.import'))
            ->post(route('employees.import.preview'), ['file' => $file])
            ->assertSessionHasErrors(['file']);
    }

    public function test_rrhh_cannot_preview_import(): void
    {
        $this->actingAsRrhh();

        $file = UploadedFile::fake()->create('empleados.xlsx', 5);

        $this->post(route('employees.import.preview'), ['file' => $file])
            ->assertForbidden();
    }

    public function test_supervisor_cannot_preview_import(): void
    {
        $this->actingAsSupervisor();

        $file = UploadedFile::fake()->create('empleados.xlsx', 5);

        $this->post(route('employees.import.preview'), ['file' => $file])
            ->assertForbidden();
    }

    public function test_employee_cannot_preview_import(): void
    {
        $this->actingAsEmployee();

        $file = UploadedFile::fake()->create('empleados.xlsx', 5);

        $this->post(route('employees.import.preview'), ['file' => $file])
            ->assertForbidden();
    }

    public function test_guest_redirected_to_login_from_preview(): void
    {
        $file = UploadedFile::fake()->create('empleados.xlsx', 5);

        $this->post(route('employees.import.preview'), ['file' => $file])
            ->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------
    // confirm — POST employees/import/confirm  (employees.import.confirm)
    // ------------------------------------------------------------------

    public function test_confirm_without_session_preview_redirects_with_error(): void
    {
        $this->actingAsAdmin();

        $this->post(route('employees.import.confirm'))
            ->assertRedirect(route('employees.import'))
            ->assertSessionHas('error');
    }

    public function test_confirm_with_empty_changes_redirects_with_error(): void
    {
        $this->actingAsAdmin();

        // Session has a preview but no changes → still treated as "nothing to apply".
        $this->withSession([
            'employee_import_preview' => ['changes' => [], 'summary' => []],
        ])->post(route('employees.import.confirm'))
            ->assertRedirect(route('employees.import'))
            ->assertSessionHas('error');
    }

    public function test_confirm_applies_session_changes_and_updates_employee(): void
    {
        $this->actingAsAdmin();

        $employee = Employee::factory()->create([
            'status' => 'active',
            'hourly_rate' => 50.00,
            'is_minimum_wage' => false,
        ]);

        // Seed a preview the same shape EmployeeBulkImport::getChanges() produces.
        $this->withSession([
            'employee_import_preview' => [
                'changes' => [
                    [
                        'employee_id' => $employee->id,
                        'employee_number' => $employee->employee_number,
                        'full_name' => $employee->full_name,
                        'changes' => [
                            [
                                'field' => 'hourly_rate',
                                'label' => 'tarifa_hora',
                                'old_value' => 50.00,
                                'new_value' => 99.99,
                            ],
                            [
                                'field' => 'is_minimum_wage',
                                'label' => 'salario_minimo',
                                'old_value' => 'NO',
                                'new_value' => 'SI',
                            ],
                        ],
                    ],
                ],
                'summary' => ['total_rows' => 1, 'employees_with_changes' => 1, 'error_count' => 0],
            ],
        ])->post(route('employees.import.confirm'))
            ->assertRedirect(route('employees.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'hourly_rate' => 99.99,
            'is_minimum_wage' => true,
        ]);
    }

    public function test_confirm_clears_the_session_preview(): void
    {
        $this->actingAsAdmin();

        $employee = Employee::factory()->create([
            'status' => 'active',
            'hourly_rate' => 10.00,
        ]);

        $this->withSession([
            'employee_import_preview' => [
                'changes' => [
                    [
                        'employee_id' => $employee->id,
                        'employee_number' => $employee->employee_number,
                        'full_name' => $employee->full_name,
                        'changes' => [
                            [
                                'field' => 'hourly_rate',
                                'label' => 'tarifa_hora',
                                'old_value' => 10.00,
                                'new_value' => 20.00,
                            ],
                        ],
                    ],
                ],
                'summary' => ['total_rows' => 1, 'employees_with_changes' => 1, 'error_count' => 0],
            ],
        ])->post(route('employees.import.confirm'))
            ->assertSessionMissing('employee_import_preview');
    }

    public function test_confirm_skips_missing_employee_ids_gracefully(): void
    {
        $this->actingAsAdmin();

        // employee_id 999999 does not exist; controller should `continue` and
        // still redirect successfully (counting it toward updatedCount).
        $this->withSession([
            'employee_import_preview' => [
                'changes' => [
                    [
                        'employee_id' => 999999,
                        'employee_number' => 'GONE',
                        'full_name' => 'Ghost',
                        'changes' => [
                            ['field' => 'hourly_rate', 'label' => 'tarifa_hora', 'old_value' => 0, 'new_value' => 5],
                        ],
                    ],
                ],
                'summary' => ['total_rows' => 1, 'employees_with_changes' => 1, 'error_count' => 0],
            ],
        ])->post(route('employees.import.confirm'))
            ->assertRedirect(route('employees.index'))
            ->assertSessionHas('success');
    }

    public function test_rrhh_cannot_confirm_import(): void
    {
        $this->actingAsRrhh();

        $this->post(route('employees.import.confirm'))->assertForbidden();
    }

    public function test_supervisor_cannot_confirm_import(): void
    {
        $this->actingAsSupervisor();

        $this->post(route('employees.import.confirm'))->assertForbidden();
    }

    public function test_employee_cannot_confirm_import(): void
    {
        $this->actingAsEmployee();

        $this->post(route('employees.import.confirm'))->assertForbidden();
    }

    public function test_guest_redirected_to_login_from_confirm(): void
    {
        $this->post(route('employees.import.confirm'))->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------
    // export — filter branches (employee_ids / status / is_minimum_wage)
    // ------------------------------------------------------------------

    public function test_export_honors_specific_employee_ids_filter(): void
    {
        $this->actingAsAdmin();
        Employee::factory()->count(3)->create(['status' => 'active']);
        $target = Employee::factory()->create(['status' => 'active']);

        // Exercises the `employee_ids` whereIn branch in export().
        $this->get(route('employees.export', ['employee_ids' => [$target->id]]))
            ->assertOk();
    }

    public function test_export_honors_status_filter(): void
    {
        $this->actingAsAdmin();
        Employee::factory()->create(['status' => 'inactive']);

        // status=inactive exercises the explicit-status branch (vs default active).
        $this->get(route('employees.export', ['status' => 'inactive']))
            ->assertOk();
    }

    public function test_export_honors_status_all_filter(): void
    {
        $this->actingAsAdmin();
        Employee::factory()->create(['status' => 'inactive']);
        Employee::factory()->create(['status' => 'active']);

        // status=all skips the status constraint entirely.
        $this->get(route('employees.export', ['status' => 'all']))
            ->assertOk();
    }

    public function test_export_honors_is_minimum_wage_filter(): void
    {
        $this->actingAsAdmin();
        Employee::factory()->minimumWage()->create(['status' => 'active']);

        // is_minimum_wage=yes exercises the boolean-coercion filter branch.
        $this->get(route('employees.export', ['is_minimum_wage' => 'yes']))
            ->assertOk();
    }

    // ------------------------------------------------------------------
    // preview — SUCCESS path with a real xlsx (round-trip from export shape)
    // ------------------------------------------------------------------

    public function test_preview_with_real_xlsx_renders_all_props_and_stores_session(): void
    {
        $this->actingAsAdmin();

        $employee = Employee::factory()->create([
            'status' => 'active',
            'employee_number' => 'EMP-PREVIEW-1',
            'hourly_rate' => 50.00,
        ]);

        // Build a minimal valid import file changing hourly_rate 50 -> 88.
        $file = $this->makeImportXlsx([
            ['numero_empleado' => $employee->employee_number, 'tarifa_hora' => 88],
        ]);

        $this->from(route('employees.import'))
            ->post(route('employees.import.preview'), ['file' => $file])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Employees/BulkImport')
                // All three declared props must be present after a successful parse.
                ->has('preview')
                ->has('errors')
                ->has('summary')
                ->where('summary.total_rows', 1)
                ->where('summary.employees_with_changes', 1)
                ->where('summary.error_count', 0)
                ->where('preview.0.employee_id', $employee->id)
                ->where('preview.0.changes.0.field', 'hourly_rate')
                // round((float)88,2) serializes as integer 88 in the JSON payload.
                ->where('preview.0.changes.0.new_value', 88));

        // The controller must persist the preview to session for the confirm step.
        $this->assertNotNull(session('employee_import_preview'));
        $this->assertNotEmpty(session('employee_import_preview')['changes']);
    }

    public function test_preview_reports_errors_for_unknown_employee_number(): void
    {
        $this->actingAsAdmin();

        // A row referencing a non-existent employee_number → an error, no change.
        $file = $this->makeImportXlsx([
            ['numero_empleado' => 'DOES-NOT-EXIST', 'tarifa_hora' => 10],
        ]);

        $this->from(route('employees.import'))
            ->post(route('employees.import.preview'), ['file' => $file])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Employees/BulkImport')
                ->where('summary.error_count', 1)
                ->where('summary.employees_with_changes', 0)
                ->where('errors.0.employee_number', 'DOES-NOT-EXIST'));
    }

    public function test_preview_reports_error_for_negative_numeric_value(): void
    {
        $this->actingAsAdmin();

        $employee = Employee::factory()->create([
            'status' => 'active',
            'employee_number' => 'EMP-NEG-1',
            'hourly_rate' => 50.00,
        ]);

        // Negative hourly_rate fails the ">= 0" validation in EmployeeBulkImport.
        $file = $this->makeImportXlsx([
            ['numero_empleado' => $employee->employee_number, 'tarifa_hora' => -5],
        ]);

        $this->from(route('employees.import'))
            ->post(route('employees.import.preview'), ['file' => $file])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.error_count', 1)
                ->where('summary.employees_with_changes', 0));
    }

    // ------------------------------------------------------------------
    // confirm — additional standard + compensation-type apply paths
    // ------------------------------------------------------------------

    public function test_confirm_applies_monthly_bonus_type_change(): void
    {
        $this->actingAsAdmin();

        $employee = Employee::factory()->create([
            'status' => 'active',
            'monthly_bonus_type' => 'none',
        ]);

        $this->withSession([
            'employee_import_preview' => [
                'changes' => [
                    [
                        'employee_id' => $employee->id,
                        'employee_number' => $employee->employee_number,
                        'full_name' => $employee->full_name,
                        'changes' => [
                            [
                                'field' => 'monthly_bonus_type',
                                'label' => 'tipo_bono_mensual',
                                'old_value' => 'none',
                                'new_value' => 'fixed',
                            ],
                        ],
                    ],
                ],
                'summary' => ['total_rows' => 1, 'employees_with_changes' => 1, 'error_count' => 0],
            ],
        ])->post(route('employees.import.confirm'))
            ->assertRedirect(route('employees.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'monthly_bonus_type' => 'fixed',
        ]);
    }

    public function test_confirm_activates_compensation_type_and_syncs_pivot(): void
    {
        $this->actingAsAdmin();

        $employee = Employee::factory()->create(['status' => 'active']);
        // A percentage compensation type the employee does NOT yet have.
        $ct = CompensationType::factory()->percentage(40.00)->create(['is_active' => true]);

        // Simulate a preview that flips comp_active SI and sets a custom value.
        $this->withSession([
            'employee_import_preview' => [
                'changes' => [
                    [
                        'employee_id' => $employee->id,
                        'employee_number' => $employee->employee_number,
                        'full_name' => $employee->full_name,
                        'changes' => [
                            [
                                'field' => "comp_{$ct->code}_active",
                                'label' => "comp_{$ct->code}_activo",
                                'old_value' => 'NO',
                                'new_value' => 'SI',
                                'compensation_type_id' => $ct->id,
                                'type' => 'comp_active',
                            ],
                            [
                                'field' => "comp_{$ct->code}_value",
                                'label' => "comp_{$ct->code}_porcentaje",
                                'old_value' => 40.0,
                                'new_value' => 75.0,
                                'compensation_type_id' => $ct->id,
                                'type' => 'comp_value',
                            ],
                        ],
                    ],
                ],
                'summary' => ['total_rows' => 1, 'employees_with_changes' => 1, 'error_count' => 0],
            ],
        ])->post(route('employees.import.confirm'))
            ->assertRedirect(route('employees.index'))
            ->assertSessionHas('success');

        // The pivot row must now exist with the custom percentage applied.
        $this->assertDatabaseHas('employee_compensation_type', [
            'employee_id' => $employee->id,
            'compensation_type_id' => $ct->id,
            'custom_percentage' => 75.00,
        ]);
    }

    public function test_confirm_deactivates_compensation_type_removes_pivot(): void
    {
        $this->actingAsAdmin();

        $employee = Employee::factory()->create(['status' => 'active']);
        $ct = CompensationType::factory()->percentage(40.00)->create(['is_active' => true]);

        // Pre-attach so the confirm step can remove it.
        $employee->compensationTypes()->attach($ct->id, [
            'custom_percentage' => 40.00,
            'custom_fixed_amount' => null,
            'is_active' => true,
        ]);

        $this->withSession([
            'employee_import_preview' => [
                'changes' => [
                    [
                        'employee_id' => $employee->id,
                        'employee_number' => $employee->employee_number,
                        'full_name' => $employee->full_name,
                        'changes' => [
                            [
                                'field' => "comp_{$ct->code}_active",
                                'label' => "comp_{$ct->code}_activo",
                                'old_value' => 'SI',
                                'new_value' => 'NO',
                                'compensation_type_id' => $ct->id,
                                'type' => 'comp_active',
                            ],
                        ],
                    ],
                ],
                'summary' => ['total_rows' => 1, 'employees_with_changes' => 1, 'error_count' => 0],
            ],
        ])->post(route('employees.import.confirm'))
            ->assertRedirect(route('employees.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('employee_compensation_type', [
            'employee_id' => $employee->id,
            'compensation_type_id' => $ct->id,
        ]);
    }

    public function test_confirm_success_message_counts_updated_employees(): void
    {
        $this->actingAsAdmin();

        $e1 = Employee::factory()->create(['status' => 'active', 'hourly_rate' => 10.00]);
        $e2 = Employee::factory()->create(['status' => 'active', 'hourly_rate' => 20.00]);

        $this->withSession([
            'employee_import_preview' => [
                'changes' => [
                    [
                        'employee_id' => $e1->id,
                        'employee_number' => $e1->employee_number,
                        'full_name' => $e1->full_name,
                        'changes' => [
                            ['field' => 'hourly_rate', 'label' => 'tarifa_hora', 'old_value' => 10.0, 'new_value' => 11.0],
                        ],
                    ],
                    [
                        'employee_id' => $e2->id,
                        'employee_number' => $e2->employee_number,
                        'full_name' => $e2->full_name,
                        'changes' => [
                            ['field' => 'hourly_rate', 'label' => 'tarifa_hora', 'old_value' => 20.0, 'new_value' => 22.0],
                        ],
                    ],
                ],
                'summary' => ['total_rows' => 2, 'employees_with_changes' => 2, 'error_count' => 0],
            ],
        ])->post(route('employees.import.confirm'))
            ->assertRedirect(route('employees.index'))
            ->assertSessionHas('success', '2 empleados actualizados desde Excel.');

        $this->assertDatabaseHas('employees', ['id' => $e1->id, 'hourly_rate' => 11.00]);
        $this->assertDatabaseHas('employees', ['id' => $e2->id, 'hourly_rate' => 22.00]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Build a real .xlsx UploadedFile matching the export/import heading shape.
     *
     * @param array<int, array<string, mixed>> $rows  Each row keyed by heading name.
     */
    private function makeImportXlsx(array $rows): UploadedFile
    {
        // Headings the EmployeeBulkImport reader expects (WithHeadingRow).
        $headings = [
            'numero_empleado',
            'tarifa_hora',
            'salario_diario',
            'tarifa_extra',
            'tarifa_festivo',
            'tipo_bono_mensual',
            'monto_bono_mensual',
            'salario_minimo',
            'dias_vacaciones',
            'prima_vacacional_pct',
        ];

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headings, null, 'A1');

        $rowIdx = 2;
        foreach ($rows as $row) {
            $line = [];
            foreach ($headings as $heading) {
                $line[] = $row[$heading] ?? null;
            }
            $sheet->fromArray($line, null, 'A' . $rowIdx);
            $rowIdx++;
        }

        $path = tempnam(sys_get_temp_dir(), 'emp_import_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);

        return new UploadedFile(
            $path,
            'empleados.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true // test mode — skip is_uploaded_file() check
        );
    }
}
