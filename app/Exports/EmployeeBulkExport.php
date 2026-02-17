<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export employees to Excel for bulk editing (salary adjustments).
 *
 * The exported file IS the import template — round-trip design.
 * Columns: identification (key) + context (read-only) + editable fields + dynamic compensation types.
 */
class EmployeeBulkExport implements FromCollection, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    use Exportable;

    /**
     * Active compensation types used for dynamic columns.
     */
    private Collection $compensationTypes;

    /**
     * Employees to export.
     */
    private Collection $employees;

    public function __construct(Collection $employees, Collection $compensationTypes)
    {
        $this->employees = $employees;
        $this->compensationTypes = $compensationTypes;
    }

    /**
     * Get the collection of employees.
     */
    public function collection(): Collection
    {
        return $this->employees;
    }

    /**
     * Define column headings.
     *
     * Group A: identification key (numero_empleado)
     * Group B: context / read-only
     * Group C: editable fields
     * Group D: dynamic compensation type columns
     */
    public function headings(): array
    {
        $headings = [
            // A - Key
            'numero_empleado',
            // B - Context (read-only)
            'nombre_completo',
            'departamento',
            'puesto',
            'horario',
            'estado',
            // C - Editable
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

        // D - Dynamic compensation type columns
        foreach ($this->compensationTypes as $ct) {
            $headings[] = "comp_{$ct->code}_activo";
            if ($ct->calculation_type === 'percentage') {
                $headings[] = "comp_{$ct->code}_porcentaje";
            } else {
                $headings[] = "comp_{$ct->code}_monto";
            }
        }

        return $headings;
    }

    /**
     * Map each employee row to export values.
     *
     * @param \App\Models\Employee $employee
     */
    public function map($employee): array
    {
        $row = [
            // A - Key
            $employee->employee_number,
            // B - Context
            $employee->full_name,
            $employee->department?->name ?? '',
            $employee->position?->name ?? '',
            $employee->schedule?->name ?? '',
            $employee->status,
            // C - Editable
            (float) $employee->hourly_rate,
            (float) ($employee->daily_salary ?? 0),
            (float) ($employee->overtime_rate ?? 0),
            (float) ($employee->holiday_rate ?? 0),
            $employee->monthly_bonus_type ?? 'none',
            (float) ($employee->monthly_bonus_amount ?? 0),
            $employee->is_minimum_wage ? 'SI' : 'NO',
            (int) ($employee->vacation_days_entitled ?? 0),
            (float) ($employee->vacation_premium_percentage ?? 25),
        ];

        // D - Dynamic compensation type columns
        $employeeCompTypes = $employee->compensationTypes->keyBy('id');

        foreach ($this->compensationTypes as $ct) {
            $pivot = $employeeCompTypes->get($ct->id);

            $row[] = $pivot ? 'SI' : 'NO';

            if ($ct->calculation_type === 'percentage') {
                $row[] = $pivot ? (float) ($pivot->pivot->custom_percentage ?? $ct->percentage_value ?? 0) : (float) ($ct->percentage_value ?? 0);
            } else {
                $row[] = $pivot ? (float) ($pivot->pivot->custom_fixed_amount ?? $ct->fixed_amount ?? 0) : (float) ($ct->fixed_amount ?? 0);
            }
        }

        return $row;
    }

    /**
     * Apply styles — only bold header row. No decorative titles.
     */
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
