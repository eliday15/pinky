<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Authorization model for managing overtime, night shifts, and permissions.
 *
 * Supports both pre-authorization (requested before the event) and
 * post-authorization (detected after the event from ZKTeco data).
 */
class Authorization extends Model
{
    use HasFactory, Auditable;

    /**
     * The module name for audit logging.
     */
    protected string $auditModule = 'authorizations';

    /**
     * Fields to exclude from audit logs.
     */
    protected array $auditExcluded = ['created_at', 'updated_at'];

    /**
     * Authorization types.
     */
    public const TYPE_OVERTIME = 'overtime';

    public const TYPE_NIGHT_SHIFT = 'night_shift';

    public const TYPE_EXIT_PERMISSION = 'exit_permission';

    public const TYPE_ENTRY_PERMISSION = 'entry_permission';

    public const TYPE_SCHEDULE_CHANGE = 'schedule_change';

    public const TYPE_HOLIDAY_WORKED = 'holiday_worked';

    public const TYPE_SPECIAL = 'special';

    /**
     * Authorization statuses.
     */
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'employee_id',
        'requested_by',
        'approved_by',
        'type',
        'date',
        'start_time',
        'end_time',
        'hours',
        'reason',
        'evidence_path',
        'status',
        'rejection_reason',
        'approved_at',
        'is_pre_authorization',
        'attendance_record_id',
        'department_head_id',
        'department_head_signed_at',
        'is_bulk_generated',
        'bulk_group_id',
    ];

    protected $casts = [
        'date' => 'date',
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
        'hours' => 'decimal:2',
        'approved_at' => 'datetime',
        'is_pre_authorization' => 'boolean',
        'department_head_signed_at' => 'datetime',
        'is_bulk_generated' => 'boolean',
    ];

    /**
     * Get the employee for this authorization.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Get the user who requested this authorization.
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Get the user who approved/rejected this authorization.
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the department head who signed this authorization.
     */
    public function departmentHead(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'department_head_id');
    }

    /**
     * Get the attendance record linked to this authorization.
     */
    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class);
    }

    /**
     * Scope for pending authorizations.
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope for approved authorizations.
     */
    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    /**
     * Scope for rejected authorizations.
     */
    public function scopeRejected($query)
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    /**
     * Scope for a specific type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope for pre-authorizations.
     */
    public function scopePreAuthorization($query)
    {
        return $query->where('is_pre_authorization', true);
    }

    /**
     * Scope for post-authorizations.
     */
    public function scopePostAuthorization($query)
    {
        return $query->where('is_pre_authorization', false);
    }

    /**
     * Approve the authorization.
     */
    public function approve(User $approver): void
    {
        $data = [
            'status' => self::STATUS_APPROVED,
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ];

        // Auto-sign department head timestamp if department_head_id is set
        if ($this->department_head_id) {
            $data['department_head_signed_at'] = now();
        }

        $this->update($data);
    }

    /**
     * Reject the authorization.
     */
    public function reject(User $rejector, string $reason): void
    {
        $this->update([
            'status' => self::STATUS_REJECTED,
            'approved_by' => $rejector->id,
            'approved_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }

    /**
     * Check if authorization is pending.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if authorization is approved.
     */
    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * Check if authorization is rejected.
     */
    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /**
     * Check if authorization is paid.
     */
    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    /**
     * Mark the authorization as paid.
     */
    public function markAsPaid(): void
    {
        if ($this->status !== self::STATUS_APPROVED) {
            throw new \InvalidArgumentException('Solo se pueden marcar como pagadas las autorizaciones aprobadas.');
        }

        $this->update([
            'status' => self::STATUS_PAID,
        ]);
    }

    /**
     * Get human-readable type name.
     */
    public function getTypeNameAttribute(): string
    {
        return match ($this->type) {
            self::TYPE_OVERTIME => 'Horas Extra',
            self::TYPE_NIGHT_SHIFT => 'Velada',
            self::TYPE_EXIT_PERMISSION => 'Permiso de Salida',
            self::TYPE_ENTRY_PERMISSION => 'Permiso de Entrada',
            self::TYPE_SCHEDULE_CHANGE => 'Cambio de Horario',
            self::TYPE_HOLIDAY_WORKED => 'Día Festivo Trabajado',
            self::TYPE_SPECIAL => 'Especial',
            default => $this->type,
        };
    }

    /**
     * Get human-readable status name.
     */
    public function getStatusNameAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Pendiente',
            self::STATUS_APPROVED => 'Aprobado',
            self::STATUS_REJECTED => 'Rechazado',
            self::STATUS_PAID => 'Pagado',
            default => $this->status,
        };
    }

    /**
     * Get status color for UI.
     */
    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'yellow',
            self::STATUS_APPROVED => 'green',
            self::STATUS_REJECTED => 'red',
            self::STATUS_PAID => 'blue',
            default => 'gray',
        };
    }

    /**
     * Scope for paid authorizations.
     */
    public function scopePaid($query)
    {
        return $query->where('status', self::STATUS_PAID);
    }
}
