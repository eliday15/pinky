<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AttendanceRecord model for tracking employee attendance.
 */
class AttendanceRecord extends Model
{
    use Auditable, HasFactory;

    /**
     * The module name for audit logging.
     */
    protected string $auditModule = 'attendance';

    /**
     * Fields to exclude from audit logs.
     */
    protected array $auditExcluded = ['created_at', 'updated_at', 'raw_punches'];

    /**
     * Human readable name of this attendance record for the audit trail.
     */
    public function auditSubjectLabel(): string
    {
        $employee = $this->employee?->full_name;
        $date = $this->auditDate($this->work_date);

        return trim('Asistencia'
            .($employee ? " de {$employee}" : '')
            .($date ? " ({$date})" : ''));
    }

    protected $fillable = [
        'employee_id',
        'work_date',
        'check_in',
        'check_out',
        'lunch_out',
        'lunch_in',
        'actual_break_minutes',
        'worked_hours',
        'overtime_hours',
        'permission_hours',
        'total_payroll_hours',
        'late_minutes',
        'early_departure_minutes',
        'status',
        'is_holiday',
        'is_weekend_work',
        'is_night_shift',
        'qualifies_for_punctuality_bonus',
        'qualifies_for_night_shift_bonus',
        'requires_review',
        'notes',
        'raw_punches',
        'authorization_id',
        'velada_hours',
        'overtime_authorized_hours',
        'velada_authorized_hours',
        'has_anomalies',
        'anomaly_count',
        'lunch_deviation_minutes',
        'manually_edited_by',
        'manually_edited_at',
        'manual_edit_reason',
    ];

    protected $casts = [
        'work_date' => 'date:Y-m-d',
        'worked_hours' => 'decimal:2',
        'overtime_hours' => 'decimal:2',
        'permission_hours' => 'decimal:2',
        'total_payroll_hours' => 'decimal:2',
        'actual_break_minutes' => 'integer',
        'is_holiday' => 'boolean',
        'is_weekend_work' => 'boolean',
        'is_night_shift' => 'boolean',
        'qualifies_for_punctuality_bonus' => 'boolean',
        'qualifies_for_night_shift_bonus' => 'boolean',
        'requires_review' => 'boolean',
        'raw_punches' => 'array',
        'velada_hours' => 'decimal:2',
        'overtime_authorized_hours' => 'decimal:2',
        'velada_authorized_hours' => 'decimal:2',
        'has_anomalies' => 'boolean',
        'anomaly_count' => 'integer',
        'lunch_deviation_minutes' => 'integer',
        'manually_edited_at' => 'datetime',
    ];

    /**
     * Get the employee this record belongs to.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Get the authorization linked to this record.
     */
    public function authorization(): BelongsTo
    {
        return $this->belongsTo(Authorization::class);
    }

    /**
     * Get the user who manually edited this record.
     */
    public function manuallyEditedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manually_edited_by');
    }

    /**
     * Get the anomalies for this record.
     */
    public function anomalies(): HasMany
    {
        return $this->hasMany(AttendanceAnomaly::class);
    }

    /**
     * Scope for records that need review.
     */
    public function scopeRequiresReview($query)
    {
        return $query->where('requires_review', true);
    }

    /**
     * Scope for records by status.
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope for records in date range.
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('work_date', [$startDate, $endDate]);
    }

    /**
     * Check if employee was late.
     */
    public function isLate(): bool
    {
        return $this->late_minutes > 0;
    }

    /**
     * Check if employee worked overtime.
     */
    public function hasOvertime(): bool
    {
        return $this->overtime_hours > 0;
    }

    /**
     * Horas CORRIDAS de entrada a salida (sin descontar comida), cruzando
     * medianoche si aplica. Null cuando la checada está incompleta. Es la
     * base de la regla de fin de semana (Dani 2026-07-08: "las 7 horas se
     * cuentan corridas, incluyendo el tiempo de comida").
     *
     * Se mide AL MINUTO, igual que se muestran las checadas (Dani 2026-10-21,
     * caso Fernanda: 08:21:13 → 20:21:01 se ve como 12 h pero con segundos
     * daba 11.9967 y el FIN no llegaba a las 2 unidades).
     */
    public function grossSpanHours(): ?float
    {
        if (! $this->check_in || ! $this->check_out) {
            return null;
        }

        $date = \Carbon\Carbon::parse($this->work_date)->toDateString();
        $in = \Carbon\Carbon::parse($date.' '.\Carbon\Carbon::parse($this->check_in)->format('H:i'));
        $out = \Carbon\Carbon::parse($date.' '.\Carbon\Carbon::parse($this->check_out)->format('H:i'));
        if ($out->lt($in) || $this->outPunchCrossesMidnight()) {
            $out->addDay();
        }

        return round(abs($in->diffInMinutes($out)) / 60, 4);
    }

    /** Hueco mínimo entre huellas para leerlo como "se fue y regresó a velar". */
    public const VELADA_RETURN_GAP_MIN_HOURS = 2.0;

    /**
     * Horas que cuentan para el FIN: corridas de entrada a salida, menos la
     * velada (se paga aparte) y menos el tiempo que estuvo FUERA cuando salió
     * y regresó a velar (Dani 2026-11-04, caso Policarpo dom 1/11: 7:48–15:00,
     * regresó 21:30 a la velada hasta 5:02; las 6.5 h fuera contaban como
     * fin y le daban 2 unidades en vez de 1). La comida no es un hueco así
     * (menos de VELADA_RETURN_GAP_MIN_HOURS) y sigue contando corrida.
     *
     * Returns:
     *     Horas base del fin de semana, o null si la checada está incompleta.
     */
    public function weekendBaseHours(): ?float
    {
        $gross = $this->grossSpanHours();
        if ($gross === null) {
            return null;
        }

        return round(max(0.0, $gross - (float) ($this->velada_hours ?? 0) - $this->veladaReturnGapHours()), 4);
    }

    /**
     * Tiempo FUERA antes de regresar a velar: el hueco más largo entre dos
     * huellas consecutivas (al minuto), si el día tiene velada y el hueco dura
     * al menos VELADA_RETURN_GAP_MIN_HOURS. 0 en cualquier otro caso.
     */
    public function veladaReturnGapHours(): float
    {
        if ((float) ($this->velada_hours ?? 0) <= 0) {
            return 0.0;
        }

        $times = collect($this->raw_punches ?? [])
            ->filter(fn ($p) => ! empty($p['date']) && ! empty($p['time']))
            ->map(fn ($p) => \Carbon\Carbon::parse($p['date'].' '.substr($p['time'], 0, 5)))
            ->sort()
            ->values();

        // Solo cuentan los pares salida → regreso INTERMEDIOS (huella 2→3,
        // 4→5…): de la entrada a la primera salida y de la última huella a la
        // salida final sí estuvo trabajando.
        $largest = 0.0;
        for ($i = 1; $i + 1 < $times->count() - 1; $i += 2) {
            $largest = max($largest, $times[$i]->diffInMinutes($times[$i + 1]) / 60);
        }

        return $largest >= self::VELADA_RETURN_GAP_MIN_HOURS ? $largest : 0.0;
    }

    /**
     * ¿La huella de SALIDA es del día siguiente al del registro? Las huellas
     * crudas guardan su fecha real; comparar solo las HORAS falla en el caso
     * límite de una velada que termina pasada la hora de entrada del día
     * anterior (Luis 2026-08-27, caso Miguel: entró 05:00 y su velada terminó
     * 05:08 del día siguiente — 05:08 > 05:00, así que la comparación de horas
     * no detectaba el cruce y el día quedaba de 7 minutos, marcado ausente).
     */
    public function outPunchCrossesMidnight(): bool
    {
        $workDate = \Carbon\Carbon::parse($this->work_date)->toDateString();
        foreach (($this->raw_punches ?? []) as $punch) {
            if (($punch['type'] ?? null) === 'out' && ! empty($punch['date'])) {
                return $punch['date'] > $workDate;
            }
        }

        return false;
    }
}
