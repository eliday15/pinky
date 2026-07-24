<?php

namespace App\Exports\Contpaqi;

use App\Models\PayrollPeriod;
use App\Services\Contpaqi\ContpaqiImportBuilder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Archivo de importación a CONTPAQi Nóminas (opción A que eligió Luis): el
 * sistema GENERA el Excel que su Importador de movimientos acepta y él lo carga
 * con un clic. Libro con 3 hojas — Movimientos (datos de la semana), Catalogo
 * (mapeo de conceptos) e Instrucciones — igual que la plantilla original.
 */
class ContpaqiImportExport implements WithMultipleSheets
{
    use Exportable;

    public function __construct(private PayrollPeriod $period) {}

    /**
     * @return list<object>
     */
    public function sheets(): array
    {
        return [
            new ContpaqiMovimientosSheet($this->period, new ContpaqiImportBuilder),
            new ContpaqiCatalogoSheet,
            new ContpaqiInstruccionesSheet,
        ];
    }
}
