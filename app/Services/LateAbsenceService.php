<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\SystemSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Única fuente de verdad de la regla "retardos acumulados → falta".
 *
 * Regla de negocio (DECISIONES §1, ACTUALIZADA por Luis 2026-10-01: "una vez
 * que se cumplan los 6 retardos y que estén dentro de la fecha de corte,
 * aplicar la falta, no esperar hasta el próximo mes"):
 * - Los retardos se acumulan por MES calendario.
 * - Cada vez que el acumulado del mes CRUZA un múltiplo del umbral se genera
 *   de inmediato una incidencia FRT auto-aprobada de 1 día, fechada EL DÍA
 *   del retardo que cruzó — así la deducción cae en el corte (semana) donde
 *   se cumplió la regla, no en el mes siguiente. El 2º cruce (12 retardos)
 *   genera otra, fechada en su propio día.
 * - Idempotente por (empleado, mes, secuencia) vía incidents.late_month
 *   ('YYYY-MM' para la 1ª, 'YYYY-MM#n' para las siguientes). Una FRT
 *   soft-deleted cuenta como procesada: borrarla es una decisión humana
 *   explícita (perdón) y NO debe regenerarse; el conteo de lo ya generado
 *   suma days_count incluidas las borradas, así la transición con las FRT
 *   históricas (regla vieja: fechadas el día 1 del mes siguiente) nunca
 *   duplica.
 * - Los meses anteriores al corte (monthly_late_absence_start_month) nunca se
 *   generan: la historia la manejó el sistema semanal legado.
 *
 * Qué cuenta como retardo: un attendance_record con status 'late' en día
 * laborable del empleado que no sea festivo. Los días que escalaron a
 * 'absent' (retardo >= max_late_minutes_before_absence) ya son falta por sí
 * mismos y NO cuentan además como retardo.
 */
class LateAbsenceService
{
    public const FRT_CODE = 'FRT';

    private const SOURCE_CORRECTED = 'Corrección automática de retardos: el umbral ya no se cumple.';

    /**
     * Umbral configurable: cada N retardos = 1 falta.
     */
    public function threshold(): int
    {
        return max(1, (int) SystemSetting::get('late_to_absence_count', 6));
    }

    /**
     * Primer mes en que aplica la regla mensual (inicio de mes), o null si el
     * setting no existe o es inválido (la regla queda inactiva).
     */
    public function startMonth(): ?Carbon
    {
        $value = (string) SystemSetting::get('monthly_late_absence_start_month', '');

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d', $value.'-01')->startOfDay();
    }

    /**
     * Faltas que corresponden a un número de retardos: floor(retardos/umbral).
     */
    public function absencesFromLates(int $lateCount): int
    {
        return intdiv(max(0, $lateCount), $this->threshold());
    }

    /**
     * Cuenta los retardos del mes para un empleado: registros 'late' en días
     * laborables del empleado, excluyendo festivos.
     */
    public function lateCountForMonth(Employee $employee, Carbon $month): int
    {
        return count($this->lateDatesForMonth($employee, $month));
    }

    /**
     * Fechas (Y-m-d, orden cronológico) de los retardos contables del mes:
     * registros 'late' en días laborables del empleado, sin festivos. La
     * n-ésima falta por acumulación se fecha en la fecha del retardo que
     * cruza su umbral (posición n×umbral de esta lista).
     *
     * @return list<string>
     */
    public function lateDatesForMonth(Employee $employee, Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $holidayDates = Holiday::whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->all();

        return AttendanceRecord::where('employee_id', $employee->id)
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->where('status', 'late')
            ->orderBy('work_date')
            ->get(['work_date'])
            ->filter(function ($record) use ($employee, $holidayDates) {
                $date = Carbon::parse($record->work_date);

                if (in_array($date->toDateString(), $holidayDates, true)) {
                    return false;
                }

                // Sábado y domingo no son obligatorios (Dani 2026-07-08): un
                // retardo de fin de semana no cuenta para el FRT mensual.
                return $employee->isObligatoryWorkDay($date);
            })
            ->map(fn ($record) => Carbon::parse($record->work_date)->toDateString())
            ->values()
            ->all();
    }

    /**
     * Asegura las incidencias FRT del mes para un empleado, generando de
     * inmediato las de cada umbral cruzado (regla de Luis 2026-10-01: la
     * falta se aplica en el corte donde se cumple el 6º retardo, fechada ese
     * día). Devuelve las incidencias CREADAS en esta llamada (vacío si no
     * procede o no hay nada pendiente).
     *
     * @return list<Incident>
     */
    public function generateForMonth(Employee $employee, Carbon $month, ?Carbon $today = null): array
    {
        // Exentos de checador ("No checa"): sus retardos residuales (de antes
        // de marcar la casilla) jamás generan falta por acumulación — sus
        // faltas se capturan por incidencia manual (Elias 2026-08-07).
        $today = $today ?? Carbon::today();
        $month = $month->copy()->startOfMonth();
        $startMonth = $this->startMonth();

        // Solo meses bajo la regla, y nunca meses futuros. El mes CORRIENTE
        // sí se procesa (regla de Luis 2026-10-01): el acumulado "a la fecha"
        // genera la falta el mismo día que cruza el umbral.
        if (! $startMonth || $month->lt($startMonth)) {
            return [];
        }
        if ($month->gt($today->copy()->startOfMonth())) {
            return [];
        }

        $lateMonth = $month->format('Y-m');
        $changedDates = [];

        // Lock the employee, including when no incident exists yet. Every
        // generator uses this lock, so concurrent payroll/cron cannot insert
        // the same sequence. SQLite serializes writers at transaction level.
        $created = DB::transaction(function () use ($employee, $month, $today, $lateMonth, &$changedDates) {
            $employee = Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $existing = Incident::withTrashed()->where('employee_id', $employee->id)
                ->where(function ($q) use ($lateMonth) {
                    $q->where('late_month', $lateMonth)->orWhere('late_month', 'like', $lateMonth.'#%');
                })->orderBy('id')->get();
            $lateDates = array_values(array_filter($this->lateDatesForMonth($employee, $month),
                fn ($date) => $date <= $today->toDateString()));
            $absences = $employee->is_attendance_exempt ? 0 : $this->absencesFromLates(count($lateDates));
            $type = IncidentType::where('code', self::FRT_CODE)->first();
            if (! $type) {
                Log::warning("IncidentType FRT no encontrado para {$lateMonth}.");

                return [];
            }

            // Legacy rows can represent several days, charged next month.
            // Preserve both that history and every explicitly forgiven slot.
            $covered = [];
            $managed = [];
            foreach ($existing as $incident) {
                $sequence = str_contains($incident->late_month, '#')
                    ? (int) explode('#', $incident->late_month)[1] : 1;
                $days = max(1, (int) $incident->days_count);
                $legacy = $days > 1 || $incident->start_date->format('Y-m') !== $lateMonth;
                if ($incident->trashed() || $legacy || $incident->approved_by !== null || ($incident->status !== 'approved'
                    && ! ($incident->status === 'rejected' && $incident->rejection_reason === self::SOURCE_CORRECTED))) {
                    for ($n = $sequence; $n < $sequence + $days; $n++) {
                        $covered[$n] = true;
                    }

                    continue;
                }
                $managed[$sequence] = $incident;
            }

            $created = [];
            $last = max($absences, $managed ? max(array_keys($managed)) : 0);
            for ($n = 1; $n <= $last; $n++) {
                if (isset($covered[$n])) {
                    continue;
                }
                $incident = $managed[$n] ?? null;
                if ($n > $absences) {
                    if ($incident && $incident->status === 'approved') {
                        $changedDates[] = $incident->start_date->toDateString();
                        $incident->update(['status' => 'rejected', 'rejection_reason' => self::SOURCE_CORRECTED]);
                    }

                    continue;
                }
                $chargeDate = $lateDates[$n * $this->threshold() - 1];
                $attributes = [
                    'employee_id' => $employee->id,
                    'incident_type_id' => $type->id,
                    'start_date' => $chargeDate,
                    'end_date' => $chargeDate,
                    'days_count' => 1,
                    'late_month' => $n === 1 ? $lateMonth : $lateMonth.'#'.$n,
                    'reason' => "Falta por acumulación de retardos en {$lateMonth}: el {$chargeDate} se cumplió el retardo número ".($n * $this->threshold()).' del mes. Se descuenta en el corte donde se cumplió la regla.',
                    'status' => 'approved',
                    'rejection_reason' => null,
                    'approved_by' => null,
                    'approved_at' => now(),
                ];
                if (! $incident) {
                    $created[] = Incident::create($attributes);
                    $changedDates[] = $chargeDate;
                } elseif ($incident->status !== 'approved' || $incident->start_date->toDateString() !== $chargeDate) {
                    $changedDates[] = $incident->start_date->toDateString();
                    $incident->update($attributes);
                    $changedDates[] = $chargeDate;
                }
            }

            return $created;
        }, 3);

        // Invalidation may reenter payroll: reconcile every slot before doing
        // this. Paid receipts are only flagged by the existing service.
        foreach (array_unique($changedDates) as $date) {
            app(PayrollInvalidationService::class)->invalidate($employee->id, $date, $date);
        }

        return $created;
    }

    /**
     * Read-only report details: charge dates belong to the selected cut; all
     * month lates establish the threshold. Deleted/rejected slots never return
     * as projections. Both reports use this same calculation.
     *
     * @return list<array{month:string,late_count:int,faltas:int,source:string,charged_on:string}>
     */
    public function reportDetails(Employee $employee, Carbon $start, Carbon $end): array
    {
        $rows = [];
        $incidents = Incident::withTrashed()->where('employee_id', $employee->id)
            ->whereNotNull('late_month')->orderBy('start_date')->get();
        $byMonth = $incidents->groupBy(fn ($i) => explode('#', $i->late_month)[0]);
        $datesByMonth = [];
        foreach ($incidents as $incident) {
            if ($incident->trashed() || $incident->status !== 'approved'
                || ! $incident->start_date->betweenIncluded($start->copy()->startOfDay(), $end->copy()->endOfDay())) {
                continue;
            }
            $key = explode('#', $incident->late_month)[0];
            $dates = $datesByMonth[$key] ??= $this->lateDatesForMonth($employee, Carbon::parse($key.'-01'));
            $rows[] = ['month' => $key, 'late_count' => count(array_filter($dates, fn ($date) => $date <= $end->copy()->min(Carbon::today())->toDateString())),
                'faltas' => max(1, (int) $incident->days_count), 'source' => 'cobrada',
                'charged_on' => $incident->start_date->toDateString()];
        }
        $ruleStart = $this->startMonth();
        if (! $ruleStart || $employee->is_attendance_exempt) {
            return $rows;
        }
        $through = $end->copy()->min(Carbon::today());
        for ($month = $start->copy()->startOfMonth(); $month->lte($through); $month->addMonthNoOverflow()) {
            if ($month->lt($ruleStart)) {
                continue;
            }
            $key = $month->format('Y-m');
            $dates = $datesByMonth[$key] ??= $this->lateDatesForMonth($employee, $month);
            $dates = array_values(array_filter($dates, fn ($d) => $d <= $through->toDateString()));
            $covered = [];
            foreach ($byMonth->get($key, collect()) as $incident) {
                $sequence = str_contains($incident->late_month, '#') ? (int) explode('#', $incident->late_month)[1] : 1;
                for ($n = $sequence; $n < $sequence + max(1, (int) $incident->days_count); $n++) {
                    $covered[$n] = true;
                }
            }
            for ($n = 1; $n <= $this->absencesFromLates(count($dates)); $n++) {
                $date = $dates[$n * $this->threshold() - 1];
                if (isset($covered[$n]) || $date < $start->toDateString()) {
                    continue;
                }
                $rows[] = ['month' => $key, 'late_count' => count($dates), 'faltas' => 1,
                    'source' => $key === Carbon::today()->format('Y-m') ? 'proyeccion' : 'pendiente_cierre',
                    'charged_on' => $date];
            }
        }

        return $rows;
    }

    /**
     * Garantiza que todos los meses desde el corte, incluido el actual, tengan su FRT
     * generada para el empleado. Idempotente; seguro de llamar en cada
     * cálculo de nómina. Devuelve cuántas incidencias se crearon.
     */
    public function ensureMonthlyIncidentsGenerated(Employee $employee, ?Carbon $today = null): int
    {
        $today = $today ?? Carbon::today();
        $startMonth = $this->startMonth();

        if (! $startMonth) {
            return 0;
        }

        // Hasta el mes CORRIENTE inclusive (regla de Luis 2026-10-01): el
        // acumulado en curso ya genera sus faltas al cruzar el umbral.
        $lastMonth = $today->copy()->startOfMonth();
        $generated = 0;

        for ($month = $startMonth->copy(); $month->lte($lastMonth); $month->addMonthNoOverflow()) {
            $generated += count($this->generateForMonth($employee, $month, $today));
        }

        return $generated;
    }

    /**
     * Reconcile eligible employee/month pairs, including the current month,
     * late imports and months whose previously generated source disappeared.
     *
     * @param  \Illuminate\Support\Collection<int, Employee>  $employees
     */
    public function ensureForEmployees(\Illuminate\Support\Collection $employees, ?Carbon $today = null): int
    {
        $today = $today ?? Carbon::today();
        $startMonth = $this->startMonth();

        if (! $startMonth || $employees->isEmpty()) {
            return 0;
        }

        $generated = 0;
        for ($month = $startMonth->copy(); $month->lte($today->copy()->startOfMonth()); $month->addMonthNoOverflow()) {
            // Candidates include previously generated incidents, even if all
            // source lates disappeared. Closed months may receive late imports.
            $candidateIds = AttendanceRecord::whereIn('employee_id', $employees->pluck('id'))
                ->whereBetween('work_date', [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()])
                ->where('status', 'late')->distinct()->pluck('employee_id');
            $key = $month->format('Y-m');
            $existingIds = Incident::withTrashed()->whereIn('employee_id', $employees->pluck('id'))
                ->where(fn ($q) => $q->where('late_month', $key)->orWhere('late_month', 'like', $key.'#%'))
                ->distinct()->pluck('employee_id');
            foreach ($employees->whereIn('id', $candidateIds->merge($existingIds)->unique()) as $employee) {
                $generated += count($this->generateForMonth($employee, $month, $today));
            }
        }

        return $generated;
    }
}
