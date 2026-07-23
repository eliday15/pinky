<?php

namespace App\Services\Reports;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Incident;
use App\Services\LateAbsenceService;
use Carbon\Carbon;

/**
 * Arma el "Reporte al contador": el resumen semanal que RRHH entrega al
 * contador, separado por empresa (razón social / canal de pago) en secciones —
 * Vacaciones, Prima vacacional, Faltas, Faltas por retardo, Incapacidades,
 * Finiquito y Pago de día descontado.
 *
 * Reutiliza las MISMAS reglas que el resto del sistema (incidencias aprobadas,
 * días justificados, festivos, días obligatorios y faltas por retardo del
 * cierre mensual) para que los números coincidan con la nómina y los reportes
 * de faltas.
 */
class AccountantReportService
{
    /**
     * Secciones del reporte, en orden, con su título y encabezados de columna.
     * Cada fila de datos es un arreglo de 3 celdas alineado a estos encabezados.
     */
    public const SECTIONS = [
        'vacaciones' => ['title' => 'VACACIONES', 'headers' => ['NOMBRE', 'FECHA', 'OBSERVACIONES']],
        'prima_vacacional' => ['title' => 'PRIMA VACACIONAL', 'headers' => ['NOMBRE', 'IMPORTE', 'OBSERVACIONES']],
        'faltas' => ['title' => 'FALTAS', 'headers' => ['NOMBRE', 'FECHA', 'OBSERVACIONES']],
        'faltas_retardo' => ['title' => 'FALTAS POR RETARDO', 'headers' => ['NOMBRE', 'FECHA', 'OBSERVACIONES']],
        'incapacidades' => ['title' => 'INCAPACIDADES', 'headers' => ['NOMBRE', 'FECHA', 'OBSERVACIONES']],
        'finiquito' => ['title' => 'FINIQUITO', 'headers' => ['NOMBRE', 'FECHA', 'IMPORTE']],
        'pago_dia_descontado' => ['title' => 'PAGO DIA DESCONTADO', 'headers' => ['NOMBRE', 'FECHA', 'OBSERVACIONES']],
    ];

    public function __construct(private LateAbsenceService $lateAbsenceService) {}

    /**
     * Construye el reporte para el rango [start, end].
     *
     * @return array<string, array<string, list<array<int, string>>>>
     *         empresa => sección => filas (cada fila: [col1, col2, col3])
     */
    public function build(Carbon $start, Carbon $end): array
    {
        $start = $start->copy()->startOfDay();
        $end = $end->copy()->startOfDay();

        // Todos los empleados (incluye dados de baja: el finiquito los necesita),
        // indexados por id y agrupados por empresa.
        $employees = Employee::withTrashed()->with(['department', 'schedule'])->get()->keyBy('id');
        $empresaOf = fn (int $id): string => $this->normalizeEmpresa($employees[$id]->empresa ?? null);

        // Estructura base: cada empresa con todas sus secciones vacías.
        $report = [];
        foreach (array_keys(Employee::EMPRESAS) as $empresa) {
            $report[$empresa] = array_fill_keys(array_keys(self::SECTIONS), []);
        }

        $holidayDates = Holiday::whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->all();

        $this->fillIncidentSections($report, $employees, $empresaOf, $start, $end);
        $this->fillFaltas($report, $employees, $empresaOf, $start, $end, $holidayDates);
        $this->fillFaltasPorRetardo($report, $employees, $empresaOf, $start, $end);
        $this->fillFiniquito($report, $employees, $empresaOf, $start, $end);

        // Ordena cada sección por nombre (y fecha) para una lectura estable.
        foreach ($report as $empresa => $sections) {
            foreach ($sections as $key => $rows) {
                usort($rows, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
                $report[$empresa][$key] = $rows;
            }
        }

        return $report;
    }

    /**
     * Vacaciones e incapacidades: una fila por DÍA de cada incidencia aprobada
     * que solapa la semana. La prima vacacional "por fuera" se lista aparte
     * (una por persona) sólo para la empresa POR_FUERA.
     */
    private function fillIncidentSections(array &$report, $employees, callable $empresaOf, Carbon $start, Carbon $end): void
    {
        $incidents = Incident::query()
            ->approved()
            ->with('incidentType')
            ->where('start_date', '<=', $end->toDateString())
            ->where('end_date', '>=', $start->toDateString())
            ->whereHas('incidentType', fn ($q) => $q->whereIn('category', ['vacation', 'sick_leave']))
            ->get();

        $primaSeen = [];

        foreach ($incidents as $incident) {
            $employee = $employees[$incident->employee_id] ?? null;
            if (! $employee) {
                continue;
            }
            $empresa = $empresaOf($incident->employee_id);
            $category = $incident->incidentType->category;

            $from = Carbon::parse($incident->start_date)->max($start);
            $to = Carbon::parse($incident->end_date)->min($end);

            for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
                if ($category === 'vacation') {
                    $report[$empresa]['vacaciones'][] = [$employee->full_name, $day->format('d/m/Y'), 'vacaciones'];
                } else { // sick_leave
                    $report[$empresa]['incapacidades'][] = [$employee->full_name, $day->format('d/m/Y'), ''];
                }
            }

            // Prima vacacional POR FUERA: se paga fuera de nómina, así que se
            // enlista (una vez por persona) para que el contador la cubra a mano.
            if ($category === 'vacation' && $empresa === 'POR_FUERA' && ! isset($primaSeen[$incident->employee_id])) {
                $primaSeen[$incident->employee_id] = true;
                $report['POR_FUERA']['prima_vacacional'][] = [$employee->full_name, '', 'vacaciones'];
            }
        }
    }

    /**
     * Faltas: una fila por (empleado, día) con status 'absent', excluyendo
     * exentos de checada, días justificados por incidencia, festivos y días no
     * obligatorios — la misma regla que el reporte de Faltas y la nómina.
     */
    private function fillFaltas(array &$report, $employees, callable $empresaOf, Carbon $start, Carbon $end, array $holidayDates): void
    {
        $absent = AttendanceRecord::query()
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->where('status', 'absent')
            ->when($holidayDates !== [], fn ($q) => $q->whereNotIn('work_date', $holidayDates))
            ->get(['employee_id', 'work_date']);

        $employeeIds = $absent->pluck('employee_id')->unique()->values();
        $justified = Incident::justifiedDatesByEmployee($employeeIds, $start->toDateString(), $end->toDateString());

        foreach ($absent as $record) {
            $employee = $employees[$record->employee_id] ?? null;
            if (! $employee || $employee->is_attendance_exempt) {
                continue;
            }
            $date = Carbon::parse($record->work_date);
            if (isset($justified[$record->employee_id][$date->toDateString()])) {
                continue; // justificada por incidencia aprobada
            }
            if (! $employee->isObligatoryWorkDay($date)) {
                continue; // fin de semana / día no laborable del empleado
            }

            $report[$empresaOf($record->employee_id)]['faltas'][] = [
                $employee->full_name,
                $date->format('d/m/Y'),
                '',
            ];
        }
    }

    /**
     * Faltas por retardo: acumulado MENSUAL. Para meses cerrados la fuente son
     * las incidencias FRT (lo que cobra la nómina); el mes en curso se proyecta
     * con el mismo servicio, claramente etiquetado. Igual que el reporte/Excel
     * de Faltas.
     */
    private function fillFaltasPorRetardo(array &$report, $employees, callable $empresaOf, Carbon $start, Carbon $end): void
    {
        $months = [];
        for ($cursor = $start->copy()->startOfMonth(); $cursor->lte($end); $cursor->addMonthNoOverflow()) {
            $months[] = $cursor->format('Y-m');
        }

        $ruleStart = $this->lateAbsenceService->startMonth()?->format('Y-m');
        $currentMonth = Carbon::parse($end->toDateString())->format('Y-m');

        // 1) Meses cerrados: incidencias FRT ya cobradas.
        $chargedMonths = [];
        $frt = Incident::approved()
            ->whereHas('incidentType', fn ($q) => $q->where('category', 'late_accumulation'))
            ->whereIn('late_month', $months)
            ->get(['employee_id', 'late_month', 'days_count']);

        foreach ($frt as $incident) {
            $employee = $employees[$incident->employee_id] ?? null;
            if (! $employee) {
                continue;
            }
            $faltas = max(1, (int) $incident->days_count);
            $chargedMonths[$incident->employee_id][$incident->late_month] = true;
            $mes = Carbon::parse($incident->late_month.'-01')->locale('es')->isoFormat('MMM YYYY');

            $report[$empresaOf($incident->employee_id)]['faltas_retardo'][] = [
                $employee->full_name,
                $mes,
                "{$faltas} falta".($faltas > 1 ? 's' : '').' por retardos (cobrada en nómina)',
            ];
        }

        // 2) Mes en curso (o pendiente de cierre): proyección con el servicio,
        // sólo para quien tuvo retardos en el rango (evita 1 query por empleado).
        $lateInRange = AttendanceRecord::query()
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->where('status', 'late')
            ->get(['employee_id', 'work_date']);

        $pending = [];
        foreach ($lateInRange as $row) {
            $month = Carbon::parse($row->work_date)->format('Y-m');
            $pending[$row->employee_id][$month] = true;
        }

        foreach ($pending as $employeeId => $monthSet) {
            $employee = $employees[$employeeId] ?? null;
            if (! $employee || $employee->is_attendance_exempt) {
                continue;
            }
            foreach (array_keys($monthSet) as $month) {
                if (isset($chargedMonths[$employeeId][$month])) {
                    continue; // ya cobrada vía incidencia FRT
                }
                if ($ruleStart !== null && $month < $ruleStart) {
                    continue; // mes previo al corte de la regla mensual
                }
                $cnt = $this->lateAbsenceService->lateCountForMonth($employee, Carbon::parse($month.'-01'));
                $faltas = $this->lateAbsenceService->absencesFromLates($cnt);
                if ($faltas < 1) {
                    continue;
                }
                $mes = Carbon::parse($month.'-01')->locale('es')->isoFormat('MMM YYYY');
                $etiqueta = $month === $currentMonth ? 'proyección, mes en curso' : 'pendiente de cierre';

                $report[$empresaOf($employeeId)]['faltas_retardo'][] = [
                    $employee->full_name,
                    $mes,
                    "{$cnt} retardos = {$faltas} falta".($faltas > 1 ? 's' : '')." ({$etiqueta})",
                ];
            }
        }
    }

    /**
     * Finiquito: empleados con fecha de baja dentro de la semana. El importe lo
     * captura el contador (el sistema no lo calcula).
     */
    private function fillFiniquito(array &$report, $employees, callable $empresaOf, Carbon $start, Carbon $end): void
    {
        foreach ($employees as $employee) {
            if (! $employee->termination_date) {
                continue;
            }
            $date = Carbon::parse($employee->termination_date);
            if ($date->lt($start) || $date->gt($end)) {
                continue;
            }
            $report[$empresaOf($employee->id)]['finiquito'][] = [
                $employee->full_name,
                $date->format('d/m/Y'),
                '',
            ];
        }
    }

    private function normalizeEmpresa(?string $empresa): string
    {
        return array_key_exists((string) $empresa, Employee::EMPRESAS) ? (string) $empresa : 'VP';
    }
}
