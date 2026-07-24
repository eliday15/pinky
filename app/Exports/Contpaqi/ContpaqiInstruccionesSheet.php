<?php

namespace App\Exports\Contpaqi;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Hoja "Instrucciones": las reglas de uso de la plantilla (de Luis) para que el
 * archivo generado se documente solo.
 */
class ContpaqiInstruccionesSheet implements FromArray, ShouldAutoSize, WithTitle
{
    public function title(): string
    {
        return 'Instrucciones';
    }

    /**
     * @return list<array<int, string>>
     */
    public function array(): array
    {
        return [
            ['IMPORTACIÓN SEMANAL DE NÓMINA – VESTIDOS PINKY'],
            [''],
            ['Uso de la plantilla'],
            ['1. Una fila por empleado.'],
            ['2. El código debe coincidir exactamente con el catálogo de empleados de CONTPAQi Nóminas.'],
            ['3. Solo movimientos variables. No incluye ISR, IMSS, sueldo, séptimo día, prima vacacional ni ajuste al neto.'],
            ['4. Los movimientos que no correspondan van en cero.'],
            ['5. No se duplica el mismo empleado dentro de la misma semana.'],
            ['6. Antes de importar, valide en CONTPAQi; ningún registro con error debe cargarse.'],
            ['7. Este archivo es compatible con el proceso de Excel/Pre-nómina de CONTPAQi; no escribe directamente en sus tablas internas.'],
            [''],
            ['Generado automáticamente desde el sistema de nómina Pinky.'],
        ];
    }
}
