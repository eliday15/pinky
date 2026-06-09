<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesReportEmployees;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Incident;
use App\Models\SystemSetting;
use App\Services\LateAbsenceService;
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
    use ScopesReportEmployees;

    /**
     * Get the middleware that should be assigned to the controller.
     */
    public static function middleware(): array
    {
        return [
            new Middleware(function ($request, $next) {
                $user = $request->user();
                if (! $user->hasPermissionTo('reports.view_all')
                    && ! $user->hasPermissionTo('reports.view_team')
                    && ! $user->hasPermissionTo('reports.view_own')) {
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
            ->whereIn('employee_id', $this->scopedActiveEmployeeIds())
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
            ->whereIn('employee_id', $this->scopedActiveEmployeeIds())
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
            ->whereIn('employee_id', $this->scopedActiveEmployeeIds())
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
            ->whereIn('employee_id', $this->scopedActiveEmployeeIds())
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
            ->whereIn('employee_id', $this->scopedActiveEmployeeIds())
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
            ->whereIn('id', $this->scopedActiveEmployeeIds())
            ->orderBy('full_name')
            ->get();

        return $this->exportCsv(
            'reporte_saldo_vacaciones.csv',
            ['Empleado', 'No. Empleado', 'Departamento', 'Dias Asignados', 'Dias Usados', 'Dias Apartados', 'Saldo'],
            $employees->map(fn ($employee) => [
                $employee->full_name,
                $employee->employee_number,
                $employee->department?->name ?? '-',
                $employee->vacation_days_entitled ?? 0,
                $employee->vacation_days_used ?? 0,
                $employee->vacation_days_reserved ?? 0,
                // Mismo saldo que el modelo y el reporte web: resta también
                // los días apartados (auditoría #83).
                $employee->vacation_days_remaining,
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
            ->whereIn('employee_id', $this->scopedActiveEmployeeIds())
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

        // Detectadas vs autorizadas: la columna "a pagar" es la que usa la
        // nómina (overtime_authorized_hours) — el CSV concilia con el recibo.
        $records = AttendanceRecord::with(['employee.department'])
            ->whereIn('employee_id', $this->scopedActiveEmployeeIds())
            ->whereBetween('work_date', [$startDate, $endDate])
            ->where(function ($q) {
                $q->where('overtime_hours', '>', 0)
                    ->orWhere('overtime_authorized_hours', '>', 0);
            })
            ->orderBy('work_date')
            ->orderBy('employee_id')
            ->get();

        return $this->exportCsv(
            "reporte_horas_extra_{$startDate}_{$endDate}.csv",
            ['Fecha', 'Empleado', 'No. Empleado', 'Departamento', 'Horas Trabajadas', 'Horas Extra Detectadas', 'Horas Extra Autorizadas (a pagar)'],
            $records->map(fn ($record) => [
                $record->work_date,
                $record->employee?->full_name ?? '-',
                $record->employee?->employee_number ?? '-',
                $record->employee?->department?->name ?? '-',
                $record->worked_hours ?? 0,
                $record->overtime_hours ?? 0,
                $record->overtime_authorized_hours ?? 0,
            ])->toArray()
        );
    }

    /**
     * Export faltas report to CSV.
     */
    public function exportFaltas(Request $request): StreamedResponse
    {
        $startDate = $request->get('start_date', Carbon::now()->startOfWeek()->toDateString());
        $endDate = $request->get('end_date', Carbon::now()->endOfWeek()->toDateString());
        $activeEmployeeIds = $this->scopedActiveEmployeeIds();
        $maxLateBeforeAbsence = (int) SystemSetting::get('max_late_minutes_before_absence', 60);
        $earlyDepartureThreshold = (int) SystemSetting::get('early_departure_absence_threshold', 30);
        $earlyDepartureIsAbsence = (bool) SystemSetting::get('early_departure_is_absence', true);

        // Direct faltas ('employee.schedule' eager-loaded for the expected
        // entry/exit times used in the Observaciones column)
        $absentRecords = AttendanceRecord::with(['employee.department', 'employee.schedule'])
            ->whereBetween('work_date', [$startDate, $endDate])
            ->whereIn('employee_id', $activeEmployeeIds)
            ->where('status', 'absent')
            ->get();

        // Retardo records
        $lateRecords = AttendanceRecord::whereBetween('work_date', [$startDate, $endDate])
            ->whereIn('employee_id', $activeEmployeeIds)
            ->where('status', 'late')
            ->get();

        // Faltas por retardos: para meses CERRADOS la fuente de verdad son las
        // incidencias FRT del cierre mensual (las mismas que cobra la nómina,
        // DECISIONES_NEGOCIO §1); el mes en curso se exporta como proyección
        // etiquetada usando el MISMO conteo del servicio (días laborables, sin
        // festivos) para que coincida con lo que se cobrará.
        $lateAbsenceService = app(LateAbsenceService::class);
        $ruleStartKey = $lateAbsenceService->startMonth()?->format('Y-m');
        $currentMonthKey = Carbon::today()->format('Y-m');

        $monthsInRange = [];
        for ($cursor = Carbon::parse($startDate)->startOfMonth(); $cursor->lte(Carbon::parse($endDate)); $cursor->addMonthNoOverflow()) {
            $monthsInRange[] = $cursor->format('Y-m');
        }

        $frtIncidents = Incident::where('status', 'approved')
            ->whereIn('employee_id', $activeEmployeeIds)
            ->whereIn('late_month', $monthsInRange)
            ->get(['employee_id', 'late_month', 'days_count']);

        $retardoFaltas = [];
        $retardoDetalles = [];
        $chargedMonths = [];

        foreach ($frtIncidents as $incident) {
            $eid = $incident->employee_id;
            $faltasMes = max(1, (int) $incident->days_count);
            $retardoFaltas[$eid] = ($retardoFaltas[$eid] ?? 0) + $faltasMes;
            $chargedMonths[$eid][$incident->late_month] = true;
            $mes = Carbon::parse($incident->late_month.'-01')->locale('es')->isoFormat('MMM YYYY');
            $retardoDetalles[$eid][] = "{$mes}: {$faltasMes} falta".($faltasMes > 1 ? 's' : '').' por retardos (cobrada en nómina)';
        }

        $pendingPairs = [];
        foreach ($lateRecords->groupBy('employee_id') as $employeeId => $empRecords) {
            $byMonth = $empRecords->groupBy(fn ($r) => Carbon::parse($r->work_date)->format('Y-m'));
            foreach ($byMonth as $month => $monthRecords) {
                if (isset($chargedMonths[$employeeId][$month])) {
                    continue; // ya cobrada vía incidencia FRT
                }
                if ($ruleStartKey !== null && $month < $ruleStartKey) {
                    continue; // mes previo al corte de la regla mensual
                }
                $pendingPairs[$employeeId][] = $month;
            }
        }

        if ($pendingPairs !== []) {
            $pendingEmployees = Employee::whereIn('id', array_keys($pendingPairs))->get()->keyBy('id');

            foreach ($pendingPairs as $employeeId => $months) {
                $employee = $pendingEmployees[$employeeId] ?? null;
                if (! $employee) {
                    continue;
                }
                foreach ($months as $month) {
                    $cnt = $lateAbsenceService->lateCountForMonth($employee, Carbon::parse($month.'-01'));
                    $faltasMes = $lateAbsenceService->absencesFromLates($cnt);
                    if ($faltasMes < 1) {
                        continue;
                    }
                    $retardoFaltas[$employeeId] = ($retardoFaltas[$employeeId] ?? 0) + $faltasMes;
                    $mes = Carbon::parse($month.'-01')->locale('es')->isoFormat('MMM YYYY');
                    $etiqueta = $month === $currentMonthKey ? 'proyección, mes en curso' : 'pendiente de cierre';
                    $retardoDetalles[$employeeId][] = "{$mes}: {$cnt} retardos = {$faltasMes} falta".($faltasMes > 1 ? 's' : '')." ({$etiqueta})";
                }
            }
        }

        // Días justificados por incidencias aprobadas: no se exportan como
        // falta — la misma regla que el reporte web y la nómina.
        $justifiedDates = Incident::justifiedDatesByEmployee(
            $activeEmployeeIds,
            Carbon::parse($startDate)->toDateString(),
            Carbon::parse($endDate)->toDateString()
        );
        $absentRecords = $absentRecords->reject(
            fn ($r) => isset($justifiedDates[$r->employee_id][Carbon::parse($r->work_date)->toDateString()])
        );

        // Split absent records: true no-shows vs threshold-triggered
        $noShowRecords = $absentRecords->filter(fn ($r) => is_null($r->check_in));
        $thresholdRecords = $absentRecords->filter(fn ($r) => ! is_null($r->check_in));

        // Combine
        $allEmployeeIds = $absentRecords->pluck('employee_id')
            ->merge(array_keys($retardoFaltas))
            ->unique();

        // Cada observación (cada fecha) se exporta en su propia columna
        // ("Observación 1", "Observación 2", …) en lugar de concatenarse con
        // " | " en una sola celda, para que cada fecha quede en una celda
        // independiente. Se acumulan las observaciones por fila y, al final, se
        // rellena cada fila hasta el máximo para que todas tengan las mismas
        // columnas.
        $rows = [];
        $maxObservaciones = 0;
        foreach ($allEmployeeIds as $employeeId) {
            $empNoShow = $noShowRecords->where('employee_id', $employeeId);
            $empThreshold = $thresholdRecords->where('employee_id', $employeeId);
            $employee = $empNoShow->first()?->employee ?? $empThreshold->first()?->employee ?? Employee::with('department')->find($employeeId);
            $noShow = $empNoShow->count();
            $threshold = $empThreshold->count();
            $retardo = $retardoFaltas[$employeeId] ?? 0;

            // Observaciones por día (inasistencias y umbral juntas, en orden
            // cronológico) y luego el detalle mensual de faltas por retardos.
            // Cada texto aclara de qué día es la checada (una salida de
            // madrugada parece del día siguiente). Mismos textos que el detalle
            // del reporte web.
            $observaciones = [];
            foreach ($empNoShow->merge($empThreshold)->sortBy('work_date') as $r) {
                $observaciones[] = $this->faltaObservacion($r, $maxLateBeforeAbsence, $earlyDepartureThreshold, $earlyDepartureIsAbsence);
            }
            foreach ($retardoDetalles[$employeeId] ?? [] as $detalle) {
                $observaciones[] = $detalle;
            }

            $maxObservaciones = max($maxObservaciones, count($observaciones));

            $rows[] = [
                'fixed' => [
                    $employee?->full_name ?? '-',
                    $employee?->employee_number ?? '-',
                    $employee?->department?->name ?? '-',
                    $noShow,
                    $threshold,
                    $retardo,
                    $noShow + $threshold + $retardo,
                ],
                'observaciones' => $observaciones,
            ];
        }

        // Una columna por observación; las filas con menos observaciones se
        // rellenan con celdas vacías para cuadrar con el encabezado.
        $observacionHeaders = [];
        for ($i = 1; $i <= $maxObservaciones; $i++) {
            $observacionHeaders[] = "Observación {$i}";
        }

        $data = array_map(
            fn ($row) => array_merge(
                $row['fixed'],
                array_pad($row['observaciones'], $maxObservaciones, '')
            ),
            $rows
        );

        return $this->exportCsv(
            "reporte_faltas_{$startDate}_{$endDate}.csv",
            array_merge(
                ['Empleado', 'No. Empleado', 'Departamento', 'Inasistencias', 'Por Umbral', 'Faltas por Retardos', 'Total Faltas'],
                $observacionHeaders
            ),
            $data
        );
    }

    /**
     * Export asistencia perfecta report to CSV.
     */
    public function exportAsistencia(Request $request): StreamedResponse
    {
        $startDate = Carbon::parse($request->get('start_date', Carbon::now()->startOfWeek()->toDateString()));
        $endDate = Carbon::parse($request->get('end_date', Carbon::now()->endOfWeek()->toDateString()));

        $employees = Employee::with(['schedule', 'department'])->active()->whereIn('id', $this->scopedActiveEmployeeIds())->get();
        $allRecords = AttendanceRecord::whereBetween('work_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->whereIn('employee_id', $employees->pluck('id'))
            ->get();

        // MISMO tratamiento de festivos que el reporte web (auditoría #28):
        // los festivos no cuentan como días esperados y una fila obsoleta en
        // fecha festiva no rompe la asistencia perfecta.
        $holidayDates = Holiday::whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->all();

        $data = [];
        foreach ($employees as $employee) {
            $effectiveSchedule = $employee->getEffectiveSchedule();
            if (! $effectiveSchedule) {
                continue;
            }

            $workingDays = array_map('strtolower', $effectiveSchedule->working_days ?? []);
            if (empty($workingDays)) {
                continue;
            }

            $expectedDays = 0;
            $currentDate = $startDate->copy();
            while ($currentDate->lte($endDate)) {
                if (in_array(strtolower($currentDate->englishDayOfWeek), $workingDays)
                    && ! in_array($currentDate->toDateString(), $holidayDates)) {
                    $expectedDays++;
                }
                $currentDate->addDay();
            }

            if ($expectedDays === 0) {
                continue;
            }

            $records = $allRecords->where('employee_id', $employee->id);
            $excusedDays = $records->filter(fn ($r) => in_array($r->status, ['holiday', 'vacation', 'sick_leave', 'permission'])
                || in_array(Carbon::parse($r->work_date)->toDateString(), $holidayDates))->count();
            $adjustedExpected = $expectedDays - $excusedDays;

            if ($adjustedExpected <= 0) {
                continue;
            }

            $nonHolidayRecords = $records->filter(fn ($r) => ! in_array(Carbon::parse($r->work_date)->toDateString(), $holidayDates));
            $presentRecords = $nonHolidayRecords->where('status', 'present');
            $hasLate = $nonHolidayRecords->where('late_minutes', '>', 0)->isNotEmpty();
            $hasEarlyDeparture = $nonHolidayRecords->where('early_departure_minutes', '>', 0)->isNotEmpty();
            $hasAbsence = $nonHolidayRecords->where('status', 'absent')->isNotEmpty();

            if ($presentRecords->count() >= $adjustedExpected && ! $hasLate && ! $hasEarlyDeparture && ! $hasAbsence) {
                $data[] = [
                    $employee->full_name,
                    $employee->employee_number ?? '-',
                    $employee->department?->name ?? '-',
                    $presentRecords->count(),
                    round($records->sum('worked_hours'), 2),
                ];
            }
        }

        return $this->exportCsv(
            "reporte_asistencia_{$startDate->toDateString()}_{$endDate->toDateString()}.csv",
            ['Empleado', 'No. Empleado', 'Departamento', 'Dias Trabajados', 'Horas Totales'],
            $data
        );
    }

    /**
     * Export retardos report to CSV.
     */
    public function exportRetardos(Request $request): StreamedResponse
    {
        $startDate = $request->get('start_date', Carbon::now()->startOfWeek()->toDateString());
        $endDate = $request->get('end_date', Carbon::now()->endOfWeek()->toDateString());

        $activeEmployeeIds = $this->scopedActiveEmployeeIds();

        $records = AttendanceRecord::with(['employee.department'])
            ->whereBetween('work_date', [$startDate, $endDate])
            ->whereIn('employee_id', $activeEmployeeIds)
            ->where('status', 'late')
            ->orderBy('work_date')
            ->orderBy('employee_id')
            ->get();

        // Retardos justificados por incidencia aprobada no se exportan — la
        // misma regla que exportFaltas, el reporte web y la nómina (#3).
        $justifiedDates = Incident::justifiedDatesByEmployee(
            $activeEmployeeIds,
            Carbon::parse($startDate)->toDateString(),
            Carbon::parse($endDate)->toDateString()
        );
        $records = $records->reject(
            fn ($r) => isset($justifiedDates[$r->employee_id][Carbon::parse($r->work_date)->toDateString()])
        )->values();

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
     * Export early departures report to CSV.
     */
    public function exportEarlyDepartures(Request $request): StreamedResponse
    {
        $startDate = $request->get('start_date', Carbon::now()->startOfWeek()->toDateString());
        $endDate = $request->get('end_date', Carbon::now()->endOfWeek()->toDateString());

        $records = AttendanceRecord::with(['employee.department'])
            ->whereBetween('work_date', [$startDate, $endDate])
            ->whereIn('employee_id', $this->scopedActiveEmployeeIds())
            ->where('early_departure_minutes', '>', 0)
            ->orderBy('work_date')
            ->orderBy('employee_id')
            ->get();

        return $this->exportCsv(
            "reporte_salidas_tempranas_{$startDate}_{$endDate}.csv",
            ['Fecha', 'Empleado', 'No. Empleado', 'Departamento', 'Salida', 'Minutos Temprano'],
            $records->map(fn ($record) => [
                $record->work_date,
                $record->employee?->full_name ?? '-',
                $record->employee?->employee_number ?? '-',
                $record->employee?->department?->name ?? '-',
                $record->check_out ?? '-',
                $record->early_departure_minutes ?? 0,
            ])->toArray()
        );
    }

    /**
     * Create a CSV export response.
     *
     * @param  string  $filename  Export filename
     * @param  array  $headers  Column headers
     * @param  array  $data  Data rows
     * @return StreamedResponse CSV file download response
     */
    private function exportCsv(string $filename, array $headers, array $data): StreamedResponse
    {
        $callback = function () use ($headers, $data) {
            $file = fopen('php://output', 'w');

            // Add BOM for Excel UTF-8 compatibility
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));

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

    /**
     * One-line explanation of a falta row for the Observaciones column: which
     * day it was, what happened, and which day the punch belongs to. Mirrors
     * the labels of the Faltas web report (AttendanceReportController).
     */
    private function faltaObservacion(AttendanceRecord $record, int $maxLateBeforeAbsence, int $earlyDepartureThreshold, bool $earlyDepartureIsAbsence): string
    {
        $dia = Carbon::parse($record->work_date)->locale('es')->isoFormat('D MMM');

        if (is_null($record->check_in)) {
            $esperada = $this->horaEsperada($record, 'entry_time');

            return "{$dia}: no se presentó".($esperada ? " (entrada esperada {$esperada})" : '');
        }

        if (($record->late_minutes ?? 0) >= $maxLateBeforeAbsence) {
            $esperada = $this->horaEsperada($record, 'entry_time');

            return "{$dia}: retardo excesivo — llegó ".$this->hora($record->check_in)
                .$this->punchDayHint($record->check_in, false)
                .($esperada ? " (entrada esperada {$esperada})" : '');
        }

        if ($earlyDepartureIsAbsence && ($record->early_departure_minutes ?? 0) >= $earlyDepartureThreshold) {
            $esperada = $this->horaEsperada($record, 'exit_time');

            return "{$dia}: salida temprana — salió ".$this->hora($record->check_out)
                .$this->punchDayHint($record->check_out, true)
                .($esperada ? " (salida esperada {$esperada})" : '');
        }

        return "{$dia}: falta por umbral";
    }

    /**
     * Expected entry/exit time for the record's day, per the employee's
     * effective schedule (including overrides). Null when not resolvable.
     */
    private function horaEsperada(AttendanceRecord $record, string $field): ?string
    {
        $employee = $record->employee;
        if (! $employee) {
            return null;
        }

        $dayName = strtolower(Carbon::parse($record->work_date)->englishDayOfWeek);
        $daySchedule = $employee->getEffectiveScheduleForDay($dayName);

        return $daySchedule?->{$field} ? $this->hora($daySchedule->{$field}) : null;
    }

    private function hora(?string $time): string
    {
        if (! $time) {
            return '—';
        }

        try {
            return Carbon::parse($time)->format('H:i');
        } catch (\Throwable) {
            return substr($time, 0, 5);
        }
    }

    /**
     * Clarify which day a punch belongs to: punches are paired per calendar
     * day, so the time always comes from the row's own date — but a check-out
     * like "04:39" next to an expected "18:30" reads as if it could belong to
     * the next morning. (Same hint as AttendanceReportController.)
     */
    private function punchDayHint(?string $time, bool $always): string
    {
        if (! $time) {
            return '';
        }

        $hour = (int) substr($time, 0, 2);

        if ($hour < 7) {
            return ' (madrugada del mismo día)';
        }

        return $always ? ' (mismo día)' : '';
    }
}
