<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Desayunos entregados en un rango: una fila por desayuno, con la misma
 * información que la pantalla de Desayunos y el total al pie.
 */
class BreakfastClaimsExport implements FromArray, WithHeadings, WithStyles, ShouldAutoSize
{
    /**
     * @param  Collection<int, array<string, mixed>>  $claims  Filas ya mapeadas por BreakfastController::claimsBetween()
     */
    public function __construct(private readonly Collection $claims) {}

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return ['Fecha', 'Hora', 'No. empleado', 'Empleado', 'Departamento', 'Costo', 'Rostro', 'Registró'];
    }

    /**
     * @return list<list<mixed>>
     */
    public function array(): array
    {
        $rows = $this->claims->map(fn (array $claim) => [
            $claim['claim_date'],
            substr($claim['claimed_at'], 11, 5),
            $claim['employee_number'],
            $claim['employee_name'],
            $claim['department'],
            $claim['unit_cost'],
            $claim['face_match_distance'],
            $claim['registered_by'],
        ])->values()->all();

        $rows[] = array_fill(0, 8, null);
        $rows[] = ['Total', $this->claims->count().' desayunos', null, null, null, round($this->claims->sum('unit_cost'), 2), null, null];

        return $rows;
    }

    /**
     * Encabezado y renglón de total en negritas; costo con formato de pesos.
     *
     * @return array<int|string, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        $lastRow = $this->claims->count() + 3;
        $sheet->getStyle("F2:F{$lastRow}")->getNumberFormat()->setFormatCode('"$"#,##0.00');

        return [
            1 => ['font' => ['bold' => true]],
            $lastRow => ['font' => ['bold' => true]],
        ];
    }
}
