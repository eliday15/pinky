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
    use HasFactory, Auditable;

    /**
     * The module name for audit logging.
     */
    protected string $auditModule = 'attendance';

    /**
     * Fields to exclude from audit logs.
     */
    protected array $auditExcluded = ['created_at', 'updated_at', 'raw_punches'];

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
        'work_date' => 'date',
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
}
