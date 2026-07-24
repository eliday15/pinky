<?php

namespace App\Exports\Contpaqi;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Hoja "Catalogo": referencia del mapeo columna→concepto de CONTPAQi (IDs y
 * códigos SAT que dio Luis). Se incluye tal cual para que el archivo generado
 * sea autoexplicativo, igual que la plantilla original.
 */
class ContpaqiCatalogoSheet implements FromArray, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    public function title(): string
    {
        return 'Catalogo';
    }

    public function headings(): array
    {
        return ['CAMPO EN PLANTILLA', 'ID CONCEPTO / INCIDENCIA', 'TIPO', 'NOMBRE EN CONTPAQI', 'FORMA DE CAPTURA', 'IMPORTAR'];
    }

    /**
     * @return list<array<int, mixed>>
     */
    public function array(): array
    {
        return [
            ['AUSENCIAS (DIAS)', 'Incidencia por confirmar', 'Incidencia', 'Ausencias', 'Días', 'Sí'],
            ['VACACIONES (DIAS)', 'Incidencia por confirmar', 'Incidencia', 'Vacaciones', 'Días', 'Sí'],
            ['INCAPACIDAD (DIAS)', 'Incidencia por confirmar', 'Incidencia', 'Incapacidades', 'Días', 'Sí'],
            ['H.E. DOBLES (HORAS)', 'Incidencia 02', 'Incidencia', 'Horas extras dobles', 'Horas', 'Sí'],
            ['H.E. TRIPLES (HORAS)', 'Incidencia 03', 'Incidencia', 'Horas extras triples', 'Horas', 'Sí'],
            ['BONO PUNTUALIDAD ($)', 15, 'Percepción', 'Bono puntualidad', 'Importe', 'Sí'],
            ['INCENTIVO PRODUCTIVIDAD ($)', 7, 'Percepción', 'Incentivo productividad', 'Importe', 'Sí'],
            ['COMISIONES ($)', 6, 'Percepción', 'Comisiones', 'Importe', 'Sí'],
            ['GRATIFICACION ($)', 12, 'Percepción', 'Gratificación', 'Importe', 'Sí'],
            ['COMPENSACION ($)', 13, 'Percepción', 'Compensación', 'Importe', 'Sí'],
            ['PREMIO EFICIENCIA ($)', 14, 'Percepción', 'Premios eficiencia', 'Importe', 'Sí'],
            ['DIA FESTIVO / DESCANSO (DIAS)', 11, 'Percepción', 'Día festivo / descanso', 'Días', 'Sí'],
            ['DESCANSO LABORADO (DIAS)', 54, 'Percepción', 'Días de descanso laborados', 'Días', 'Sí'],
            ['FONACOT ($)', 61, 'Deducción', 'Préstamo FONACOT', 'Importe', 'Revisar'],
            ['PRESTAMO EMPRESA ($)', 64, 'Deducción', 'Préstamo empresa', 'Importe', 'Sí'],
            ['ANTICIPO SUELDO ($)', 66, 'Deducción', 'Anticipo sueldo', 'Importe', 'Sí'],
            ['DEDUCCION GENERAL ($)', 70, 'Deducción', 'Deducción general', 'Importe', 'Sí'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
