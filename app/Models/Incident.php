<?php

namespace App\Models;

use App\Traits\Auditable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Incident model for tracking employee incidents (vacations, sick leave, etc.).
 */
class Incident extends Model
{
    use HasFactory, SoftDeletes, Auditable;

    /**
     * The module name for audit logging.
     */
    protected string $auditModule = 'incidents';

    /**
     * Fields to exclude from audit logs.
     */
    protected array $auditExcluded = ['created_at', 'updated_at', 'deleted_at'];

    protected $fillable = [
        'employee_id',
        'incident_type_id',
        'start_date',
        'end_date',
        'days_count',
        'reason',
        'late_month',
        'document_path',
        'start_time',
        'end_time',
        'hours',
        'status',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'pay_worked_days',
        'migrated_from_authorization_id',
    ];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'approved_at' => 'datetime',
        'pay_worked_days' => 'boolean',
    ];

    /**
     * Get the employee this incident belongs to.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Get the incident type.
     */
    public function incidentType(): BelongsTo
    {
        return $this->belongsTo(IncidentType::class);
    }

    /**
     * Get the user who approved this incident.
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Scope for pending incidents.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope for approved incidents.
     */
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /**
     * Scope for incidents in date range.
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->where(function ($q) use ($startDate, $endDate) {
            $q->whereBetween('start_date', [$startDate, $endDate])
              ->orWhereBetween('end_date', [$startDate, $endDate])
              ->orWhere(function ($q2) use ($startDate, $endDate) {
                  $q2->where('start_date', '<=', $startDate)
                     ->where('end_date', '>=', $endDate);
              });
        });
    }

    /**
     * ¿El tipo de incidencia JUSTIFICA la ausencia/retardo del día para
     * efectos de bonos y reportes? (DECISIONES_NEGOCIO_2026-06-04.md §8:
     * "lo justificado no rompe el bono"; solo lo SIN justificar penaliza.)
     *
     * - vacation / sick_leave / permission: siempre justifican.
     * - absence: solo con goce (FJU "falta justificada"); FIN/SUS son
     *   disciplinarias y no justifican.
     * - late_accumulation (FRT): es una sanción, nunca justifica.
     */
    public static function typeJustifiesAbsence(IncidentType $type): bool
    {
        return match ($type->category) {
            'vacation', 'sick_leave', 'permission' => true,
            'absence' => (bool) $type->is_paid,
            default => false,
        };
    }

    /**
     * Fechas cubiertas por incidencias aprobadas que justifican, por empleado,
     * acotadas al rango dado: [employee_id => ['Y-m-d' => true, ...]].
     *
     * Única fuente de la regla de justificación para reportes (faltas,
     * salidas tempranas) — debe coincidir siempre con lo que nómina respeta.
     */
    public static function justifiedDatesByEmployee(iterable $employeeIds, string $startDate, string $endDate): array
    {
        $incidents = self::query()
            ->where('status', 'approved')
            ->whereIn('employee_id', collect($employeeIds)->all())
            ->where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate)
            ->with('incidentType')
            ->get();

        $map = [];

        foreach ($incidents as $incident) {
            if (! $incident->incidentType || ! self::typeJustifiesAbsence($incident->incidentType)) {
                continue;
            }

            $from = Carbon::parse($incident->start_date)->max(Carbon::parse($startDate));
            $to = Carbon::parse($incident->end_date)->min(Carbon::parse($endDate));

            for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
                $map[$incident->employee_id][$day->toDateString()] = true;
            }
        }

        return $map;
    }

    /**
     * Días de ESTA incidencia que solapan el rango [start, end], contados
     * según el count_mode del tipo (DECISIONES §6): calendario para
     * incapacidades (estándar IMSS), hábiles para vacaciones/permisos.
     *
     * Fuente ÚNICA del prorrateo: la consumen la nómina y los reportes
     * (auditoría #86 — el reporte mensual sumaba days_count crudo).
     *
     * @param  list<string>  $holidayDates  fechas 'Y-m-d' festivas del rango
     */
    public function overlapDays(Carbon $startDate, Carbon $endDate, Employee $employee, array $holidayDates = []): int
    {
        $from = Carbon::parse($this->start_date)->max($startDate);
        $to = Carbon::parse($this->end_date)->min($endDate);

        if ($from->gt($to)) {
            return 0;
        }

        $countMode = $this->incidentType->count_mode ?? IncidentType::COUNT_WORKING_DAYS;

        if ($countMode === IncidentType::COUNT_CALENDAR_DAYS) {
            return (int) $from->diffInDays($to) + 1;
        }

        $days = 0;

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            if (in_array($day->toDateString(), $holidayDates, true)) {
                continue;
            }
            if ($employee->isEffectiveWorkingDay($day->englishDayOfWeek)) {
                $days++;
            }
        }

        // Regla de vacaciones (Dani, 2026-06-24): en una semana con 3+ días de
        // vacaciones, el sábado de esa semana también cuenta. Solo aplica a
        // vacaciones (descuentan saldo) y solo sobre el solape [from, to].
        if ($this->incidentType->deducts_vacation) {
            $days += self::saturdayVacationBonusDays($from, $to, $employee, $holidayDates);
        }

        return $days;
    }

    /**
     * Sábados extra que las vacaciones suman por la regla de Dani (2026-06-24,
     * aclarada 2026-07-01): en cada semana (lun–dom) con 3 o más días de
     * vacaciones, el sábado de esa semana también se cuenta como vacación,
     * INDEPENDIENTEMENTE de qué días sean y aunque el sábado quede FUERA del
     * rango solicitado (p. ej. vacaciones mié–vie suman también el sábado). No
     * se cuenta si el sábado es festivo, ni si el empleado ya lo trabaja (para
     * no duplicarlo con el conteo de días hábiles).
     *
     * @param  list<string>  $holidayDates  fechas 'Y-m-d' festivas del rango
     */
    public static function saturdayVacationBonusDays(Carbon $from, Carbon $to, Employee $employee, array $holidayDates = []): int
    {
        // Si el sábado ya es día laborable del empleado, su jornada ya lo cuenta
        // como hábil: la regla no suma nada (evita doble conteo).
        if ($employee->isEffectiveWorkingDay('Saturday')) {
            return 0;
        }

        $perWeek = []; // lunes de la semana => # de días de vacaciones hábiles

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $weekKey = $day->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
            $isHoliday = in_array($day->toDateString(), $holidayDates, true);

            if (! $isHoliday && $employee->isEffectiveWorkingDay($day->englishDayOfWeek)) {
                $perWeek[$weekKey] = ($perWeek[$weekKey] ?? 0) + 1;
            }
        }

        $extra = 0;
        foreach ($perWeek as $weekKey => $count) {
            if ($count < 3) {
                continue;
            }

            // El sábado de esa semana (lunes + 5), aunque caiga fuera del rango.
            $saturday = Carbon::parse($weekKey)->addDays(5)->toDateString();
            if (in_array($saturday, $holidayDates, true) || Holiday::isHoliday($saturday)) {
                continue;
            }

            $extra++;
        }

        return $extra;
    }

    /**
     * Approve this incident.
     */
    public function approve(User $user): void
    {
        $this->update([
            'status' => 'approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);
    }

    /**
     * Reject this incident.
     */
    public function reject(User $user, string $reason): void
    {
        $this->update([
            'status' => 'rejected',
            'approved_by' => $user->id,
            'approved_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }
}
