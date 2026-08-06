<?php

namespace App\Services\Reports;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Department;
use App\Models\Employee;
use App\Services\CompensationRateResolverService;
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
        private readonly CompensationRateResolverService $resolver = new CompensationRateResolverService,
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
     * Códigos que YA tienen columna/marcador fijo en el reporte. Cualquier otro
     * concepto aprobado se agrupa en "otros conceptos" para que también se vea.
     */
    private const KNOWN_CODES = ['HE', 'HED', 'HET', 'FIN', 'VEL', 'CENA', 'COM'];

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

        $employees = Employee::with(['schedule', 'department', 'compensationTypes'])
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

        // Rangos de PERSONAL DE ENTREGAS (Dani 2026-07-28): en esas fechas la
        // velada y el tiempo extra autorizados se muestran COMPLETOS, sin topar
        // al timecard, porque el colaborador andaba en la calle y su checada no
        // lo refleja — misma cifra que paga la nómina. Se traen los rangos que
        // tocan el rango del reporte, por empleado, como pares [start, end].
        $deliveryPeriods = \App\Models\DeliveryPeriod::query()
            ->whereIn('employee_id', $employeeIds)
            ->overlapping($start->toDateString(), $end->toDateString())
            ->get(['employee_id', 'start_date', 'end_date'])
            ->groupBy('employee_id')
            ->map(fn ($rows) => $rows->map(fn ($p) => [$p->start_date->toDateString(), $p->end_date->toDateString()])->all());

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
            $deliveryPeriods->get($employee->id, []),
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
        array $deliveryPeriods = [],
    ): array {
        $recordsByDate = $records->keyBy(fn (AttendanceRecord $r) => $r->work_date->toDateString());
        $authsByDate = $authorizations->groupBy(fn (Authorization $a) => $a->date->toDateString());

        $days = [];
        $weeklyExtra = 0.0;
        $weeklyWeekend = 0.0;
        $weeklyWeekendWorked = 0.0;
        $weekendUnitsAccum = 0;
        $veladaCount = 0;
        $cenaCount = 0;
        $comidaCount = 0;

        $weeklyDetected = 0.0;
        $weeklyPending = 0.0;

        foreach ($dates as $date) {
            $record = $recordsByDate->get($date);
            $dayAuths = $authsByDate->get($date, collect());

            $isDeliveryDay = false;
            foreach ($deliveryPeriods as [$from, $to]) {
                if ($date >= $from && $date <= $to) {
                    $isDeliveryDay = true;
                    break;
                }
            }
            $day = $this->buildDay($employee, $date, $record, $dayAuths, $isDeliveryDay);

            $days[$date] = $day;
            $weeklyExtra += $day['overtime_hours'];
            $weeklyWeekend += $day['weekend_hours'];
            $weeklyWeekendWorked += $day['weekend_worked_hours'];
            $weeklyDetected += $day['detected_overtime_hours'];
            $weeklyPending += $day['pending_overtime_hours'];
            $veladaCount += $day['velada_marker'];
            $cenaCount += $day['cena_marker'];
            $comidaCount += $day['comida_marker'];

            // Unidades de fin de semana POR DÍA autorizado (Almacén): cada día de
            // fin de semana con autorización FIN cuenta al menos 1, aunque trabaje
            // < 1 unidad (regla de Dani 2026-06-28: "aunque se presenten 1 hora es
            // un fin de semana"); 12 h ÷ 6 = 2. Coincide con la nómina.
            if ($weekendUnitHours && $day['has_weekend_auth']) {
                $weekendUnitsAccum += max(1, (int) floor($day['weekend_worked_hours'] / $weekendUnitHours));
            }
        }

        $weekendUnits = $weekendUnitHours ? $weekendUnitsAccum : null;
        $extraConcepts = $this->buildExtraConcepts($authorizations, $employee);

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
            'extra_concepts' => $extraConcepts,
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
        bool $isDeliveryDay = false,
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
            'has_weekend_auth' => false,
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
            // Día sin timecard medible (sin fila o con checada incompleta —
            // p. ej. entrada sin salida): TODO lo aprobado se muestra desde la
            // autorización, que es la evidencia cuando no hay horas que medir
            // — igual que la nómina. Aplica a los conceptos por día (FIN,
            // Comida, Cena, Velada) y también a las horas extra (Dani
            // 2026-07-08, caso Julissa: TE aprobado con salida no marcada).
            // El tope al timecard (auditoría #20) solo rige cuando SÍ hay
            // checada completa que medir.
            return $this->buildUnbackedDay($blank, $dayAuthorizations);
        }

        $byCode = $dayAuthorizations->groupBy(fn (Authorization $a) => $this->normalizeCode($a->compensationType?->code));

        // El excedente aprobado "fuera de checada" (is_unbacked_extra, split de
        // Elias 2026-08-05) se separa del resto: la nómina lo paga SIN topar al
        // timecard, así que el reporte lo suma después del tope para que reporte
        // y recibo coincidan.
        $authorizedOvertimeRaw = 0.0;
        $unbackedExtraHours = 0.0;
        foreach (self::OVERTIME_CODES as $code) {
            foreach ($byCode->get($code, collect()) as $auth) {
                if ($auth->is_unbacked_extra) {
                    $unbackedExtraHours += (float) $auth->hours;
                } else {
                    $authorizedOvertimeRaw += (float) $auth->hours;
                }
            }
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

        // FIN DE SEMANA en deptos que NO pagan por unidades fijas (Dani
        // 2026-07-07): el tiempo extra es lo que exceda del umbral T (o TODO si se
        // trabajó < T), igual que la nómina (VeladaCalculatorService), no lo que
        // exceda del horario. Así el reporte y el recibo coinciden en el OT del
        // fin de semana. Almacén PT (paga por unidades) devuelve NULL y conserva
        // la detección por horario. Las horas se cuentan CORRIDAS, sin descontar
        // la comida (Dani 2026-07-08, caso Eva Adriana).
        if ($isWeekendWork) {
            $totalWeekend = $record->grossSpanHours()
                ?? (float) ($record->worked_hours ?? 0) + (float) ($record->overtime_hours ?? 0);
            $weekendThreshold = $employee->weekendOvertimeThresholdForHours($totalWeekend);
            if ($weekendThreshold !== null) {
                $detectedHours = $this->rounding->roundMinutes((int) round(max(0.0, $totalWeekend - $weekendThreshold) * 60));
            }
        }

        // Tope al timecard (auditoría #20 / DECISIONES derivadas): las horas
        // autorizadas mostradas no pueden exceder lo realmente detectado en
        // checadas — el mismo tope que aplica la nómina al pagar. Si se
        // aprobaron más horas de las trabajadas, el reporte muestra lo
        // pagable, no la autorización inflada.
        // En semana de personal de entregas, lo autorizado NO se topa al
        // timecard: se muestra completo (Dani 2026-07-28), igual que la nómina.
        // Fuera de eso rige el tope al timecard (auditoría #20).
        $overtimeHours = ($isDeliveryDay ? $authorizedOvertimeRaw : min($authorizedOvertimeRaw, $detectedHours))
            + $unbackedExtraHours;
        $veladaHours = $isDeliveryDay ? $authorizedVeladaRaw : min($authorizedVeladaRaw, (float) ($record->velada_hours ?? 0));

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
            'has_weekend_auth' => $byCode->has(self::WEEKEND_CODE),
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
     * Celda de un día SIN timecard medible (sin fila de asistencia o con
     * checada incompleta, p. ej. entrada sin salida). Todo lo aprobado se
     * muestra a valor de su autorización: los conceptos por día (FIN, Comida,
     * Cena, Velada) y también las horas extra (Dani 2026-07-08, caso
     * Julissa). Sin checada completa no hay nada detectado ni pendiente, y
     * el tope al timecard solo rige cuando sí hay checada que medir.
     */
    private function buildUnbackedDay(array $blank, Collection $dayAuthorizations): array
    {
        if ($dayAuthorizations->isEmpty()) {
            return $blank;
        }

        $byCode = $dayAuthorizations->groupBy(fn (Authorization $a) => $this->normalizeCode($a->compensationType?->code));

        $overtimeHours = 0.0;
        foreach (self::OVERTIME_CODES as $code) {
            $overtimeHours += (float) $byCode->get($code, collect())->sum('hours');
        }

        return array_merge($blank, [
            'overtime_hours' => round($overtimeHours, 2),
            'm_hours' => round($overtimeHours, 2),
            'velada_hours' => round((float) $byCode->get(self::VELADA_CODE, collect())->sum('hours'), 2),
            'weekend_hours' => round((float) $byCode->get(self::WEEKEND_CODE, collect())->sum('hours'), 2),
            'has_weekend_auth' => $byCode->has(self::WEEKEND_CODE),
            'velada_marker' => $byCode->has(self::VELADA_CODE) ? 1 : 0,
            'cena_marker' => $byCode->has(self::CENA_CODE) ? 1 : 0,
            'comida_marker' => $byCode->has(self::COMIDA_CODE) ? 1 : 0,
        ]);
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
     * (Los "otros conceptos" aprobados sin columna fija se muestran en su propia
     * columna OTROS CONCEPTOS, no aquí — Dani 2026-07-01.)
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
     * Otros conceptos aprobados que NO tienen columna fija en el reporte (p. ej.
     * una compensación nueva creada a mano como "Cena por entrega a Walmart").
     * Se agrupan por nombre con su conteo y horas, para que TODO concepto
     * aprobado se vea en el reporte (petición de Dani 2026-07-01), no solo los
     * que se cargan desde checadas.
     *
     * Cada concepto lleva también su VALOR en pesos (Luis 2026-07-09: "que me
     * dé el valor y al final la suma"), calculado igual que la nómina: cantidad
     * × tarifa del empleado para las autorizaciones, y el monto fijo (con signo
     * — negativo = deducción) para los recurrentes.
     *
     * @return list<array{name: string, count: int, hours: float, amount: float}>
     */
    private function buildExtraConcepts(Collection $authorizations, ?Employee $employee = null): array
    {
        $extra = [];
        $hourlyRate = (float) ($employee?->hourly_rate ?? 0);
        $dailySalary = (float) ($employee?->daily_salary_computed ?? 0);

        foreach ($authorizations as $auth) {
            $type = $auth->compensationType;
            if (! $type) {
                continue;
            }

            if (in_array($this->normalizeCode($type->code), self::KNOWN_CODES, true)) {
                continue;
            }

            $name = $type->name ?: ($this->normalizeCode($type->code) ?: 'Concepto');
            $extra[$name] ??= ['name' => $name, 'count' => 0, 'hours' => 0.0, 'amount' => 0.0];
            $extra[$name]['count']++;
            $extra[$name]['hours'] += (float) $auth->hours;
            $extra[$name]['amount'] += $employee
                ? $this->authorizationConceptAmount($employee, $type, (float) $auth->hours, $hourlyRate, $dailySalary)
                : 0.0;
        }

        // Conceptos RECURRENTES semanales inscritos al empleado (Luis
        // 2026-07-09): cantidades fijas que se pagan cada semana sin
        // autorización. Aparecen una vez en "Otros Conceptos" para que se vean
        // en la hoja, ya que no tienen una fila de autorización que los traiga.
        foreach ($employee?->compensationTypes ?? collect() as $type) {
            if (! $type->pivot?->is_active || ! $type->is_recurring || ! $type->paidWeekly()) {
                continue;
            }
            if (in_array($this->normalizeCode($type->code), self::KNOWN_CODES, true)) {
                continue;
            }

            $name = $type->name ?: ($this->normalizeCode($type->code) ?: 'Concepto');
            $extra[$name] ??= ['name' => $name, 'count' => 0, 'hours' => 0.0, 'amount' => 0.0];
            $extra[$name]['count']++;
            $extra[$name]['amount'] += $this->recurringConceptAmount($employee, $type, $dailySalary);
        }

        return array_values(array_map(
            fn (array $e) => [
                'name' => $e['name'],
                'count' => $e['count'],
                'hours' => round($e['hours'], 2),
                'amount' => round($e['amount'], 2),
            ],
            $extra,
        ));
    }

    /**
     * Valor en pesos de una autorización de concepto, con el MISMO criterio que
     * la nómina (CompensationRateResolverService::payAuthorizationConcepts):
     * cantidad (bonos/horas/días) × tarifa resuelta del empleado.
     */
    private function authorizationConceptAmount(
        Employee $employee,
        CompensationType $type,
        float $authHours,
        float $hourlyRate,
        float $dailySalary,
    ): float {
        $rate = $this->resolver->resolveRate($employee, $type);

        [$hours, $days] = match ($type->application_mode) {
            CompensationType::APPLICATION_PER_HOUR => [$authHours, 0.0],
            CompensationType::APPLICATION_PER_DAY => [0.0, 1.0],
            default => [0.0, 0.0],
        };

        // La cantidad conserva su signo: negativa = descuento (deducción), igual
        // que en la nómina.
        $quantity = ($type->application_mode === CompensationType::APPLICATION_ONE_TIME && abs($authHours) > 0)
            ? $authHours
            : 1.0;

        $amount = $type->calculateCompensation($hourlyRate, $dailySalary, $hours, $days, $rate['percentage'], $rate['fixed_amount']);

        return $quantity !== 1.0 ? round($amount * $quantity, 2) : $amount;
    }

    /**
     * Valor en pesos de un concepto recurrente (mismo criterio que la nómina):
     * el monto fijo con su signo (negativo = deducción) o el % del sueldo diario.
     */
    private function recurringConceptAmount(Employee $employee, CompensationType $type, float $dailySalary): float
    {
        $rate = $this->resolver->resolveRate($employee, $type);

        return $rate['fixed_amount'] !== null
            ? round((float) $rate['fixed_amount'], 2)
            : round($dailySalary * ((float) ($rate['percentage'] ?? 0) / 100), 2);
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
        $extraConcepts = [];

        foreach ($rows as $row) {
            foreach ($row['extra_concepts'] ?? [] as $ec) {
                $extraConcepts[$ec['name']] ??= ['name' => $ec['name'], 'count' => 0, 'hours' => 0.0, 'amount' => 0.0];
                $extraConcepts[$ec['name']]['count'] += $ec['count'];
                $extraConcepts[$ec['name']]['hours'] += $ec['hours'];
                $extraConcepts[$ec['name']]['amount'] += $ec['amount'] ?? 0.0;
            }
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
            'extra_concepts' => array_values(array_map(
                fn (array $e) => ['name' => $e['name'], 'count' => $e['count'], 'hours' => round($e['hours'], 2), 'amount' => round($e['amount'] ?? 0.0, 2)],
                $extraConcepts,
            )),
            'employee_count' => count($rows),
        ];
    }
}
