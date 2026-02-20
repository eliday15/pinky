<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AttendanceAnomaly model for tracking detected attendance issues.
 */
class AttendanceAnomaly extends Model
{
    use HasFactory, Auditable;

    protected string $auditModule = 'attendance';
    protected array $auditExcluded = ['created_at', 'updated_at'];

    // Anomaly types
    public const TYPE_MISSING_CHECKOUT = 'missing_checkout';
    public const TYPE_MISSING_CHECKIN = 'missing_checkin';
    public const TYPE_EXCESSIVE_OVERTIME = 'excessive_overtime';
    public const TYPE_UNAUTHORIZED_OVERTIME = 'unauthorized_overtime';
    public const TYPE_UNAUTHORIZED_VELADA = 'unauthorized_velada';
    public const TYPE_EXCESSIVE_BREAK = 'excessive_break';
    public const TYPE_MISSING_LUNCH = 'missing_lunch';
    public const TYPE_LATE_ARRIVAL = 'late_arrival';
    public const TYPE_EARLY_DEPARTURE = 'early_departure';
    public const TYPE_SCHEDULE_DEVIATION = 'schedule_deviation';
    public const TYPE_DUPLICATE_PUNCHES = 'duplicate_punches';
    public const TYPE_VELADA_MISSING_CONFIRMATION = 'velada_missing_confirmation';

    // Severity levels
    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    // Status
    public const STATUS_OPEN = 'open';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_DISMISSED = 'dismissed';
    public const STATUS_LINKED = 'linked_to_authorization';

    protected $fillable = [
        'attendance_record_id',
        'employee_id',
        'work_date',
        'anomaly_type',
        'severity',
        'description',
        'expected_value',
        'actual_value',
        'deviation_minutes',
        'status',
        'resolved_by',
        'resolved_at',
        'resolution_notes',
        'linked_authorization_id',
        'linked_incident_id',
        'auto_detected',
    ];

    protected $casts = [
        'work_date' => 'date',
        'resolved_at' => 'datetime',
        'auto_detected' => 'boolean',
        'deviation_minutes' => 'integer',
    ];

    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function resolvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function linkedAuthorization(): BelongsTo
    {
        return $this->belongsTo(Authorization::class, 'linked_authorization_id');
    }

    public function linkedIncident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'linked_incident_id');
    }

    // Scopes
    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeOfSeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('anomaly_type', $type);
    }

    public function scopeDateRange($query, $start, $end)
    {
        return $query->whereBetween('work_date', [$start, $end]);
    }

    /**
     * Resolve the anomaly.
     */
    public function resolve(User $user, ?string $notes = null): void
    {
        $this->update([
            'status' => self::STATUS_RESOLVED,
            'resolved_by' => $user->id,
            'resolved_at' => now(),
            'resolution_notes' => $notes,
        ]);
    }

    /**
     * Dismiss the anomaly.
     */
    public function dismiss(User $user, ?string $notes = null): void
    {
        $this->update([
            'status' => self::STATUS_DISMISSED,
            'resolved_by' => $user->id,
            'resolved_at' => now(),
            'resolution_notes' => $notes,
        ]);
    }

    /**
     * Link to an authorization (auto-resolve).
     */
    public function linkToAuthorization(Authorization $authorization): void
    {
        $this->update([
            'status' => self::STATUS_LINKED,
            'linked_authorization_id' => $authorization->id,
            'resolved_at' => now(),
            'resolution_notes' => "Auto-resuelto al aprobar autorizacion #{$authorization->id}.",
        ]);
    }

    /**
     * Get human-readable anomaly type name.
     */
    public function getTypeNameAttribute(): string
    {
        return match ($this->anomaly_type) {
            self::TYPE_MISSING_CHECKOUT => 'Salida no registrada',
            self::TYPE_MISSING_CHECKIN => 'Entrada no registrada',
            self::TYPE_EXCESSIVE_OVERTIME => 'Horas extra excesivas',
            self::TYPE_UNAUTHORIZED_OVERTIME => 'Horas extra sin autorizar',
            self::TYPE_UNAUTHORIZED_VELADA => 'Velada sin autorizar',
            self::TYPE_EXCESSIVE_BREAK => 'Comida excesiva',
            self::TYPE_MISSING_LUNCH => 'Sin checada de comida',
            self::TYPE_LATE_ARRIVAL => 'Retardo significativo',
            self::TYPE_EARLY_DEPARTURE => 'Salida anticipada',
            self::TYPE_SCHEDULE_DEVIATION => 'Desviacion de horario',
            self::TYPE_DUPLICATE_PUNCHES => 'Checadas duplicadas',
            self::TYPE_VELADA_MISSING_CONFIRMATION => 'Velada sin confirmacion post-medianoche',
            default => $this->anomaly_type,
        };
    }

    /**
     * Get severity color for UI.
     */
    public function getSeverityColorAttribute(): string
    {
        return match ($this->severity) {
            self::SEVERITY_INFO => 'blue',
            self::SEVERITY_WARNING => 'yellow',
            self::SEVERITY_CRITICAL => 'red',
            default => 'gray',
        };
    }

    /**
     * Get status label for UI.
     */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_OPEN => 'Abierta',
            self::STATUS_RESOLVED => 'Resuelta',
            self::STATUS_DISMISSED => 'Descartada',
            self::STATUS_LINKED => 'Vinculada a Autorizacion',
            default => $this->status,
        };
    }
}
