<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Excel del resumen semanal (Luis, 2026-07-30): una sola hoja con las 4
 * secciones apiladas (Vacaciones, Faltas, Faltas por retardo, Incapacidades),
 * cada una con encabezado Nombre / Fecha / Observaciones — el mismo formato del
 * Excel que armaba a mano.
 */
class WeeklySummaryExport implements FromArray, ShouldAutoSize, WithStyles, WithTitle
{
    use Exportable;

    /** @var list<int> filas (1-indexed) con título de sección — para el estilo. */
    private array $sectionTitleRows = [];

    /** @var list<int> filas (1-indexed) con encabezado de columnas — para el estilo. */
    private array $headerRows = [];

    public function __construct(
        private array $summary,
        private string $from,
        private string $to,
    ) {}

    public function title(): string
    {
        return 'Resumen';
    }

    public function array(): array
    {
        $rows = [];
        $rows[] = ['PINKY — RESUMEN SEMANAL'];
        $rows[] = ['Del '.$this->fmt($this->from).' al '.$this->fmt($this->to)];
        $rows[] = [''];

        $sections = [
            ['VACACIONES', $this->summary['vacaciones'] ?? []],
            ['FALTAS', $this->summary['faltas'] ?? []],
            ['FALTAS POR RETARDO', $this->summary['retardos'] ?? []],
            ['INCAPACIDADES', $this->summary['incapacidades'] ?? []],
        ];

        foreach ($sections as [$title, $items]) {
            $this->sectionTitleRows[] = count($rows) + 1;
            $rows[] = [$title.' ('.count($items).')'];

            $this->headerRows[] = count($rows) + 1;
            $rows[] = ['NOMBRE', 'FECHA', 'OBSERVACIONES'];

            if (empty($items)) {
                $rows[] = ['— sin registros —', '', ''];
            } else {
                foreach ($items as $item) {
                    $rows[] = [
                        $item['name'] ?? '',
                        $item['date'] ?? '',
                        $item['observaciones'] ?? '',
                    ];
                }
            }

            $rows[] = [''];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        $styles = [
            1 => ['font' => ['bold' => true, 'size' => 14]],
            2 => ['font' => ['italic' => true, 'size' => 11]],
        ];

        foreach ($this->sectionTitleRows as $row) {
            $styles[$row] = ['font' => ['bold' => true, 'size' => 12]];
        }

        foreach ($this->headerRows as $row) {
            $sheet->getStyle("A{$row}:C{$row}")->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB('F3D9E6'); // rosa suave
            $sheet->getStyle("A{$row}:C{$row}")->getFont()->setBold(true);
        }

        return $styles;
    }

    private function fmt(string $date): string
    {
        [$y, $m, $d] = explode('-', $date);

        return "{$d}/{$m}/{$y}";
    }
}
