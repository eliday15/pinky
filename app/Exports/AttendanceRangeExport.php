<?php

namespace App\Exports;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export attendance records pivoted by employee (one row per employee).
 *
 * Columns: Employee info + per-date Entrada/Salida/Horas/Estado.
 */
class AttendanceRangeExport implements FromArray, WithStyles, ShouldAutoSize
{
    use Exportable;

    private string $startDate;

    private string $endDate;

    private ?int $departmentId;

    private ?Collection $scopedEmployeeIds;

    /** @var array Dates in the range */
    private array $dates = [];

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
        $this->buildDates();
    }

    /**
     * Build the list of dates in the range.
     */
    private function buildDates(): void
    {
        $current = Carbon::parse($this->startDate);
        $end = Carbon::parse($this->endDate);

        while ($current->lte($end)) {
            $this->dates[] = $current->toDateString();
            $current->addDay();
        }
    }

    /**
     * Build the full array: header row + data rows.
     */
    public function array(): array
    {
        $rows = [];

        // Header row
        $header = ['No. Empleado', 'Nombre', 'Departamento', 'Horario'];
        foreach ($this->dates as $date) {
            $label = Carbon::parse($date)->locale('es')->isoFormat('dd DD/MM');
            $header[] = "{$label} Entrada";
            $header[] = "{$label} Salida";
            $header[] = "{$label} Horas";
            $header[] = "{$label} Estado";
        }
        $rows[] = $header;

        // Query employees with attendance
        $employeeQuery = Employee::active()
            ->with(['department', 'schedule'])
            ->with(['attendanceRecords' => function ($q) {
                $q->whereBetween('work_date', [$this->startDate, $this->endDate]);
            }]);

        if ($this->scopedEmployeeIds !== null) {
            $employeeQuery->whereIn('id', $this->scopedEmployeeIds);
        }

        if ($this->departmentId) {
            $employeeQuery->where('department_id', $this->departmentId);
        }

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

        $employees = $employeeQuery->orderBy('full_name')->get();

        foreach ($employees as $employee) {
            $attendanceByDate = $employee->attendanceRecords
                ->keyBy(fn ($r) => $r->work_date->format('Y-m-d'));

            $schedule = $employee->schedule;
            $scheduleName = $schedule
                ? substr($schedule->entry_time ?? '', 0, 5) . ' - ' . substr($schedule->exit_time ?? '', 0, 5)
                : '-';

            $row = [
                $employee->employee_number,
                $employee->full_name,
                $employee->department?->name ?? '-',
                $scheduleName,
            ];

            foreach ($this->dates as $date) {
                $record = $attendanceByDate->get($date);
                if ($record) {
                    $row[] = $record->check_in ? substr($record->check_in, 0, 5) : '-';
                    $row[] = $record->check_out ? substr($record->check_out, 0, 5) : '-';
                    $row[] = (float) ($record->worked_hours ?? 0);
                    $row[] = $statusLabels[$record->status] ?? $record->status;
                } else {
                    $row[] = '-';
                    $row[] = '-';
                    $row[] = 0;
                    $row[] = '-';
                }
            }

            $rows[] = $row;
        }

        return $rows;
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
