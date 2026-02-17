<?php

namespace App\Imports;

use App\Models\CompensationType;
use App\Models\Employee;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Parse and validate an employee bulk import file.
 *
 * Does NOT persist changes automatically — only builds a preview
 * of detected changes for user confirmation.
 */
class EmployeeBulkImport implements ToCollection, WithHeadingRow, SkipsEmptyRows
{
    /**
     * Map of Spanish heading names to Employee model fields.
     */
    private const FIELD_MAP = [
        'tarifa_hora' => 'hourly_rate',
        'salario_diario' => 'daily_salary',
        'tarifa_extra' => 'overtime_rate',
        'tarifa_festivo' => 'holiday_rate',
        'tipo_bono_mensual' => 'monthly_bonus_type',
        'monto_bono_mensual' => 'monthly_bonus_amount',
        'salario_minimo' => 'is_minimum_wage',
        'dias_vacaciones' => 'vacation_days_entitled',
        'prima_vacacional_pct' => 'vacation_premium_percentage',
    ];

    /**
     * Numeric fields that must be >= 0.
     */
    private const NUMERIC_FIELDS = [
        'tarifa_hora',
        'salario_diario',
        'tarifa_extra',
        'tarifa_festivo',
        'monto_bono_mensual',
        'dias_vacaciones',
        'prima_vacacional_pct',
    ];

    /**
     * Detected changes per employee.
     *
     * @var array<int, array{employee_id: int, employee_number: string, full_name: string, changes: array}>
     */
    private array $changes = [];

    /**
     * Errors per row.
     *
     * @var array<int, array{row: int, employee_number: string|null, message: string}>
     */
    private array $errors = [];

    /**
     * Total rows processed.
     */
    private int $totalRows = 0;

    /**
     * Active compensation types keyed by code.
     */
    private Collection $compensationTypes;

    public function __construct()
    {
        $this->compensationTypes = CompensationType::active()->get()->keyBy('code');
    }

    /**
     * Process all rows — detect changes without persisting.
     */
    public function collection(Collection $rows): void
    {
        $this->totalRows = $rows->count();

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // +2 because heading is row 1, 0-indexed
            $employeeNumber = trim((string) ($row['numero_empleado'] ?? ''));

            if (empty($employeeNumber)) {
                $this->errors[] = [
                    'row' => $rowNumber,
                    'employee_number' => null,
                    'message' => 'Falta numero_empleado.',
                ];
                continue;
            }

            $employee = Employee::with('compensationTypes')
                ->where('employee_number', $employeeNumber)
                ->first();

            if (! $employee) {
                $this->errors[] = [
                    'row' => $rowNumber,
                    'employee_number' => $employeeNumber,
                    'message' => "Empleado con numero '{$employeeNumber}' no encontrado.",
                ];
                continue;
            }

            $employeeChanges = [];

            // Process editable fields (Group C)
            foreach (self::FIELD_MAP as $heading => $field) {
                if (! isset($row[$heading]) && $row[$heading] !== 0 && $row[$heading] !== '0') {
                    continue;
                }

                $newValue = $row[$heading];
                $oldValue = $employee->$field;

                // Validate
                $error = $this->validateField($heading, $newValue, $rowNumber, $employeeNumber);
                if ($error) {
                    $this->errors[] = $error;
                    continue;
                }

                // Normalize and compare
                if ($heading === 'salario_minimo') {
                    $newValue = $this->parseBooleanField($newValue);
                    $oldValue = (bool) $oldValue;
                    if ($newValue !== $oldValue) {
                        $employeeChanges[] = [
                            'field' => $field,
                            'label' => $heading,
                            'old_value' => $oldValue ? 'SI' : 'NO',
                            'new_value' => $newValue ? 'SI' : 'NO',
                        ];
                    }
                } elseif ($heading === 'tipo_bono_mensual') {
                    $newValue = strtolower(trim((string) $newValue));
                    $oldValue = $oldValue ?? 'none';
                    if ($newValue !== $oldValue) {
                        $employeeChanges[] = [
                            'field' => $field,
                            'label' => $heading,
                            'old_value' => $oldValue,
                            'new_value' => $newValue,
                        ];
                    }
                } elseif ($heading === 'dias_vacaciones') {
                    $newValue = (int) $newValue;
                    $oldValue = (int) ($oldValue ?? 0);
                    if ($newValue !== $oldValue) {
                        $employeeChanges[] = [
                            'field' => $field,
                            'label' => $heading,
                            'old_value' => $oldValue,
                            'new_value' => $newValue,
                        ];
                    }
                } else {
                    // Numeric comparison
                    $newValue = round((float) $newValue, 2);
                    $oldValue = round((float) ($oldValue ?? 0), 2);
                    if (abs($newValue - $oldValue) > 0.001) {
                        $employeeChanges[] = [
                            'field' => $field,
                            'label' => $heading,
                            'old_value' => $oldValue,
                            'new_value' => $newValue,
                        ];
                    }
                }
            }

            // Process dynamic compensation type columns (Group D)
            $compChanges = $this->processCompensationColumns($row, $employee, $rowNumber, $employeeNumber);
            $employeeChanges = array_merge($employeeChanges, $compChanges);

            if (! empty($employeeChanges)) {
                $this->changes[] = [
                    'employee_id' => $employee->id,
                    'employee_number' => $employee->employee_number,
                    'full_name' => $employee->full_name,
                    'changes' => $employeeChanges,
                ];
            }
        }
    }

    /**
     * Get all detected changes.
     *
     * @return array<int, array{employee_id: int, employee_number: string, full_name: string, changes: array}>
     */
    public function getChanges(): array
    {
        return $this->changes;
    }

    /**
     * Get all errors.
     *
     * @return array<int, array{row: int, employee_number: string|null, message: string}>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get a summary of the import.
     *
     * @return array{total_rows: int, employees_with_changes: int, error_count: int}
     */
    public function getSummary(): array
    {
        return [
            'total_rows' => $this->totalRows,
            'employees_with_changes' => count($this->changes),
            'error_count' => count($this->errors),
        ];
    }

    /**
     * Validate a single field value.
     *
     * @return array{row: int, employee_number: string, message: string}|null
     */
    private function validateField(string $heading, mixed $value, int $row, string $employeeNumber): ?array
    {
        // Numeric fields: must be >= 0
        if (in_array($heading, self::NUMERIC_FIELDS)) {
            if (! is_numeric($value) || (float) $value < 0) {
                return [
                    'row' => $row,
                    'employee_number' => $employeeNumber,
                    'message' => "Campo '{$heading}' debe ser un numero >= 0 (valor: '{$value}').",
                ];
            }
        }

        // Boolean fields: must be SI/NO
        if ($heading === 'salario_minimo') {
            $normalized = strtoupper(trim((string) $value));
            if (! in_array($normalized, ['SI', 'NO', 'SÍ', '1', '0', 'TRUE', 'FALSE'])) {
                return [
                    'row' => $row,
                    'employee_number' => $employeeNumber,
                    'message' => "Campo '{$heading}' debe ser SI o NO (valor: '{$value}').",
                ];
            }
        }

        // Enum: monthly_bonus_type
        if ($heading === 'tipo_bono_mensual') {
            $normalized = strtolower(trim((string) $value));
            if (! in_array($normalized, ['none', 'fixed', 'variable'])) {
                return [
                    'row' => $row,
                    'employee_number' => $employeeNumber,
                    'message' => "Campo '{$heading}' debe ser none, fixed o variable (valor: '{$value}').",
                ];
            }
        }

        // Vacation premium percentage: 0-100
        if ($heading === 'prima_vacacional_pct') {
            if (is_numeric($value) && ((float) $value < 0 || (float) $value > 100)) {
                return [
                    'row' => $row,
                    'employee_number' => $employeeNumber,
                    'message' => "Campo '{$heading}' debe estar entre 0 y 100 (valor: '{$value}').",
                ];
            }
        }

        return null;
    }

    /**
     * Parse SI/NO style boolean.
     */
    private function parseBooleanField(mixed $value): bool
    {
        $normalized = strtoupper(trim((string) $value));

        return in_array($normalized, ['SI', 'SÍ', '1', 'TRUE']);
    }

    /**
     * Process dynamic compensation type columns for a single employee.
     *
     * @return array<int, array{field: string, label: string, old_value: mixed, new_value: mixed}>
     */
    private function processCompensationColumns(Collection $row, Employee $employee, int $rowNumber, string $employeeNumber): array
    {
        $changes = [];
        $employeeCompTypes = $employee->compensationTypes->keyBy('id');

        foreach ($this->compensationTypes as $code => $ct) {
            $activeKey = "comp_{$code}_activo";
            $valueKey = $ct->calculation_type === 'percentage'
                ? "comp_{$code}_porcentaje"
                : "comp_{$code}_monto";

            // Skip if these columns are not present
            if (! isset($row[$activeKey])) {
                continue;
            }

            $pivot = $employeeCompTypes->get($ct->id);
            $wasActive = $pivot !== null;
            $newActive = $this->parseBooleanField($row[$activeKey]);

            // Check active status change
            if ($newActive !== $wasActive) {
                $changes[] = [
                    'field' => "comp_{$code}_active",
                    'label' => $activeKey,
                    'old_value' => $wasActive ? 'SI' : 'NO',
                    'new_value' => $newActive ? 'SI' : 'NO',
                    'compensation_type_id' => $ct->id,
                    'type' => 'comp_active',
                ];
            }

            // Check value change (only relevant if compensation is/will be active)
            if (isset($row[$valueKey]) && ($newActive || $wasActive)) {
                $newVal = round((float) $row[$valueKey], 2);

                // Validate
                if ($newVal < 0) {
                    $this->errors[] = [
                        'row' => $rowNumber,
                        'employee_number' => $employeeNumber,
                        'message' => "Campo '{$valueKey}' debe ser >= 0 (valor: '{$row[$valueKey]}').",
                    ];
                    continue;
                }

                if ($ct->calculation_type === 'percentage') {
                    $oldVal = round((float) ($pivot?->pivot->custom_percentage ?? $ct->percentage_value ?? 0), 2);
                } else {
                    $oldVal = round((float) ($pivot?->pivot->custom_fixed_amount ?? $ct->fixed_amount ?? 0), 2);
                }

                if (abs($newVal - $oldVal) > 0.001) {
                    $changes[] = [
                        'field' => "comp_{$code}_value",
                        'label' => $valueKey,
                        'old_value' => $oldVal,
                        'new_value' => $newVal,
                        'compensation_type_id' => $ct->id,
                        'type' => 'comp_value',
                    ];
                }
            }
        }

        return $changes;
    }
}
