<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Position model serving as a template for employee configuration.
 *
 * Positions define default values for department, schedule, rates,
 * and compensation types. When an employee selects a position,
 * these defaults auto-fill into the employee form.
 */
class Position extends Model
{
    use HasFactory, Auditable;

    /**
     * Module name for audit logging.
     */
    protected string $auditModule = 'positions';

    /**
     * Fields to exclude from audit logs.
     */
    protected array $auditExcluded = ['created_at', 'updated_at'];

    protected $fillable = [
        'name',
        'code',
        'description',
        'position_type',
        'base_hourly_rate',
        'default_overtime_rate',
        'default_holiday_rate',
        'is_active',
        'department_id',
        'supervisor_position_id',
        'default_schedule_id',
    ];

    protected $casts = [
        'base_hourly_rate' => 'decimal:2',
        'default_overtime_rate' => 'decimal:2',
        'default_holiday_rate' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * Get the department this position belongs to.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Get the supervisor position in the hierarchy.
     */
    public function supervisorPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'supervisor_position_id');
    }

    /**
     * Get positions that report to this position.
     */
    public function subordinatePositions(): HasMany
    {
        return $this->hasMany(Position::class, 'supervisor_position_id');
    }

    /**
     * Get the default schedule for this position.
     */
    public function defaultSchedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class, 'default_schedule_id');
    }

    /**
     * Get all employees with this position.
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * Get compensation types assigned as template defaults.
     */
    public function compensationTypes(): BelongsToMany
    {
        return $this->belongsToMany(CompensationType::class, 'position_compensation_type')
            ->withPivot('default_percentage', 'default_fixed_amount')
            ->withTimestamps();
    }

    /**
     * Scope for active positions.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
