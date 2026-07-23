<?php

namespace App\Exports\AccountantReport;

use App\Services\Reports\AccountantReportService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Una hoja del "Reporte al contador" = una empresa (VP / AVL / POR FUERA), con
 * sus secciones apiladas (título en negrita + encabezados + filas). Reproduce
 * el formato del Excel que el contador ya usa.
 */
class AccountantEmpresaSheet implements FromArray, ShouldAutoSize, WithStyles, WithTitle
{
    /** @var list<array<int, string>> */
    private array $rows = [];

    /** @var list<int> filas (1-based) que van en negrita */
    private array $boldRows = [];

    /**
     * @param  array<string, list<array<int, string>>>  $sections  sección => filas
     */
    public function __construct(
        private string $empresaLabel,
        private string $weekLabel,
        private array $sections
    ) {
        $this->buildRows();
    }

    public function title(): string
    {
        return $this->empresaLabel;
    }

    /**
     * @return list<array<int, string>>
     */
    public function array(): array
    {
        return $this->rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        $styles = [];
        foreach ($this->boldRows as $row) {
            $styles[$row] = ['font' => ['bold' => true]];
        }

        return $styles;
    }

    private function buildRows(): void
    {
        $push = function (array $row): int {
            $this->rows[] = $row;

            return count($this->rows);
        };

        $this->boldRows[] = $push([$this->empresaLabel]);
        $push([$this->weekLabel]);
        $push(['']);

        foreach (AccountantReportService::SECTIONS as $key => $meta) {
            $this->boldRows[] = $push([$meta['title']]);
            $this->boldRows[] = $push($meta['headers']);

            foreach ($this->sections[$key] ?? [] as $dataRow) {
                $push($dataRow);
            }

            $push(['']);
        }
    }
}
