<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/** The same employee/concept totals used by the on-screen report. */
class OvertimeSummaryExport implements FromArray, ShouldAutoSize, WithColumnFormatting, WithHeadings
{
    public function __construct(private readonly array $report) {}

    public function headings(): array
    {
        return ['Desde', 'Hasta', 'Número', 'Empleado', 'Departamento', 'Código', 'Concepto', 'Horas', 'Cantidad', 'Importe MXN', 'Observación'];
    }

    public function array(): array
    {
        $rows = [];
        foreach ($this->report['byEmployee'] as $row) {
            foreach ($row['concepts'] as $concept) {
                $rows[] = [
                    $this->report['startDate'], $this->report['endDate'],
                    $row['employee']['employee_number'], $row['employee']['full_name'], $row['employee']['department']['name'] ?? '',
                    $concept['code'], $concept['name'], $concept['hours'], $concept['quantity'], $concept['amount'],
                    $concept['missing_rate'] ? 'Sin tarifa configurada' : '',
                ];
            }
            $rows[] = ['', '', '', $row['employee']['full_name'], '', '', 'TOTAL EMPLEADO', '', '', $row['estimated_cost'], $row['estimate_incomplete'] ? 'Importe incompleto: revisar tarifas' : ''];
        }
        $rows[] = ['', '', '', '', '', '', 'TOTAL GENERAL', '', '', $this->report['summary']['total_estimated_cost'], $this->report['summary']['estimate_incomplete'] ? 'Importe incompleto: revisar tarifas' : ''];

        return $rows;
    }

    public function columnFormats(): array
    {
        return ['J' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1];
    }
}
