<?php

namespace App\Services\Reports;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\Department;
use App\Models\Employee;
use App\Services\OvertimeRoundingService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the weekly overtime report DTO for a department.
 *
 * Reports only AUTHORIZED hours (Authorization with status approved/paid)
 * that are also backed by an actual attendance record (i.e. employee
 * checked in and out that day). Hours are classified by their attached
 * compensation_type.code:
 *
 *   - Daily extra hours cell  : HE + HED + HET (per_hour overtime)
 *   - FIN DE SEMANA hours     : FIN
 *   - VELADA marker (1/0)     : VEL
 *   - CENA marker (1/0)       : Cena
 *   - COMIDA marker (1/0)     : COM
 *
 * No fallback to unauthorized hours — if it's not authorized, it's not
 * shown, by design.
 */
class WeeklyOvertimeReportService
{
    public function __construct(
        private readonly OvertimeRoundingService $rounding = new OvertimeRoundingService,
    ) {}

    /**
     * Compensation type codes that count as "horas extra" in daily cells.
     */
    private const OVERTIME_CODES = ['HE', 'HED', 'HET'];

    private const WEEKEND_CODE = 'FIN';

    private const VELADA_CODE = 'VEL';

    private const CENA_CODE = 'CENA';

    private const COMIDA_CODE = 'COM';

    /**
     * Build the report payload for a department and week.
     *
     * Args:
     *     department: The department to report on.
     *     weekStart: Any date in the target week (will be normalized to startOfWeek).
     *
     * Returns:
     *     Array with department, dates, rows and totals ready for templates.
     */
    public function buildReport(Department $department, Carbon $weekStart, ?Carbon $rangeEnd = null): array
    {
        // Rango libre: si viene una fecha fin se respeta el rango literal
        // [inicio, fin] que pidió el usuario ("de qué día a qué día"). Sin
        // fecha fin se conserva el comportamiento semanal de siempre (la
        // semana lun–dom que contiene la fecha dada), por lo que los llamados
        // existentes no cambian.
        if ($rangeEnd !== null) {
            $start = $weekStart->copy()->startOfDay();
            $end = $rangeEnd->copy()->startOfDay();
        } else {
            $start = $weekStart->copy()->startOfWeek();
            $end = $start->copy()->endOfWeek();
        }

        $dates = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $dates[] = $cursor->toDateString();
            $cursor->addDay();
        }

        $employees = Employee::with(['schedule', 'department'])
            ->where('department_id', $department->id)
            ->where('status', 'active')
            ->orderBy('full_name')
            ->get();

        $employeeIds = $employees->pluck('id');

        $records = AttendanceRecord::whereIn('employee_id', $employeeIds)
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->groupBy('employee_id');

        $authorizations = Authorization::with('compensationType')
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('status', [Authorization::STATUS_APPROVED, Authorization::STATUS_PAID])
            ->get()
            ->groupBy('employee_id');

        // Departamentos como Almacén PT cuentan el fin de semana por unidades de
        // N horas trabajadas (weekend_unit_hours) en vez de por día. NULL =
        // comportamiento normal (se muestran las horas/conteo de siempre).
        $weekendUnitHours = $department->weekend_unit_hours;

        $rows = $employees->map(fn (Employee $employee) => $this->buildEmployeeRow(
            $employee,
            $dates,
            $records->get($employee->id, collect()),
            $authorizations->get($employee->id, collect()),
            $weekendUnitHours,
        ))->values()->all();

        $totals = $this->buildGrandTotals($rows, $weekendUnitHours);

        return [
            'department' => [
                'id' => $department->id,
                'name' => $department->name,
                'code' => strtoupper($department->code ?? ''),
            ],
            'week_start' => $start->toDateString(),
            'week_end' => $end->toDateString(),
            'weekend_unit_hours' => $weekendUnitHours,
            'dates' => $dates,
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    /**
     * Build the full row payload for a single employee.
     */
    private function buildEmployeeRow(
        Employee $employee,
        array $dates,
        Collection $records,
        Collection $authorizations,
        ?int $weekendUnitHours = null,
    ): array {
        $recordsByDate = $records->keyBy(fn (AttendanceRecord $r) => $r->work_date->toDateString());
        $authsByDate = $authorizations->groupBy(fn (Authorization $a) => $a->date->toDateString());

        $days = [];
        $weeklyExtra = 0.0;
        $weeklyWeekend = 0.0;
        $weeklyWeekendWorked = 0.0;
        $veladaCount = 0;
        $cenaCount = 0;
        $comidaCount = 0;

        $weeklyDetected = 0.0;
        $weeklyPending = 0.0;

        foreach ($dates as $date) {
            $record = $recordsByDate->get($date);
            $dayAuths = $authsByDate->get($date, collect());

            $day = $this->buildDay($employee, $date, $record, $dayAuths);

            $days[$date] = $day;
            $weeklyExtra += $day['overtime_hours'];
            $weeklyWeekend += $day['weekend_hours'];
            $weeklyWeekendWorked += $day['weekend_worked_hours'];
            $weeklyDetected += $day['detected_overtime_hours'];
            $weeklyPending += $day['pending_overtime_hours'];
            $veladaCount += $day['velada_marker'];
            $cenaCount += $day['cena_marker'];
            $comidaCount += $day['comida_marker'];
        }

        // Unidades de fin de semana (horas trabajadas ÷ N), a números cerrados:
        // floor, sin redondear hacia arriba (WhatsApp 2026-06-24, Dani: 11 h = 1,
        // 12 h = 2). Coincide con la nómina. Null cuando el depto no usa unidades.
        $weekendUnits = $weekendUnitHours
            ? (int) floor($weeklyWeekendWorked / $weekendUnitHours)
            : null;

        return [
            'employee' => [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
                'employee_number' => $employee->employee_number,
                'has_night_shift' => collect($days)->contains(fn ($d) => $d['is_night_shift']),
            ],
            'days' => $days,
            'totals' => [
                'total_hours' => round($weeklyExtra, 2),
                'weekend_hours' => round($weeklyWeekend, 2),
                'weekend_worked_hours' => round($weeklyWeekendWorked, 2),
                'weekend_units' => $weekendUnits,
                'detected_hours' => round($weeklyDetected, 2),
                'pending_hours' => round($weeklyPending, 2),
                'velada_count' => $veladaCount,
                'cena_count' => $cenaCount,
                // En deptos por unidades (Almacén PT) la comida va igualada al fin
                // de semana: una comida por unidad (12 h = 2 comidas). En el resto
                // sigue siendo 1 por día con comida.
                'comida_count' => ($weekendUnitHours && $comidaCount > 0)
                    ? $weekendUnits
                    : $comidaCount,
            ],
            'observations' => $this->buildObservations($records, $authorizations),
        ];
    }

    /**
     * Build per-day metrics, classifying authorized hours by compensation_type.code.
     *
     * Day with no check-in/check-out yields all zeros for authorized columns.
     * Also computes `detected_overtime_hours` from real punches vs schedule using
     * the company rounding rule, and `pending_overtime_hours` (detected − approved,
     * floored at 0) so the report can surface OT that was worked but not yet
     * approved.
     */
    private function buildDay(
        Employee $employee,
        string $date,
        ?AttendanceRecord $record,
        Collection $dayAuthorizations,
    ): array {
        $dateObj = Carbon::parse($date);
        $isWeekendDate = $dateObj->isWeekend();

        $blank = [
            'date' => $date,
            'is_weekend_date' => $isWeekendDate,
            'overtime_hours' => 0.0,
            'velada_hours' => 0.0,
            'weekend_hours' => 0.0,
            'weekend_worked_hours' => 0.0,
            'worked_hours' => 0.0,
            'detected_overtime_hours' => 0.0,
            'pending_overtime_hours' => 0.0,
            'is_night_shift' => false,
            'is_weekend_work' => false,
            'm_hours' => 0.0,
            'v_hours' => 0.0,
            'velada_marker' => 0,
            'cena_marker' => 0,
            'comida_marker' => 0,
        ];

        if (! $record || ! $record->check_in || ! $record->check_out) {
            return $blank;
        }

        $byCode = $dayAuthorizations->groupBy(fn (Authorization $a) => $this->normalizeCode($a->compensationType?->code));

        $authorizedOvertimeRaw = 0.0;
        foreach (self::OVERTIME_CODES as $code) {
            $authorizedOvertimeRaw += (float) $byCode->get($code, collect())->sum('hours');
        }

        $weekendHours = (float) $byCode->get(self::WEEKEND_CODE, collect())->sum('hours');
        $authorizedVeladaRaw = (float) $byCode->get(self::VELADA_CODE, collect())->sum('hours');

        $veladaMarker = $byCode->has(self::VELADA_CODE) ? 1 : 0;
        $cenaMarker = $byCode->has(self::CENA_CODE) ? 1 : 0;
        $comidaMarker = $byCode->has(self::COMIDA_CODE) ? 1 : 0;

        $isNightShift = (bool) $record->is_night_shift;
        $isWeekendWork = (bool) $record->is_weekend_work;

        $dayName = $dateObj->format('l');
        $schedule = $employee->getEffectiveScheduleForDay($dayName);
        $detectedHours = $this->rounding->detectOvertimeHours($record, $schedule, $date);

        // Tope al timecard (auditoría #20 / DECISIONES derivadas): las horas
        // autorizadas mostradas no pueden exceder lo realmente detectado en
        // checadas — el mismo tope que aplica la nómina al pagar. Si se
        // aprobaron más horas de las trabajadas, el reporte muestra lo
        // pagable, no la autorización inflada.
        $overtimeHours = min($authorizedOvertimeRaw, $detectedHours);
        $veladaHours = min($authorizedVeladaRaw, (float) ($record->velada_hours ?? 0));

        $mHours = $isNightShift ? 0.0 : $overtimeHours;
        $vHours = $isNightShift ? $overtimeHours : 0.0;

        // Approved is what the supervisor signed off on (HE codes + Velada),
        // SIN topar — pending mide lo detectado no cubierto por autorización.
        $approvedForGap = $authorizedOvertimeRaw + $authorizedVeladaRaw;
        $pendingHours = max($detectedHours - $approvedForGap, 0.0);

        return [
            'date' => $date,
            'is_weekend_date' => $isWeekendDate,
            'overtime_hours' => round($overtimeHours, 2),
            'velada_hours' => round($veladaHours, 2),
            'weekend_hours' => round($weekendHours, 2),
            // Horas realmente trabajadas ese día cuando hay autorización de fin
            // de semana (FIN): base del conteo por unidades de Almacén PT. Incluye
            // las horas extra: en fin de semana TODA la jornada cuenta para las
            // unidades (worked_hours topa a la jornada base, overtime_hours es el
            // excedente) — igual que la nómina (metrics['weekend_hours']).
            'weekend_worked_hours' => $byCode->has(self::WEEKEND_CODE)
                ? round((float) ($record->worked_hours ?? 0) + (float) ($record->overtime_hours ?? 0), 2)
                : 0.0,
            'worked_hours' => round((float) ($record->worked_hours ?? 0), 2),
            'detected_overtime_hours' => round($detectedHours, 2),
            'pending_overtime_hours' => round($pendingHours, 2),
            'is_night_shift' => $isNightShift,
            'is_weekend_work' => $isWeekendWork,
            'm_hours' => round($mHours, 2),
            'v_hours' => round($vHours, 2),
            'velada_marker' => $veladaMarker,
            'cena_marker' => $cenaMarker,
            'comida_marker' => $comidaMarker,
        ];
    }

    /**
     * Normalize a compensation type code for matching (uppercased, trimmed).
     */
    private function normalizeCode(?string $code): string
    {
        return strtoupper(trim((string) $code));
    }

    /**
     * Concatenate observations from attendance notes + authorization reasons.
     */
    private function buildObservations(Collection $records, Collection $authorizations): string
    {
        $parts = [];

        foreach ($records as $record) {
            if (! empty($record->notes)) {
                $parts[] = trim($record->notes);
            }
        }

        foreach ($authorizations as $auth) {
            $reason = trim($auth->reason ?? '');
            if ($reason === '') {
                continue;
            }
            $label = $auth->compensationType?->name
                ?? match ($auth->type) {
                    Authorization::TYPE_NIGHT_SHIFT => 'Velada',
                    Authorization::TYPE_OVERTIME => 'Extra',
                    Authorization::TYPE_HOLIDAY_WORKED => 'Festivo',
                    Authorization::TYPE_SPECIAL => 'Especial',
                    default => 'Auth',
                };
            $parts[] = "{$label}: {$reason}";
        }

        $unique = array_values(array_unique(array_filter($parts)));

        return implode('; ', $unique);
    }

    /**
     * Sum totals across all rows.
     */
    private function buildGrandTotals(array $rows, ?int $weekendUnitHours = null): array
    {
        $totalHours = 0.0;
        $weekendHours = 0.0;
        $weekendWorked = 0.0;
        $weekendUnits = 0;
        $detectedHours = 0.0;
        $pendingHours = 0.0;
        $veladaCount = 0;
        $cenaCount = 0;
        $comidaCount = 0;

        foreach ($rows as $row) {
            $totalHours += $row['totals']['total_hours'];
            $weekendHours += $row['totals']['weekend_hours'];
            $weekendWorked += $row['totals']['weekend_worked_hours'] ?? 0;
            $weekendUnits += (int) ($row['totals']['weekend_units'] ?? 0);
            $detectedHours += $row['totals']['detected_hours'] ?? 0;
            $pendingHours += $row['totals']['pending_hours'] ?? 0;
            $veladaCount += $row['totals']['velada_count'];
            $cenaCount += $row['totals']['cena_count'];
            $comidaCount += $row['totals']['comida_count'];
        }

        return [
            'total_hours' => round($totalHours, 2),
            'weekend_hours' => round($weekendHours, 2),
            'weekend_worked_hours' => round($weekendWorked, 2),
            // Suma de las unidades por empleado (cada una ya a floor), no se
            // recalcula desde el total de horas: floor no es aditivo y mezclaría
            // empleados. Consistente con la nómina y con cada fila.
            'weekend_units' => $weekendUnitHours ? $weekendUnits : null,
            'detected_hours' => round($detectedHours, 2),
            'pending_hours' => round($pendingHours, 2),
            'velada_count' => $veladaCount,
            'cena_count' => $cenaCount,
            'comida_count' => $comidaCount,
            'employee_count' => count($rows),
        ];
    }
}
