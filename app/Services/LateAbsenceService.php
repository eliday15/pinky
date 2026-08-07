<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\SystemSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Única fuente de verdad de la regla "retardos acumulados → falta".
 *
 * Regla de negocio (docs/DECISIONES_NEGOCIO_2026-06-04.md §1):
 * - Los retardos se acumulan por MES calendario.
 * - Al cierre del mes se generan floor(retardos / umbral) faltas como UNA
 *   incidencia FRT auto-aprobada fechada el día 1 del mes SIGUIENTE, de modo
 *   que la deducción cae en la primera nómina calculada después del cierre
 *   ("retardos de junio se cobran en la 1ª nómina de julio").
 * - Idempotente por (empleado, mes) vía incidents.late_month. Una FRT
 *   soft-deleted cuenta como procesada: borrarla es una decisión humana
 *   explícita y NO debe regenerarse.
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
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $holidayDates = Holiday::whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->all();

        return AttendanceRecord::where('employee_id', $employee->id)
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->where('status', 'late')
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
            ->count();
    }

    /**
     * Genera (si procede) la incidencia FRT del mes para un empleado.
     *
     * Devuelve la incidencia creada, o null si el mes no es procesable
     * (no cerrado, anterior al corte), ya fue procesado, o no alcanza el
     * umbral de retardos.
     */
    public function generateForMonth(Employee $employee, Carbon $month, ?Carbon $today = null): ?Incident
    {
        // Exentos de checador ("No checa"): sus retardos residuales (de antes
        // de marcar la casilla) jamás generan falta por acumulación — sus
        // faltas se capturan por incidencia manual (Elias 2026-08-07).
        if ($employee->is_attendance_exempt) {
            return null;
        }

        $today = $today ?? Carbon::today();
        $month = $month->copy()->startOfMonth();
        $startMonth = $this->startMonth();

        // Solo meses bajo la regla y completamente terminados.
        if (! $startMonth || $month->lt($startMonth)) {
            return null;
        }
        if ($month->copy()->endOfMonth()->gte($today->copy()->startOfDay())) {
            return null;
        }

        $lateMonth = $month->format('Y-m');

        // Idempotencia: una FRT del mes (incluso soft-deleted) cuenta como
        // procesada — borrarla fue una decisión humana explícita.
        $alreadyProcessed = Incident::withTrashed()
            ->where('employee_id', $employee->id)
            ->where('late_month', $lateMonth)
            ->exists();

        if ($alreadyProcessed) {
            return null;
        }

        $lateCount = $this->lateCountForMonth($employee, $month);
        $absences = $this->absencesFromLates($lateCount);

        if ($absences < 1) {
            return null;
        }

        $incidentType = IncidentType::where('code', self::FRT_CODE)->first();

        if (! $incidentType) {
            Log::warning("IncidentType '".self::FRT_CODE."' no encontrado; no se puede generar la falta por retardos de {$lateMonth}.");

            return null;
        }

        // Fechada el día 1 del mes siguiente: cae en la primera nómina
        // calculada después del cierre del mes.
        $chargeDate = $month->copy()->addMonthNoOverflow()->startOfMonth()->toDateString();
        $threshold = $this->threshold();
        $monthLabel = $month->copy()->locale('es')->isoFormat('MMMM YYYY');

        return Incident::create([
            'employee_id' => $employee->id,
            'incident_type_id' => $incidentType->id,
            'start_date' => $chargeDate,
            'end_date' => $chargeDate,
            'days_count' => $absences,
            'late_month' => $lateMonth,
            'reason' => "Falta(s) por acumulación de {$lateCount} retardos en {$monthLabel} (umbral: {$threshold}). Generada automáticamente al cierre del mes; se descuenta en la primera nómina posterior.",
            'status' => 'approved',
            'approved_by' => null,
            'approved_at' => now(),
        ]);
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

        $lastClosed = $today->copy()->startOfMonth()->subMonthNoOverflow();
        $generated = 0;

        for ($month = $startMonth->copy(); $month->lte($lastClosed); $month->addMonthNoOverflow()) {
            if ($this->generateForMonth($employee, $month, $today) !== null) {
                $generated++;
            }
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
                if ($this->generateForMonth($employee, $month, $today) !== null) {
                    $generated++;
                }
            }
        }

        return $generated;
    }
}
