<?php

namespace App\Exports;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export attendance records for a date range to Excel.
 *
 * Includes employee schedule information alongside actual attendance data.
 */
class AttendanceRangeExport implements FromCollection, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    use Exportable;

    private string $startDate;

    private string $endDate;

    private ?int $departmentId;

    /** @var Collection|null Scoped employee IDs (for permission filtering). Null = all active. */
    private ?Collection $scopedEmployeeIds;

    public function __construct(
        string $startDate,
        string $endDate,
        ?int $departmentId = null,
        ?Collection $scopedEmployeeIds = null
    ) {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->departmentId = $departmentId;
        $this->scopedEmployeeIds = $scopedEmployeeIds;
    }

    /**
     * Get the collection of attendance records for the date range.
     */
    public function collection(): Collection
    {
        $activeEmployeeIds = $this->scopedEmployeeIds ?? Employee::active()->pluck('id');

        $query = AttendanceRecord::with(['employee.department', 'employee.schedule'])
            ->whereBetween('work_date', [$this->startDate, $this->endDate])
            ->whereIn('employee_id', $activeEmployeeIds)
            ->orderBy('work_date')
            ->orderBy('employee_id');

        if ($this->departmentId) {
            $deptEmployeeIds = Employee::active()
                ->where('department_id', $this->departmentId)
                ->pluck('id');
            $query->whereIn('employee_id', $deptEmployeeIds);
        }

        return $query->get();
    }

    /**
     * Define column headings.
     */
    public function headings(): array
    {
        return [
            'Fecha',
            'No. Empleado',
            'Nombre',
            'Departamento',
            'Horario',
            'Entrada Programada',
            'Salida Programada',
            'Entrada Real',
            'Salida Real',
            'Horas Trabajadas',
            'Horas Extra',
            'Min. Retardo',
            'Estado',
        ];
    }

    /**
     * Map each attendance record to a row.
     *
     * @param AttendanceRecord $record
     */
    public function map($record): array
    {
        $schedule = $record->employee?->schedule;
        $statusLabels = [
            'present' => 'Presente',
            'late' => 'Retardo',
            'absent' => 'Ausente',
            'partial' => 'Parcial',
            'holiday' => 'Festivo',
            'vacation' => 'Vacaciones',
            'sick_leave' => 'Incapacidad',
            'permission' => 'Permiso',
        ];

        return [
            $record->work_date->format('Y-m-d'),
            $record->employee?->employee_number ?? '-',
            $record->employee?->full_name ?? '-',
            $record->employee?->department?->name ?? '-',
            $schedule?->name ?? '-',
            $schedule ? substr($schedule->entry_time ?? '', 0, 5) : '-',
            $schedule ? substr($schedule->exit_time ?? '', 0, 5) : '-',
            $record->check_in ? substr($record->check_in, 0, 5) : '-',
            $record->check_out ? substr($record->check_out, 0, 5) : '-',
            (float) ($record->worked_hours ?? 0),
            (float) ($record->overtime_hours ?? 0),
            (int) ($record->late_minutes ?? 0),
            $statusLabels[$record->status] ?? $record->status,
        ];
    }

    /**
     * Apply styles — bold header row.
     */
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
