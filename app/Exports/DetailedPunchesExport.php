<?php

namespace App\Exports;

use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Exporta las checadas DETALLADAS: una fila por cada marca del día (no solo
 * entrada/salida), incluyendo todas las marcaciones —turno, comida y velada—,
 * con la hora en formato de 12 horas AM/PM (petición de Dani 2026-06-30 para
 * que cada encargado descargue las checadas de su personal).
 *
 * Columnas: No. Empleado, Nombre, Departamento, Fecha, Hora, Tipo, Método.
 */
class DetailedPunchesExport implements FromArray, WithStyles, ShouldAutoSize
{
    use Exportable;

    public function __construct(
        private string $startDate,
        private string $endDate,
        private ?int $departmentId = null,
        private ?Collection $scopedEmployeeIds = null,
    ) {}

    public function array(): array
    {
        $rows = [];
        $rows[] = ['No. Empleado', 'Nombre', 'Departamento', 'Fecha', 'Hora', 'Tipo', 'Método'];

        $employeeQuery = Employee::active()
            ->with(['department'])
            ->with(['attendanceRecords' => function ($q) {
                $q->whereBetween('work_date', [$this->startDate, $this->endDate])
                    ->orderBy('work_date');
            }]);

        if ($this->scopedEmployeeIds !== null) {
            $employeeQuery->whereIn('id', $this->scopedEmployeeIds);
        }

        if ($this->departmentId) {
            $employeeQuery->where('department_id', $this->departmentId);
        }

        foreach ($employeeQuery->orderBy('full_name')->get() as $employee) {
            foreach ($employee->attendanceRecords->sortBy('work_date') as $record) {
                $punches = $record->raw_punches ?? [];

                // Registros viejos sin marcas crudas: se sintetiza entrada/salida
                // a partir de check_in/check_out para no perderlos.
                if (empty($punches)) {
                    $punches = array_values(array_filter([
                        $record->check_in ? ['time' => $record->check_in, 'type' => 'in'] : null,
                        $record->check_out ? ['time' => $record->check_out, 'type' => 'out'] : null,
                    ]));
                }

                $date = Carbon::parse($record->work_date)->format('d/m/Y');

                foreach ($punches as $punch) {
                    $time = $punch['time'] ?? null;
                    if (! $time) {
                        continue;
                    }

                    $rows[] = [
                        $employee->employee_number,
                        $employee->full_name,
                        $employee->department?->name ?? '-',
                        $date,
                        $this->formatTime12h($time),
                        $this->punchTypeLabel($punch['type'] ?? 'punch'),
                        $punch['method'] ?? '-',
                    ];
                }
            }
        }

        return $rows;
    }

    /** 'HH:MM:SS' -> '10:04 p. m.' (12 h, es-MX), igual que la vista de checadas. */
    private function formatTime12h(string $time): string
    {
        $carbon = Carbon::parse($time);

        return $carbon->format('g:i') . ' ' . ($carbon->hour < 12 ? 'a. m.' : 'p. m.');
    }

    /** Etiqueta legible del tipo de marca (igual que en Autorizaciones/Show.vue). */
    private function punchTypeLabel(?string $type): string
    {
        return [
            'in' => 'entrada',
            'out' => 'salida',
            'lunch_out' => 'sale a comer',
            'lunch_in' => 'regresa de comer',
            'punch' => 'marca',
        ][$type] ?? ($type ?: 'marca');
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
