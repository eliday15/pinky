<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\SystemSetting;
use App\Services\PayrollInvalidationService;
use Carbon\Carbon;
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

        if (! preg_match('/^\d{4}-\d{2}$/', $value)) {
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
        if ($employee->is_attendance_exempt) {
            return [];
        }

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

        // Lo ya generado del mes se mide en DÍAS de falta (incluidas las
        // soft-deleted: borrarlas fue un perdón humano explícito y no se
        // regeneran). Esto hace la transición exacta con las FRT históricas
        // de la regla vieja (una incidencia día-1 con days_count N).
        $generatedDays = (int) Incident::withTrashed()
            ->where('employee_id', $employee->id)
            ->where(function ($q) use ($lateMonth) {
                $q->where('late_month', $lateMonth)
                    ->orWhere('late_month', 'like', $lateMonth.'#%');
            })
            ->sum('days_count');

        $lateDates = $this->lateDatesForMonth($employee, $month);
        $absences = $this->absencesFromLates(count($lateDates));

        if ($absences <= $generatedDays) {
            return [];
        }

        $incidentType = IncidentType::where('code', self::FRT_CODE)->first();

        if (! $incidentType) {
            Log::warning("IncidentType '".self::FRT_CODE."' no encontrado; no se puede generar la falta por retardos de {$lateMonth}.");

            return [];
        }

        $threshold = $this->threshold();
        $monthLabel = $month->copy()->locale('es')->isoFormat('MMMM YYYY');
        $created = [];

        for ($n = $generatedDays + 1; $n <= $absences; $n++) {
            // Fechada el día del retardo que cruzó el umbral n×threshold:
            // cae en el corte (semana) donde se cumplió la regla.
            $chargeDate = $lateDates[$n * $threshold - 1];

            $created[] = Incident::create([
                'employee_id' => $employee->id,
                'incident_type_id' => $incidentType->id,
                'start_date' => $chargeDate,
                'end_date' => $chargeDate,
                'days_count' => 1,
                'late_month' => $n === 1 ? $lateMonth : $lateMonth.'#'.$n,
                'reason' => 'Falta por acumulación de retardos en '.$monthLabel.': el '.$chargeDate.' se cumplió el retardo número '.($n * $threshold).' del mes (umbral: '.$threshold.'). Se descuenta en el corte donde se cumplió la regla (Luis 2026-10-01).',
                'status' => 'approved',
                'approved_by' => null,
                'approved_at' => now(),
            ]);

        }

        // El corte que contiene cada fecha puede estar ya calculado: se marca
        // para recálculo (un draft se recalcula solo; pagados quedan solo
        // señalados). DESPUÉS de crear todas las del mes: invalidar en medio
        // del loop re-entra al cálculo del draft y duplicaba la secuencia.
        foreach ($created as $incident) {
            app(PayrollInvalidationService::class)->invalidate(
                $employee->id,
                $incident->start_date->toDateString(),
                $incident->start_date->toDateString(),
            );
        }

        return $created;
    }

    /**
     * Garantiza que todos los meses cerrados desde el corte tengan su FRT
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
     * Versión en lote de ensureMonthlyIncidentsGenerated para el cálculo de
     * nómina: un solo query trae los pares (empleado, mes) ya procesados y
     * solo se intenta generar lo que falta. En estado estable (todo generado)
     * cuesta 1 query en total en vez de meses × empleados.
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

        $lastClosed = $today->copy()->startOfMonth()->subMonthNoOverflow();

        if ($startMonth->gt($lastClosed)) {
            return 0;
        }

        // Pares empleado|mes ya procesados — con soft-deleted, igual que
        // generateForMonth: una FRT borrada fue decisión humana, no se regenera.
        $processed = Incident::withTrashed()
            ->whereIn('employee_id', $employees->pluck('id'))
            ->whereNotNull('late_month')
            ->get(['employee_id', 'late_month'])
            ->map(fn (Incident $i) => $i->employee_id.'|'.$i->late_month)
            ->flip();

        $generated = 0;

        for ($month = $startMonth->copy(); $month->lte($lastClosed); $month->addMonthNoOverflow()) {
            $monthKey = $month->format('Y-m');

            $pending = $employees->filter(
                fn (Employee $e) => ! isset($processed[$e->id.'|'.$monthKey])
            );

            if ($pending->isEmpty()) {
                continue;
            }

            $start = $month->copy()->startOfMonth();
            $end = $month->copy()->endOfMonth();

            $holidayDates = Holiday::whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->pluck('date')
                ->map(fn ($d) => Carbon::parse($d)->toDateString())
                ->all();

            // Retardos del mes de TODOS los pendientes en un query. El conteo
            // en memoria replica lateCountForMonth: excluye festivos y días no
            // obligatorios (fin de semana / fuera de horario).
            $latesByEmployee = AttendanceRecord::whereIn('employee_id', $pending->pluck('id'))
                ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
                ->where('status', 'late')
                ->get(['employee_id', 'work_date'])
                ->groupBy('employee_id');

            foreach ($pending as $employee) {
                $lateCount = $latesByEmployee->get($employee->id, collect())
                    ->filter(function ($record) use ($employee, $holidayDates) {
                        $date = Carbon::parse($record->work_date);

                        if (in_array($date->toDateString(), $holidayDates, true)) {
                            return false;
                        }

                        return $employee->isObligatoryWorkDay($date);
                    })
                    ->count();

                if ($this->absencesFromLates($lateCount) < 1) {
                    // Sin FRT que generar: el mes queda sin marca (igual que el
                    // camino por-empleado) y se re-evalúa en el siguiente
                    // cálculo — pero ya al costo del query en lote.
                    continue;
                }

                // Candidato real (raro): generateForMonth re-verifica
                // idempotencia y conteo por su cuenta — sigue siendo la única
                // fuente de verdad de la creación.
                $generated += count($this->generateForMonth($employee, $month, $today));
            }
        }

        // MES CORRIENTE (regla de Luis 2026-10-01): el acumulado en curso
        // genera su falta el día que cruza el umbral. Pre-filtro barato en un
        // query: solo los empleados cuyo conteo bruto de 'late' del mes llega
        // al umbral pasan al generador (que aplica los filtros finos de
        // festivos/días obligatorios e idempotencia por secuencia).
        $currentMonth = $today->copy()->startOfMonth();
        if ($currentMonth->gte($startMonth)) {
            $threshold = $this->threshold();
            $candidateIds = AttendanceRecord::whereIn('employee_id', $employees->pluck('id'))
                ->whereBetween('work_date', [
                    $currentMonth->toDateString(),
                    $currentMonth->copy()->endOfMonth()->toDateString(),
                ])
                ->where('status', 'late')
                ->selectRaw('employee_id, count(*) as c')
                ->groupBy('employee_id')
                ->havingRaw('count(*) >= ?', [$threshold])
                ->pluck('employee_id');

            foreach ($employees->whereIn('id', $candidateIds) as $employee) {
                $generated += count($this->generateForMonth($employee, $currentMonth, $today));
            }
        }

        return $generated;
    }
}
