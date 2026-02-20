<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Incident;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FASE 5.4: Controller for exporting reports to Excel/CSV and PDF.
 *
 * Provides export functionality for various attendance reports.
 */
class ReportExportController extends Controller implements HasMiddleware
{
    /**
     * Get the middleware that should be assigned to the controller.
     */
    public static function middleware(): array
    {
        return [
            new Middleware(function ($request, $next) {
                $user = $request->user();
                if (!$user->hasPermissionTo('reports.view_all')
                    && !$user->hasPermissionTo('reports.view_team')
                    && !$user->hasPermissionTo('reports.view_own')) {
                    abort(403);
                }
                return $next($request);
            }),
        ];
    }

    /**
     * Export daily report to CSV.
     */
    public function exportDaily(Request $request): StreamedResponse
    {
        $date = $request->get('date', Carbon::today()->toDateString());

        $records = AttendanceRecord::with(['employee.department'])
            ->where('work_date', $date)
            ->get();

        return $this->exportCsv(
            "reporte_diario_{$date}.csv",
            ['Empleado', 'No. Empleado', 'Departamento', 'Entrada', 'Salida', 'Horas', 'Horas Extra', 'Estado'],
            $records->map(fn ($record) => [
                $record->employee?->full_name ?? '-',
                $record->employee?->employee_number ?? '-',
                $record->employee?->department?->name ?? '-',
                $record->check_in ?? '-',
                $record->check_out ?? '-',
                $record->worked_hours ?? 0,
                $record->overtime_hours ?? 0,
                $this->translateStatus($record->status),
            ])->toArray()
        );
    }

    /**
     * Export weekly report to CSV.
     */
    public function exportWeekly(Request $request): StreamedResponse
    {
        $startDate = $request->get('start_date', Carbon::now()->startOfWeek()->toDateString());
        $endDate = Carbon::parse($startDate)->endOfWeek()->toDateString();

        $records = AttendanceRecord::with(['employee.department'])
            ->whereBetween('work_date', [$startDate, $endDate])
            ->orderBy('employee_id')
            ->orderBy('work_date')
            ->get();

        // Group by employee
        $byEmployee = $records->groupBy('employee_id');
        $data = [];

        foreach ($byEmployee as $employeeRecords) {
            $employee = $employeeRecords->first()->employee;
            $data[] = [
                $employee?->full_name ?? '-',
                $employee?->employee_number ?? '-',
                $employee?->department?->name ?? '-',
                $employeeRecords->sum('worked_hours'),
                $employeeRecords->sum('overtime_hours'),
                $employeeRecords->where('status', 'present')->count(),
                $employeeRecords->where('status', 'late')->count(),
                $employeeRecords->where('status', 'absent')->count(),
            ];
        }

        return $this->exportCsv(
            "reporte_semanal_{$startDate}.csv",
            ['Empleado', 'No. Empleado', 'Departamento', 'Horas Trabajadas', 'Horas Extra', 'Dias Presentes', 'Retardos', 'Ausencias'],
            $data
        );
    }

    /**
     * Export monthly report to CSV.
     */
    public function exportMonthly(Request $request): StreamedResponse
    {
        $month = $request->get('month', Carbon::now()->month);
        $year = $request->get('year', Carbon::now()->year);
        $startDate = Carbon::create($year, $month, 1)->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();

        $records = AttendanceRecord::with(['employee.department'])
            ->whereBetween('work_date', [$startDate, $endDate])
            ->orderBy('employee_id')
            ->get();

        // Group by employee
        $byEmployee = $records->groupBy('employee_id');
        $data = [];

        foreach ($byEmployee as $employeeRecords) {
            $employee = $employeeRecords->first()->employee;
            $data[] = [
                $employee?->full_name ?? '-',
                $employee?->employee_number ?? '-',
                $employee?->department?->name ?? '-',
                $employeeRecords->sum('worked_hours'),
                $employeeRecords->sum('overtime_hours'),
                $employeeRecords->where('status', 'present')->count(),
                $employeeRecords->where('status', 'late')->count(),
                $employeeRecords->where('status', 'absent')->count(),
                $employeeRecords->sum('late_minutes'),
            ];
        }

        return $this->exportCsv(
            "reporte_mensual_{$year}_{$month}.csv",
            ['Empleado', 'No. Empleado', 'Departamento', 'Horas Trabajadas', 'Horas Extra', 'Dias Presentes', 'Retardos', 'Ausencias', 'Minutos Retardo'],
            $data
        );
    }

    /**
     * Export absences report to CSV.
     */
    public function exportAbsences(Request $request): StreamedResponse
    {
        $startDate = $request->get('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->get('end_date', Carbon::now()->endOfMonth()->toDateString());

        $records = AttendanceRecord::with(['employee.department'])
            ->whereBetween('work_date', [$startDate, $endDate])
            ->where('status', 'absent')
            ->orderBy('work_date')
            ->orderBy('employee_id')
            ->get();

        return $this->exportCsv(
            "reporte_ausencias_{$startDate}_{$endDate}.csv",
            ['Fecha', 'Empleado', 'No. Empleado', 'Departamento'],
            $records->map(fn ($record) => [
                $record->work_date,
                $record->employee?->full_name ?? '-',
                $record->employee?->employee_number ?? '-',
                $record->employee?->department?->name ?? '-',
            ])->toArray()
        );
    }

    /**
     * Export late arrivals report to CSV.
     */
    public function exportLateArrivals(Request $request): StreamedResponse
    {
        $startDate = $request->get('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->get('end_date', Carbon::now()->endOfMonth()->toDateString());

        $records = AttendanceRecord::with(['employee.department'])
            ->whereBetween('work_date', [$startDate, $endDate])
            ->where('status', 'late')
            ->orderBy('work_date')
            ->orderBy('employee_id')
            ->get();

        return $this->exportCsv(
            "reporte_retardos_{$startDate}_{$endDate}.csv",
            ['Fecha', 'Empleado', 'No. Empleado', 'Departamento', 'Entrada', 'Minutos Retardo'],
            $records->map(fn ($record) => [
                $record->work_date,
                $record->employee?->full_name ?? '-',
                $record->employee?->employee_number ?? '-',
                $record->employee?->department?->name ?? '-',
                $record->check_in ?? '-',
                $record->late_minutes ?? 0,
            ])->toArray()
        );
    }

    /**
     * Export vacation balance report to CSV.
     */
    public function exportVacationBalance(Request $request): StreamedResponse
    {
        $employees = Employee::with('department')
            ->active()
            ->orderBy('full_name')
            ->get();

        return $this->exportCsv(
            'reporte_saldo_vacaciones.csv',
            ['Empleado', 'No. Empleado', 'Departamento', 'Dias Asignados', 'Dias Usados', 'Saldo'],
            $employees->map(fn ($employee) => [
                $employee->full_name,
                $employee->employee_number,
                $employee->department?->name ?? '-',
                $employee->vacation_days_entitled ?? 0,
                $employee->vacation_days_used ?? 0,
                ($employee->vacation_days_entitled ?? 0) - ($employee->vacation_days_used ?? 0),
            ])->toArray()
        );
    }

    /**
     * Export incidents report to CSV.
     */
    public function exportIncidents(Request $request): StreamedResponse
    {
        $startDate = $request->get('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->get('end_date', Carbon::now()->endOfMonth()->toDateString());

        $incidents = Incident::with(['employee.department', 'incidentType'])
            ->whereBetween('start_date', [$startDate, $endDate])
            ->orderBy('start_date')
            ->get();

        return $this->exportCsv(
            "reporte_incidencias_{$startDate}_{$endDate}.csv",
            ['Empleado', 'No. Empleado', 'Departamento', 'Tipo', 'Inicio', 'Fin', 'Dias', 'Estado'],
            $incidents->map(fn ($incident) => [
                $incident->employee?->full_name ?? '-',
                $incident->employee?->employee_number ?? '-',
                $incident->employee?->department?->name ?? '-',
                $incident->incidentType?->name ?? '-',
                $incident->start_date?->format('Y-m-d'),
                $incident->end_date?->format('Y-m-d'),
                $incident->days_count,
                $this->translateIncidentStatus($incident->status),
            ])->toArray()
        );
    }

    /**
     * Export overtime report to CSV.
     */
    public function exportOvertime(Request $request): StreamedResponse
    {
        $startDate = $request->get('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->get('end_date', Carbon::now()->endOfMonth()->toDateString());

        $records = AttendanceRecord::with(['employee.department'])
            ->whereBetween('work_date', [$startDate, $endDate])
            ->where('overtime_hours', '>', 0)
            ->orderBy('work_date')
            ->orderBy('employee_id')
            ->get();

        return $this->exportCsv(
            "reporte_horas_extra_{$startDate}_{$endDate}.csv",
            ['Fecha', 'Empleado', 'No. Empleado', 'Departamento', 'Horas Trabajadas', 'Horas Extra'],
            $records->map(fn ($record) => [
                $record->work_date,
                $record->employee?->full_name ?? '-',
                $record->employee?->employee_number ?? '-',
                $record->employee?->department?->name ?? '-',
                $record->worked_hours ?? 0,
                $record->overtime_hours ?? 0,
            ])->toArray()
        );
    }

    /**
     * Create a CSV export response.
     *
     * @param string $filename Export filename
     * @param array $headers Column headers
     * @param array $data Data rows
     * @return StreamedResponse CSV file download response
     */
    private function exportCsv(string $filename, array $headers, array $data): StreamedResponse
    {
        $callback = function () use ($headers, $data) {
            $file = fopen('php://output', 'w');

            // Add BOM for Excel UTF-8 compatibility
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));

            // Write headers
            fputcsv($file, $headers);

            // Write data rows
            foreach ($data as $row) {
                fputcsv($file, $row);
            }

            fclose($file);
        };

        return Response::streamDownload($callback, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Translate attendance status to Spanish.
     */
    private function translateStatus(string $status): string
    {
        return match ($status) {
            'present' => 'Presente',
            'late' => 'Retardo',
            'absent' => 'Ausente',
            'partial' => 'Parcial',
            'vacation' => 'Vacaciones',
            'sick_leave' => 'Incapacidad',
            'holiday' => 'Festivo',
            default => $status,
        };
    }

    /**
     * Translate incident status to Spanish.
     */
    private function translateIncidentStatus(string $status): string
    {
        return match ($status) {
            'pending' => 'Pendiente',
            'approved' => 'Aprobada',
            'rejected' => 'Rechazada',
            default => $status,
        };
    }
}
