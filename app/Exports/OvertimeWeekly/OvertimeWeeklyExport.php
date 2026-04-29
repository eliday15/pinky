<?php

namespace App\Exports\OvertimeWeekly;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Generic Excel export for the weekly overtime report.
 *
 * The actual layout (column count, semantics) is decided by the Template
 * passed in via the controller — this class only handles header styling
 * and grid borders.
 */
class OvertimeWeeklyExport implements FromArray, ShouldAutoSize, WithEvents, WithStyles
{
    use Exportable;

    public function __construct(
        private readonly array $headings,
        private readonly array $rows,
        private readonly string $title,
    ) {}

    public function array(): array
    {
        return array_merge(
            [[$this->title], [], $this->headings],
            $this->rows,
        );
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true, 'size' => 14]],
            3 => ['font' => ['bold' => true]],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $columnCount = max(count($this->headings), 1);
                $rowCount = count($this->rows) + 3;

                $highestColumn = $sheet->getCellByColumnAndRow($columnCount, 1)->getColumn();
                $range = "A3:{$highestColumn}{$rowCount}";

                $sheet->getStyle($range)->applyFromArray([
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => '000000'],
                        ],
                    ],
                    'alignment' => [
                        'vertical' => Alignment::VERTICAL_CENTER,
                    ],
                ]);

                $sheet->getStyle("A3:{$highestColumn}3")->applyFromArray([
                    'fill' => [
                        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'F3F4F6'],
                    ],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                    ],
                ]);
            },
        ];
    }
}
