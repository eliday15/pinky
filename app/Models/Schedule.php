<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Schedule model for employee work schedules.
 */
class Schedule extends Model
{
    use HasFactory, Auditable;

    /**
     * Module name for audit logging.
     */
    protected string $auditModule = 'schedules';

    /**
     * Fields to exclude from audit logs.
     */
    protected array $auditExcluded = ['created_at', 'updated_at'];

    protected $fillable = [
        'name',
        'code',
        'description',
        'entry_time',
        'exit_time',
        'break_start',
        'break_end',
        'break_minutes',
        'late_tolerance_minutes',
        'daily_work_hours',
        'is_flexible',
        'working_days',
        'day_schedules',
        'is_active',
    ];

    protected $casts = [
        'is_flexible' => 'boolean',
        'working_days' => 'array',
        'day_schedules' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Get all employees with this schedule.
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * Scope for active schedules.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Check if a given day is a working day.
     */
    public function isWorkingDay(string $dayName): bool
    {
        return in_array(strtolower($dayName), array_map('strtolower', $this->working_days ?? []));
    }

    /**
     * Get schedule times for a specific day, with per-day overrides.
     *
     * Returns the day-specific times if defined in day_schedules,
     * otherwise falls back to the schedule's default times.
     */
    public function getScheduleForDay(string $dayName): object
    {
        $day = strtolower($dayName);
        $override = $this->day_schedules[$day] ?? [];

        return (object) [
            'entry_time' => $override['entry_time'] ?? $this->entry_time,
            'exit_time' => $override['exit_time'] ?? $this->exit_time,
            'break_start' => $override['break_start'] ?? $this->break_start,
            'break_end' => $override['break_end'] ?? $this->break_end,
            'break_minutes' => (int) ($override['break_minutes'] ?? $this->break_minutes),
            'daily_work_hours' => (float) ($override['daily_work_hours'] ?? $this->daily_work_hours),
        ];
    }

    /**
     * Check if this schedule has per-day overrides.
     */
    public function hasPerDaySchedules(): bool
    {
        return !empty($this->day_schedules);
    }
}
