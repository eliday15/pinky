<?php

namespace App\Exports\AccountantReport;

use App\Models\Employee;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * "Reporte al contador": un libro de Excel con una hoja por empresa
 * (VP / AVL / POR FUERA), cada una con las secciones semanales del contador.
 */
class AccountantReportExport implements WithMultipleSheets
{
    use Exportable;

    /**
     * @param  array<string, array<string, list<array<int, string>>>>  $report  empresa => sección => filas
     */
    public function __construct(
        private array $report,
        private string $weekLabel
    ) {}

    /**
     * @return list<AccountantEmpresaSheet>
     */
    public function sheets(): array
    {
        $sheets = [];
        foreach (Employee::EMPRESAS as $key => $label) {
            $sheets[] = new AccountantEmpresaSheet($label, $this->weekLabel, $this->report[$key] ?? []);
        }

        return $sheets;
    }
}
