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
     * Whether this period pays the base salary (weekly/biweekly).
     */
    private function paysBase(): bool
    {
        return in_array($this->period->type, ['weekly', 'biweekly'], true);
    }

    /**
     * Whether this period pays the extras (monthly/biweekly).
     */
    private function paysExtras(): bool
    {
        return in_array($this->period->type, ['monthly', 'biweekly'], true);
    }

    /**
     * Column definitions (heading + value resolver), scoped to the period type.
     *
     * A weekly period exports only the base salary and absence deductions; a
     * monthly period exports only the extras (overtime, holiday, weekend,
     * other concepts, vacations, bonuses). A legacy biweekly period exports
     * both. This keeps the CONTPAQi layout aligned with what each period pays.
     *
     * @return array<int, array{heading: string, value: callable}>
     */
    private function columns(): array
    {
        $codes = config('contpaqi.concept_codes');
        $columns = [
            ['heading' => 'CODIGO', 'value' => fn ($e) => $e->employee->contpaqi_identifier],
            ['heading' => 'NOMBRE', 'value' => fn ($e) => $e->employee->full_name],
            ['heading' => 'DEPARTAMENTO', 'value' => fn ($e) => $e->employee->department?->name ?? ''],
            ['heading' => 'PUESTO', 'value' => fn ($e) => $e->employee->position?->name ?? ''],
        ];

        if ($this->paysBase()) {
            $columns[] = ['heading' => $codes['regular_pay'].'_SUELDO', 'value' => fn ($e) => $this->formatNumber($e->regular_pay)];
            $columns[] = ['heading' => $codes['deductions'].'_DEDUCCIONES', 'value' => fn ($e) => $this->formatNumber($e->deductions)];
            $columns[] = ['heading' => 'HORAS_REGULARES', 'value' => fn ($e) => $this->formatNumber($e->regular_hours)];
            $columns[] = ['heading' => 'DIAS_TRABAJADOS', 'value' => fn ($e) => $e->days_worked];
            $columns[] = ['heading' => 'DIAS_AUSENCIA', 'value' => fn ($e) => $e->days_absent];
        }

        if ($this->paysExtras()) {
            $columns[] = ['heading' => $codes['overtime_pay'].'_HORAS_EXTRA', 'value' => fn ($e) => $this->formatNumber($e->overtime_pay)];
            $columns[] = ['heading' => $codes['holiday_pay'].'_FESTIVO', 'value' => fn ($e) => $this->formatNumber($e->holiday_pay)];
            $columns[] = ['heading' => $codes['weekend_pay'].'_FIN_SEMANA', 'value' => fn ($e) => $this->formatNumber($e->weekend_pay)];
            $columns[] = ['heading' => $codes['other_compensation_pay'].'_OTROS', 'value' => fn ($e) => $this->formatNumber($e->other_compensation_pay)];
            $columns[] = ['heading' => $codes['vacation_pay'].'_VACACIONES', 'value' => fn ($e) => $this->formatNumber($e->vacation_pay)];
            $columns[] = ['heading' => $codes['bonuses'].'_BONOS', 'value' => fn ($e) => $this->formatNumber($e->bonuses)];
            $columns[] = ['heading' => 'HORAS_EXTRA', 'value' => fn ($e) => $this->formatNumber($e->overtime_hours)];
        }

        $columns[] = ['heading' => 'BRUTO', 'value' => fn ($e) => $this->formatNumber($e->gross_pay)];
        $columns[] = ['heading' => 'NETO', 'value' => fn ($e) => $this->formatNumber($e->net_pay)];

        return $columns;
    }

    /**
     * Define column headings, scoped to the period type.
     */
    public function headings(): array
    {
        return array_map(fn ($col) => $col['heading'], $this->columns());
    }

    /**
     * Map each payroll entry row to export format, scoped to the period type.
     *
     * @param PayrollEntry $entry
     */
    public function map($entry): array
    {
        return array_map(fn ($col) => $col['value']($entry), $this->columns());
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
