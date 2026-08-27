<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\DeliveryPeriod;
use App\Models\Employee;
use App\Models\Schedule;
use App\Models\SystemSetting;
use Carbon\Carbon;

/**
 * Service for calculating velada (night shift overtime after midnight).
 *
 * Logic:
 * - Regular hours = within scheduled shift
 * - Overtime = extra hours before midnight (or before velada start)
 * - Velada = hours worked after midnight (or after velada start hour)
 * - Only authorized hours get paid
 * - Overtime is rounded with the company ladder (OvertimeRoundingService)
 *   BEFORE capping at the authorized hours (DECISIONES_NEGOCIO §10), so the
 *   weekly report and payroll always use the same rounded figure.
 */
class VeladaCalculatorService
{
    public function __construct(
        private readonly OvertimeRoundingService $rounding = new OvertimeRoundingService(),
    ) {}

    /**
     * Calculate overtime and velada split for an attendance record.
     *
     * @param AttendanceRecord $record The attendance record to calculate for
     * @param Employee $employee The employee who owns the record
     * @return array{overtime_hours: float, velada_hours: float, overtime_authorized: float, velada_authorized: float}
     */
    public function calculate(AttendanceRecord $record, Employee $employee): array
    {
        // Personal de entregas marcado en la semana (Dani 2026-07-28): la velada
        // AUTORIZADA se paga completa aunque la checada no la capture — andan en
        // la calle repartiendo y no alcanzan a checar la noche. null = no marcado
        // (comportamiento normal, topado contra lo checado).
        $veladaOverride = $this->deliveryWeekVeladaOverride($record);

        if (!$employee->schedule || !$record->check_in || !$record->check_out) {
            return [
                'overtime_hours' => 0,
                'velada_hours' => $veladaOverride ?? 0,
                'overtime_authorized' => 0,
                'velada_authorized' => $veladaOverride ?? 0,
            ];
        }

        // Ventana de velada en minutos del día. Por departamento si está
        // configurada (p. ej. BIES 15:30–22:30), si no la global (22:00–05:00,
        // que coincide con la AuthorizationController y el valor migrado/sembrado
        // para que una BD nueva use la misma ventana en todos lados).
        [$veladaStartMin, $veladaEndMin] = $this->resolveVeladaWindow($employee);

        $dateStr = $record->work_date->toDateString();
        $checkIn = Carbon::parse($dateStr . ' ' . Carbon::parse($record->check_in)->format('H:i:s'));
        $checkOut = Carbon::parse($dateStr . ' ' . Carbon::parse($record->check_out)->format('H:i:s'));

        // Handle midnight crossing. La comparación de horas falla cuando la
        // velada termina pasada la hora de entrada del día anterior (caso
        // Miguel 2026-08-27: 05:00 → 05:08 del día siguiente); la fecha real
        // de la huella de salida es la autoridad.
        if ($checkOut->lt($checkIn) || $record->outPunchCrossesMidnight()) {
            $checkOut->addDay();
        }

        // Get per-day schedule with employee overrides applied
        $dayName = strtolower(Carbon::parse($record->work_date)->format('l'));
        $daySchedule = $employee->getEffectiveScheduleForDay($dayName);

        $scheduledExit = Carbon::parse($dateStr . ' ' . Carbon::parse($daySchedule->exit_time)->format('H:i:s'));
        if ($scheduledExit->lt($checkIn)) {
            $scheduledExit->addDay();
        }

        $dailyHours = $daySchedule->daily_work_hours ?? 8;

        $totalWorkedMinutes = abs($checkIn->diffInMinutes($checkOut));

        // Subtract break (fallback: schedule -> department -> 60)
        $departmentBreak = $employee->department?->default_break_minutes;
        $breakMinutes = $record->actual_break_minutes ?: ($totalWorkedMinutes > 300 ? ($daySchedule->break_minutes ?? $departmentBreak ?? 60) : 0);
        $netWorkedMinutes = max(0, $totalWorkedMinutes - $breakMinutes);
        $netWorkedHours = $netWorkedMinutes / 60;

        // FIN DE SEMANA (deptos que NO pagan por unidades fijas). Regla de Dani
        // 2026-07-07: el "fin de semana" absorbe las primeras T horas (umbral del
        // empleado, 7 por omisión). Si trabajó >= T, el tiempo extra empieza tras
        // T (y aparte gana 1 fin de semana); si trabajó < T, no gana fin de semana
        // y TODO es tiempo extra (umbral 0). Almacén PT devuelve NULL (paga por
        // unidades) y aquí conserva la jornada del horario. Solo aplica en días de
        // fin de semana; el tiempo extra es aditivo al fin de semana (sin doble
        // pago porque el fin de semana no paga base ese día).
        //
        // Las horas del fin de semana se cuentan CORRIDAS, de entrada a salida
        // SIN descontar la comida (Dani 2026-07-08, caso Eva Adriana:
        // 09:07–18:02 = 8 h 55 − 7 = 1 h 55 → 2 h por la escalera).
        $extraBase = $netWorkedHours;
        $grossWorkedHours = $totalWorkedMinutes / 60;
        $weekendOtThreshold = $employee->weekendOvertimeThresholdForHours($grossWorkedHours);
        if ($record->is_weekend_work && $weekendOtThreshold !== null) {
            $dailyHours = $weekendOtThreshold;
            $extraBase = $grossWorkedHours;
        }

        $extraHours = max(0, $extraBase - $dailyHours);

        // Velada window [start, end) — se construye ANTES del early-return
        // porque una velada PURA (entrar solo a velar: 22:00–05:00 con neto
        // menor que la jornada) también debe medirse (caso Policarpo dom
        // 2026-08-16: 7 h en ventana y velada_hours quedaba en 0, la noche
        // aprobada no contaba ni pagaba).
        if ($veladaStartMin === $veladaEndMin) {
            // No velada window configured.
            $veladaStart = null;
            $veladaEnd = null;
        } elseif ($veladaStartMin > $veladaEndMin) {
            // Crosses midnight (e.g., 22:00–05:00): start on work_date, end +1 día.
            $veladaStart = Carbon::parse($dateStr)->startOfDay()->addMinutes($veladaStartMin);
            $veladaEnd = Carbon::parse($dateStr)->startOfDay()->addDay()->addMinutes($veladaEndMin);
        } else {
            // Ventana del mismo día (BIES 15:30–22:30, o el legado 00:00–06:00).
            // Se ancla a la fecha del check-in; si toda la ventana termina antes
            // del check-in (un 00:00–06:00 para un turno que empezó la noche
            // anterior), pertenece al día siguiente.
            $veladaStart = Carbon::parse($dateStr)->startOfDay()->addMinutes($veladaStartMin);
            $veladaEnd = Carbon::parse($dateStr)->startOfDay()->addMinutes($veladaEndMin);
            if ($veladaEnd->lte($checkIn)) {
                $veladaStart->addDay();
                $veladaEnd->addDay();
            }
        }

        if ($extraHours <= 0) {
            // TE aprobado con las VENTANAS respaldadas por la checada aunque el
            // TOTAL del día no supere la jornada (Luis 2026-08-26/27, casos
            // Diana, Pamela, Eva): el retardo ya se castiga como retardo — la
            // ventana autorizada que las huellas cubren se paga. El respaldo se
            // mide por la UNIÓN de las ventanas aprobadas contra el span
            // checado (windowBackedOvertimeHours), y también con la vara del
            // reporte (entrada temprana + salida tardía por horario). Siempre
            // topado a lo autorizado. Y la velada se mide por su VENTANA: una
            // velada pura no genera "extra" pero sí es velada.
            $overtimeAuthorized = $this->getAuthorizedHours($record->employee_id, $dateStr, Authorization::TYPE_OVERTIME);
            $veladaAuthorized = $this->getAuthorizedHours($record->employee_id, $dateStr, Authorization::TYPE_NIGHT_SHIFT);

            $veladaInWindow = ($veladaStart && $veladaEnd)
                ? max(0, (min($checkOut->getTimestamp(), $veladaEnd->getTimestamp()) - max($checkIn->getTimestamp(), $veladaStart->getTimestamp())) / 3600)
                : 0.0;

            $windowGuardOff = $record->is_weekend_work && $weekendOtThreshold !== null;
            $scheduleBacked = ($overtimeAuthorized > 0 && ! $record->is_weekend_work)
                ? $this->rounding->detectOvertimeHours($record, $daySchedule, $dateStr)
                : 0.0;
            $windowBacked = ($overtimeAuthorized > 0 && ! $windowGuardOff)
                ? $this->windowBackedOvertimeHours($record, $checkIn, $checkOut, $veladaStart, $veladaEnd, $veladaAuthorized > 0)
                : 0.0;

            return [
                'overtime_hours' => 0,
                'velada_hours' => $veladaOverride ?? round($veladaInWindow, 2),
                'overtime_authorized' => round(min(max($scheduleBacked, $windowBacked), $overtimeAuthorized), 2),
                'velada_authorized' => $veladaOverride ?? round(min($veladaInWindow, $veladaAuthorized), 2),
            ];
        }

        $overtimeHours = 0;
        $veladaHours = 0;

        if (!$veladaStart || !$veladaEnd) {
            // No velada window — all extra is overtime
            $overtimeHours = $extraHours;
        } elseif ($checkOut->gt($veladaStart) && $checkOut->lte($veladaEnd)) {
            // Part of the work falls in velada window
            $veladaMinutes = abs($veladaStart->diffInMinutes($checkOut));
            $veladaHours = min($extraHours, $veladaMinutes / 60);
            $overtimeHours = max(0, $extraHours - $veladaHours);
        } elseif ($checkOut->gt($veladaEnd)) {
            // Worked past velada window
            $veladaMinutes = abs($veladaStart->diffInMinutes($veladaEnd));
            $veladaHours = min($extraHours, $veladaMinutes / 60);
            $overtimeHours = max(0, $extraHours - $veladaHours);
        } else {
            // All extra is regular overtime (before velada window)
            $overtimeHours = $extraHours;
        }

        // Check authorized hours (fecha como string: comparar un DATE contra
        // un Carbon datetime pierde filas en el límite — quirk de SQLite).
        $overtimeAuthorized = $this->getAuthorizedHours($record->employee_id, $dateStr, Authorization::TYPE_OVERTIME);
        $veladaAuthorized = $this->getAuthorizedHours($record->employee_id, $dateStr, Authorization::TYPE_NIGHT_SHIFT);

        // Escalera de redondeo al PAGO (DECISIONES_NEGOCIO §10): las horas
        // extra se redondean con la regla de la empresa (<25min→0,
        // 25-49→0.5h, 50-59→1h) ANTES de topar a lo autorizado — la misma
        // escalera del reporte semanal, para que reporte y nómina nunca
        // diverjan. La velada se paga por horas exactas en ventana (VEL).
        $overtimePayable = $this->rounding->roundMinutes((int) round($overtimeHours * 60));

        // El tope al pago también mide por VENTANAS (Luis 2026-08-26/27,
        // casos Diana/Pamela/Eva/Policarpo): la unión de las ventanas de TE
        // aprobadas que el span checado respalda — descontando la parte en
        // ventana de velada SOLO si hay velada aprobada ese día (esa hora ya
        // paga como velada; sin velada aprobada no hay doble y el TE paga
        // completo). Se toma la MAYOR de las medidas — nunca paga menos que
        // antes — y se mantiene la vara del reporte (horario) fuera de finde y
        // sin velada. En finde de deptos de umbral rige el umbral (sin tope
        // por ventanas).
        if ($overtimeAuthorized > 0) {
            if ($veladaHours <= 0 && ! $record->is_weekend_work) {
                $overtimePayable = max(
                    $overtimePayable,
                    $this->rounding->detectOvertimeHours($record, $daySchedule, $dateStr),
                );
            }
            if (! ($record->is_weekend_work && $weekendOtThreshold !== null)) {
                $overtimePayable = max(
                    $overtimePayable,
                    $this->windowBackedOvertimeHours($record, $checkIn, $checkOut, $veladaStart, $veladaEnd, $veladaAuthorized > 0),
                );
            }
        }

        return [
            'overtime_hours' => round($overtimeHours, 2),
            // En día con omisión aprobada la velada NO se topa contra lo checado:
            // se paga/reporta la autorizada completa (misma cifra en velada_hours
            // y velada_authorized para que reporte y recibo coincidan).
            'velada_hours' => $veladaOverride ?? round($veladaHours, 2),
            'overtime_authorized' => round(min($overtimePayable, $overtimeAuthorized), 2),
            'velada_authorized' => $veladaOverride ?? round(min($veladaHours, $veladaAuthorized), 2),
        ];
    }

    /**
     * Velada autorizada a pagar completa cuando la fecha del registro cae en un
     * rango de PERSONAL DE ENTREGAS del colaborador (Dani 2026-07-28).
     *
     * Devuelve la suma de las horas de velada autorizadas (sin topar por la
     * checada) o null si no está marcado — en cuyo caso rige el tope normal.
     */
    private function deliveryWeekVeladaOverride(AttendanceRecord $record): ?float
    {
        $dateStr = $record->work_date->toDateString();

        $onDelivery = DeliveryPeriod::query()
            ->where('employee_id', $record->employee_id)
            ->coveringDate($dateStr)
            ->exists();

        if (! $onDelivery) {
            return null;
        }

        return round($this->getAuthorizedHours($record->employee_id, $dateStr, Authorization::TYPE_NIGHT_SHIFT), 2);
    }

    /**
     * Resolve the velada window for an employee, in minutes-of-day [start, end].
     * Per-department when the department defines velada_start/velada_end
     * (e.g. BIES 15:30–22:30); otherwise the global setting (default 22:00–05:00).
     *
     * @return array{0: int, 1: int}
     */
    private function resolveVeladaWindow(Employee $employee): array
    {
        $dept = $employee->department;
        if ($dept && $dept->velada_start && $dept->velada_end) {
            return [
                $this->timeToMinutes((string) $dept->velada_start),
                $this->timeToMinutes((string) $dept->velada_end),
            ];
        }

        return [
            ((int) SystemSetting::get('velada_detection_start_hour', 22)) * 60,
            ((int) SystemSetting::get('velada_detection_end_hour', 5)) * 60,
        ];
    }

    /** Minutes-of-day for a 'HH:MM[:SS]' time string. */
    private function timeToMinutes(string $time): int
    {
        $parsed = Carbon::parse($time);

        return $parsed->hour * 60 + $parsed->minute;
    }

    /**
     * Get total authorized hours for a type on a date.
     *
     * @param int $employeeId The employee ID
     * @param mixed $date The date to check
     * @param string $type The authorization type
     * @return float Total authorized hours
     */
    /**
     * Horas de las VENTANAS de TE aprobadas del día que el span checado
     * respalda (Luis 2026-08-27). Se mide sobre la UNIÓN de las ventanas
     * (dos capturas encimadas no suman doble — el guard de encimados avisa
     * aparte) recortada al span [check_in, check_out] ya con el cruce de
     * medianoche resuelto. Si el día tiene VELADA aprobada, la parte de la
     * unión dentro de la ventana de velada se descuenta: esa hora paga como
     * velada, no dos veces. Sin velada aprobada no hay doble pago y el TE
     * aprobado que cubre la noche paga completo como TE.
     */
    private function windowBackedOvertimeHours(
        AttendanceRecord $record,
        Carbon $checkIn,
        Carbon $checkOut,
        ?Carbon $veladaStart,
        ?Carbon $veladaEnd,
        bool $veladaAuthorized,
    ): float {
        $dateStr = $record->work_date->toDateString();

        $windows = Authorization::where('employee_id', $record->employee_id)
            ->whereDate('date', $dateStr)
            ->where('type', Authorization::TYPE_OVERTIME)
            ->whereIn('status', [Authorization::STATUS_APPROVED, Authorization::STATUS_PAID])
            ->where('is_unbacked_extra', false)
            ->get(['start_time', 'end_time'])
            ->filter(fn (Authorization $a) => $a->start_time && $a->end_time)
            ->map(function (Authorization $a) use ($dateStr, $checkIn, $checkOut) {
                $start = Carbon::parse($dateStr.' '.$a->start_time->format('H:i:s'));
                $end = Carbon::parse($dateStr.' '.$a->end_time->format('H:i:s'));
                if ($end->lte($start)) {
                    $end->addDay();
                }

                // Recorte al span checado.
                $s = max($start->getTimestamp(), $checkIn->getTimestamp());
                $e = min($end->getTimestamp(), $checkOut->getTimestamp());

                return $e > $s ? [$s, $e] : null;
            })
            ->filter()
            ->sortBy(fn (array $w) => $w[0])
            ->values();

        // Unión de intervalos: los encimados no suman doble.
        $merged = [];
        foreach ($windows as [$s, $e]) {
            if ($merged !== [] && $s <= $merged[count($merged) - 1][1]) {
                $merged[count($merged) - 1][1] = max($merged[count($merged) - 1][1], $e);
            } else {
                $merged[] = [$s, $e];
            }
        }

        $seconds = 0;
        foreach ($merged as [$s, $e]) {
            $seconds += $e - $s;
            if ($veladaAuthorized && $veladaStart && $veladaEnd) {
                $seconds -= max(0, min($e, $veladaEnd->getTimestamp()) - max($s, $veladaStart->getTimestamp()));
            }
        }

        // Escalera de la empresa (igual que el reporte y el resto del pago):
        // 31 min respaldados = 0.5 h, no 0.52.
        return $this->rounding->roundMinutes((int) round(max(0, $seconds) / 60));
    }

    private function getAuthorizedHours(int $employeeId, $date, string $type): float
    {
        return (float) Authorization::where('employee_id', $employeeId)
            ->whereDate('date', Carbon::parse($date)->toDateString())
            ->where('type', $type)
            ->whereIn('status', [Authorization::STATUS_APPROVED, Authorization::STATUS_PAID])
            // El excedente aprobado "fuera de checada" se paga por su propia vía
            // en la nómina (sin tope al detectado); aquí se excluye para que el
            // mín(detectado, autorizado) no lo cuente dos veces.
            ->where('is_unbacked_extra', false)
            ->sum('hours');
    }
}
