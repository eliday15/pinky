<?php

namespace App\Exports\Contpaqi;

use App\Models\PayrollPeriod;
use App\Services\Contpaqi\ContpaqiImportBuilder;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Hoja "Movimientos" del archivo de importación a CONTPAQi: una fila por
 * empleado con los movimientos variables de la semana.
 */
class ContpaqiMovimientosSheet implements FromArray, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    public function __construct(
        private PayrollPeriod $period,
        private ContpaqiImportBuilder $builder
    ) {}

    public function title(): string
    {
        return 'Movimientos';
    }

    public function headings(): array
    {
        return ContpaqiImportBuilder::HEADERS;
    }

    /**
     * @return list<array<int, mixed>>
     */
    public function array(): array
    {
        return $this->builder->rows($this->period);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
