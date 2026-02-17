<?php

namespace App\Http\Controllers;

use App\Exports\EmployeeBulkExport;
use App\Imports\EmployeeBulkImport;
use App\Models\CompensationType;
use App\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Controller for employee bulk Excel import/export.
 *
 * Export produces an editable spreadsheet; import reads it back
 * with a preview step before committing changes.
 */
class EmployeeBulkController extends Controller
{
    /**
     * Export employees to Excel.
     *
     * Replicates the same filters available on EmployeeController@index.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $user = Auth::user();

        if (! $user->hasPermissionTo('employees.bulk_edit')) {
            abort(403, 'No tienes permiso para exportacion masiva.');
        }

        $query = Employee::with(['department', 'position', 'schedule', 'compensationTypes']);

        // Permission-based filtering (same as EmployeeController@index)
        if (! $user->hasPermissionTo('employees.view_all')) {
            if ($user->hasPermissionTo('employees.view_team')) {
                $userEmployee = $user->employee;
                if ($userEmployee) {
                    $query->where(function ($q) use ($userEmployee) {
                        $q->where('department_id', $userEmployee->department_id)
                            ->orWhere('supervisor_id', $userEmployee->id);
                    });
                } else {
                    $query->whereRaw('1 = 0');
                }
            } elseif ($user->hasPermissionTo('employees.view_own')) {
                $query->where('id', $user->employee?->id);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        // Specific employee IDs (from bulk selection)
        if ($request->has('employee_ids') && is_array($request->employee_ids)) {
            $query->whereIn('id', $request->employee_ids);
        }

        // Replicate Index filters
        $query->when($request->department, fn ($q, $dept) => $q->where('department_id', $dept))
            ->when($request->status && $request->status !== 'all', fn ($q) => $q->where('status', $request->status))
            ->when(! $request->has('status'), fn ($q) => $q->where('status', 'active'))
            ->when($request->has('is_minimum_wage') && $request->is_minimum_wage !== '', function ($q) use ($request) {
                $q->where('is_minimum_wage', $request->is_minimum_wage === 'yes');
            });

        $employees = $query->orderBy('full_name')->get();
        $compensationTypes = CompensationType::active()->orderBy('code')->get();

        $filename = 'empleados_' . now()->format('Y-m-d_Hi') . '.xlsx';

        return Excel::download(
            new EmployeeBulkExport($employees, $compensationTypes),
            $filename
        );
    }

    /**
     * Show the import page (upload state).
     */
    public function showImport(): Response
    {
        $user = Auth::user();

        if (! $user->hasPermissionTo('employees.bulk_edit')) {
            abort(403, 'No tienes permiso para importacion masiva.');
        }

        return Inertia::render('Employees/BulkImport', [
            'preview' => null,
            'errors' => [],
            'summary' => null,
        ]);
    }

    /**
     * Parse the uploaded file and return a preview of detected changes.
     */
    public function preview(Request $request): Response
    {
        $user = Auth::user();

        if (! $user->hasPermissionTo('employees.bulk_edit')) {
            abort(403, 'No tienes permiso para importacion masiva.');
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ], [
            'file.required' => 'Selecciona un archivo Excel.',
            'file.mimes' => 'El archivo debe ser .xlsx o .xls.',
            'file.max' => 'El archivo no debe superar 10MB.',
        ]);

        $import = new EmployeeBulkImport();
        Excel::import($import, $request->file('file'));

        // Store preview in session for the confirm step
        $request->session()->put('employee_import_preview', [
            'changes' => $import->getChanges(),
            'summary' => $import->getSummary(),
        ]);

        return Inertia::render('Employees/BulkImport', [
            'preview' => $import->getChanges(),
            'errors' => $import->getErrors(),
            'summary' => $import->getSummary(),
        ]);
    }

    /**
     * Apply the previewed changes from session.
     */
    public function confirm(Request $request): RedirectResponse
    {
        $user = Auth::user();

        if (! $user->hasPermissionTo('employees.bulk_edit')) {
            abort(403, 'No tienes permiso para importacion masiva.');
        }

        $preview = $request->session()->get('employee_import_preview');

        if (! $preview || empty($preview['changes'])) {
            return redirect()->route('employees.import')
                ->with('error', 'No hay cambios pendientes para aplicar.');
        }

        $compensationTypes = CompensationType::active()->get()->keyBy('code');
        $updatedCount = 0;

        DB::transaction(function () use ($preview, $compensationTypes, &$updatedCount) {
            foreach ($preview['changes'] as $employeeData) {
                $employee = Employee::with('compensationTypes')
                    ->find($employeeData['employee_id']);

                if (! $employee) {
                    continue;
                }

                $modelUpdates = [];
                $compChanges = [];

                foreach ($employeeData['changes'] as $change) {
                    if (isset($change['type']) && str_starts_with($change['type'], 'comp_')) {
                        $compChanges[] = $change;
                    } else {
                        // Standard field update
                        $field = $change['field'];
                        $value = $change['new_value'];

                        // Convert display values back to DB values
                        if ($field === 'is_minimum_wage') {
                            $value = $value === 'SI';
                        }

                        $modelUpdates[$field] = $value;
                    }
                }

                // Apply model updates
                if (! empty($modelUpdates)) {
                    $employee->update($modelUpdates);
                }

                // Apply compensation type changes
                if (! empty($compChanges)) {
                    $this->applyCompensationChanges($employee, $compChanges, $compensationTypes);
                }

                $updatedCount++;
            }
        });

        $request->session()->forget('employee_import_preview');

        return redirect()->route('employees.index')
            ->with('success', "{$updatedCount} empleados actualizados desde Excel.");
    }

    /**
     * Apply compensation type pivot changes for a single employee.
     */
    private function applyCompensationChanges(Employee $employee, array $changes, $compensationTypes): void
    {
        // Build the current sync data from existing pivot
        $syncData = [];
        foreach ($employee->compensationTypes as $ct) {
            $syncData[$ct->id] = [
                'custom_percentage' => $ct->pivot->custom_percentage,
                'custom_fixed_amount' => $ct->pivot->custom_fixed_amount,
                'is_active' => $ct->pivot->is_active,
            ];
        }

        foreach ($changes as $change) {
            $ctId = $change['compensation_type_id'];

            if ($change['type'] === 'comp_active') {
                $isActive = $change['new_value'] === 'SI';

                if ($isActive && ! isset($syncData[$ctId])) {
                    // Add new compensation type
                    $syncData[$ctId] = [
                        'custom_percentage' => null,
                        'custom_fixed_amount' => null,
                        'is_active' => true,
                    ];
                } elseif (! $isActive && isset($syncData[$ctId])) {
                    // Remove compensation type
                    unset($syncData[$ctId]);
                }
            } elseif ($change['type'] === 'comp_value') {
                // Extract the code from the field name (comp_{code}_value)
                $code = preg_replace('/^comp_(.+)_value$/', '$1', $change['field']);
                $ct = $compensationTypes->get($code);

                if ($ct && isset($syncData[$ctId])) {
                    if ($ct->calculation_type === 'percentage') {
                        $syncData[$ctId]['custom_percentage'] = $change['new_value'];
                    } else {
                        $syncData[$ctId]['custom_fixed_amount'] = $change['new_value'];
                    }
                }
            }
        }

        $employee->compensationTypes()->sync($syncData);
    }
}
