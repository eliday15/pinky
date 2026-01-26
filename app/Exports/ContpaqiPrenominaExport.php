<?php

namespace App\Exports;

use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export payroll data in CONTPAQi-compatible format.
 */
class ContpaqiPrenominaExport implements FromCollection, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    use Exportable;

    public function __construct(
        private PayrollPeriod $period
    ) {}

    /**
     * Get collection of payroll entries for the period.
     */
    public function collection(): Collection
    {
        return PayrollEntry::where('payroll_period_id', $this->period->id)
            ->with(['employee.department', 'employee.position'])
            ->orderBy('id')
            ->get();
    }

    /**
     * Define column headings using configured concept codes.
     */
    public function headings(): array
    {
        $codes = config('contpaqi.concept_codes');

        return [
            'CODIGO',
            'NOMBRE',
            'DEPARTAMENTO',
            'PUESTO',
            $codes['regular_pay'] . '_SUELDO',
            $codes['overtime_pay'] . '_HORAS_EXTRA',
            $codes['holiday_pay'] . '_FESTIVO',
            $codes['weekend_pay'] . '_FIN_SEMANA',
            $codes['vacation_pay'] . '_VACACIONES',
            $codes['bonuses'] . '_BONOS',
            $codes['deductions'] . '_DEDUCCIONES',
            'HORAS_REGULARES',
            'HORAS_EXTRA',
            'DIAS_TRABAJADOS',
            'DIAS_AUSENCIA',
            'BRUTO',
            'NETO',
        ];
    }

    /**
     * Map each payroll entry row to export format.
     *
     * @param PayrollEntry $entry
     */
    public function map($entry): array
    {
        return [
            $entry->employee->contpaqi_identifier,
            $entry->employee->full_name,
            $entry->employee->department?->name ?? '',
            $entry->employee->position?->name ?? '',
            $this->formatNumber($entry->regular_pay),
            $this->formatNumber($entry->overtime_pay),
            $this->formatNumber($entry->holiday_pay),
            $this->formatNumber($entry->weekend_pay),
            $this->formatNumber($entry->vacation_pay),
            $this->formatNumber($entry->bonuses),
            $this->formatNumber($entry->deductions),
            $this->formatNumber($entry->regular_hours),
            $this->formatNumber($entry->overtime_hours),
            $entry->days_worked,
            $entry->days_absent,
            $this->formatNumber($entry->gross_pay),
            $this->formatNumber($entry->net_pay),
        ];
    }

    /**
     * Apply styles to the worksheet.
     */
    public function styles(Worksheet $sheet): array
    {
        return [
            // Bold header row
            1 => ['font' => ['bold' => true]],
        ];
    }

    /**
     * Format number for CONTPAQi compatibility.
     */
    private function formatNumber(mixed $value): string
    {
        if ($value === null) {
            return '0.00';
        }

        $precision = config('contpaqi.export.decimal_precision', 2);

        return number_format(
            (float) $value,
            $precision,
            config('contpaqi.export.decimal_separator', '.'),
            config('contpaqi.export.thousands_separator', '')
        );
    }
}
