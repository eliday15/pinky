<?php

namespace App\Http\Controllers;

use App\Exports\WeeklySummaryExport;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Incident;
use App\Services\LateAbsenceService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Resumen semanal en una sola vista (pedido de Luis, 2026-07-30): Vacaciones,
 * Faltas, Faltas por retardo e Incapacidades de un rango de fechas, cada una
 * como lista de nombre + fecha + observaciones — el equivalente en el sistema
 * del Excel semanal que armaba a mano.
 *
 * Cada sección lee la MISMA fuente de verdad que su reporte dedicado: las
 * faltas descuentan las justificadas por incidencia aprobada
 * (Incident::justifiedDatesByEmployee) y los festivos, igual que la nómina.
 */
class WeeklySummaryReportController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeAccess();

        [$from, $to] = $this->resolveRange($request);

        return Inertia::render('Reports/ResumenSemanal', array_merge(
            $this->buildSummary($from, $to),
            [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
        ));
    }

    public function export(Request $request): BinaryFileResponse
    {
        $this->authorizeAccess();

        [$from, $to] = $this->resolveRange($request);
        $summary = $this->buildSummary($from, $to);

        $filename = 'resumen_semanal_'.$from->toDateString().'_'.$to->toDateString().'.xlsx';

        return Excel::download(
            new WeeklySummaryExport($summary, $from->toDateString(), $to->toDateString()),
            $filename,
        );
    }

    /**
     * Arma las secciones del resumen para el rango: vacaciones, faltas, faltas
     * por retardo, incapacidades, finiquitos y cumpleaños.
     *
     * @return array{vacaciones: array, faltas: array, retardos: array, incapacidades: array, finiquitos: array, cumpleanos: array}
     */
    private function buildSummary(Carbon $from, Carbon $to): array
    {
        $fromStr = $from->toDateString();
        $toStr = $to->toDateString();

        // Empleados activos (no exentos de checada para las faltas) + nombres.
        $employees = Employee::active()
            ->with('department:id,name')
            ->get(['id', 'full_name', 'employee_number', 'department_id', 'is_attendance_exempt', 'birth_date'])
            ->keyBy('id');

        $label = fn ($e) => [
            'name' => $e?->full_name ?? '—',
            'employee_number' => $e?->employee_number,
            'department' => $e?->department?->name,
        ];

        // Incidencias aprobadas que solapan el rango, con su tipo.
        $incidents = Incident::where('status', 'approved')
            ->where('start_date', '<=', $toStr)
            ->where('end_date', '>=', $fromStr)
            ->with('incidentType')
            ->orderBy('start_date')
            ->get();

        // ---- VACACIONES ----
        $vacaciones = $incidents
            ->filter(fn ($i) => $i->incidentType?->category === 'vacation')
            ->map(fn ($i) => array_merge($label($employees->get($i->employee_id)), [
                'date' => $this->dateLabel($i->start_date, $i->end_date),
                'observaciones' => $i->reason ?: 'Vacaciones',
            ]))
            ->sortBy('name')->values()->all();

        // ---- INCAPACIDADES ----
        $incapacidades = $incidents
            ->filter(fn ($i) => $i->incidentType?->category === 'sick_leave')
            ->map(fn ($i) => array_merge($label($employees->get($i->employee_id)), [
                'date' => $this->dateLabel($i->start_date, $i->end_date),
                'observaciones' => $i->reason ?: 'Incapacidad',
            ]))
            ->sortBy('name')->values()->all();

        // ---- FALTAS ---- (misma regla que Reports/Faltas: ausencias no
        // justificadas por incidencia, sin festivos, sin exentos de checada)
        $nonExemptIds = $employees->reject(fn ($e) => $e->is_attendance_exempt)->keys()->all();
        $holidayDates = Holiday::whereBetween('date', [$fromStr, $toStr])
            ->pluck('date')->map(fn ($d) => Carbon::parse($d)->toDateString())->all();
        $justified = Incident::justifiedDatesByEmployee($nonExemptIds, $fromStr, $toStr);

        $absentRows = AttendanceRecord::whereBetween('work_date', [$fromStr, $toStr])
            ->whereIn('employee_id', $nonExemptIds)
            ->where('status', 'absent')
            ->orderBy('work_date')
            ->get(['employee_id', 'work_date']);

        $faltas = $absentRows
            ->filter(function ($r) use ($holidayDates, $justified) {
                $date = Carbon::parse($r->work_date)->toDateString();

                return ! in_array($date, $holidayDates, true)
                    && ! isset($justified[$r->employee_id][$date]);
            })
            ->map(fn ($r) => array_merge($label($employees->get($r->employee_id)), [
                'date' => Carbon::parse($r->work_date)->toDateString(),
                'observaciones' => 'Falta',
            ]))
            ->sortBy(fn ($row) => ($row['name'] ?? '').'|'.($row['date'] ?? ''))
            ->values()->all();

        // ---- FALTAS POR RETARDO ---- La acumulación de retardos es MENSUAL
        // (igual que la nómina, DECISIONES §1): N retardos en el mes = 1 falta,
        // con N = late_to_absence_count. Se cuentan los retardos del/los mes(es)
        // que toca el rango y se listan solo los colaboradores que alcanzan el
        // umbral (los que SÍ generan falta), con el conteo y las faltas
        // proyectadas — como el "Faltas por retardo" del Excel de Luis.
        $threshold = app(LateAbsenceService::class)->threshold();
        $months = [];
        for ($c = $from->copy()->startOfMonth(); $c->lte($to); $c->addMonthNoOverflow()) {
            $months[$c->format('Y-m')] = [$c->copy()->startOfMonth(), $c->copy()->endOfMonth()];
        }

        $retardos = [];
        foreach ($months as $ym => [$mStart, $mEnd]) {
            $monthHolidays = Holiday::whereBetween('date', [$mStart->toDateString(), $mEnd->toDateString()])
                ->pluck('date')->map(fn ($d) => Carbon::parse($d)->toDateString())->all();

            $counts = AttendanceRecord::whereBetween('work_date', [$mStart->toDateString(), $mEnd->toDateString()])
                ->whereIn('employee_id', $nonExemptIds)
                ->where('status', 'late')
                ->when(! empty($monthHolidays), fn ($q) => $q->whereNotIn('work_date', $monthHolidays))
                ->selectRaw('employee_id, count(*) as n')
                ->groupBy('employee_id')
                ->havingRaw('count(*) >= ?', [$threshold])
                ->pluck('n', 'employee_id');

            $monthLabel = ucfirst($mStart->locale('es')->isoFormat('MMMM YYYY'));
            foreach ($counts as $eid => $n) {
                $faltasGeneradas = intdiv((int) $n, $threshold);
                $retardos[] = array_merge($label($employees->get($eid)), [
                    'date' => $monthLabel,
                    'observaciones' => $n.' retardos → '.$faltasGeneradas.' '.($faltasGeneradas === 1 ? 'falta' : 'faltas'),
                    'count' => (int) $n,
                ]);
            }
        }

        usort($retardos, fn ($a, $b) => $b['count'] <=> $a['count']);

        // ---- CUMPLEAÑOS ---- Colaboradores activos cuyo cumpleaños cae en el/los
        // mes(es) que toca el rango (Luis 2026-08-06). Fecha = nacimiento;
        // observación = el mes. Ordenados por día del mes.
        $monthNameByN = [];
        for ($c = $from->copy()->startOfMonth(); $c->lte($to); $c->addMonthNoOverflow()) {
            $monthNameByN[(int) $c->format('n')] = mb_strtoupper($c->locale('es')->isoFormat('MMMM'));
        }
        $cumpleanos = $employees
            ->filter(fn ($e) => $e->birth_date && isset($monthNameByN[(int) $e->birth_date->format('n')]))
            ->map(fn ($e) => array_merge($label($e), [
                'date' => $e->birth_date->format('d/m/Y'),
                'observaciones' => $monthNameByN[(int) $e->birth_date->format('n')],
                'day' => (int) $e->birth_date->format('j'),
            ]))
            ->sortBy('day')->values()->all();

        // ---- FINIQUITO ---- Colaboradores con fecha de BAJA en el rango (incluye
        // dados de baja / soft-deleted, igual que el reporte al contador). El
        // IMPORTE lo captura a mano el usuario: el sistema no calcula finiquitos,
        // así que la observación sale en blanco para llenarla.
        $finiquitos = Employee::withTrashed()
            ->whereNotNull('termination_date')
            ->whereBetween('termination_date', [$fromStr, $toStr])
            ->with('department:id,name')
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'employee_number', 'department_id', 'termination_date', 'finiquito_amount'])
            ->map(fn ($e) => array_merge($label($e), [
                'date' => Carbon::parse($e->termination_date)->format('d/m/Y'),
                // El importe capturado en la ficha (Dani 2026-08-12) sale
                // impreso; sin captura, en blanco para anotarse a pluma.
                'observaciones' => $e->finiquito_amount !== null
                    ? '$'.number_format((float) $e->finiquito_amount, 2)
                    : '',
            ]))->values()->all();

        return compact('vacaciones', 'faltas', 'retardos', 'incapacidades', 'finiquitos', 'cumpleanos');
    }

    /** 'dd/mm/YYYY' o 'dd/mm–dd/mm/YYYY' cuando el rango abarca varios días. */
    private function dateLabel($start, $end): string
    {
        $s = Carbon::parse($start);
        $e = Carbon::parse($end);
        if ($s->isSameDay($e)) {
            return $s->format('d/m/Y');
        }

        return $s->format('d/m').' – '.$e->format('d/m/Y');
    }

    /**
     * Rango a mostrar: el que venga por query, o por defecto la semana actual
     * (lunes a domingo).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveRange(Request $request): array
    {
        $from = $request->input('from')
            ? Carbon::parse($request->input('from'))->startOfDay()
            : Carbon::now()->startOfWeek();

        $to = $request->input('to')
            ? Carbon::parse($request->input('to'))->startOfDay()
            : Carbon::now()->endOfWeek()->startOfDay();

        if ($to->lt($from)) {
            $to = $from->copy();
        }

        return [$from, $to];
    }

    private function authorizeAccess(): void
    {
        if (! Auth::user()->hasPermissionTo('reports.view_all')) {
            abort(403);
        }
    }
}
