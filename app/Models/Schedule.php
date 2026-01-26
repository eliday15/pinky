<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Schedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'entry_time',
        'exit_time',
        'break_start',
        'break_end',
        'break_minutes',
        'late_tolerance_minutes',
        'daily_work_hours',
        'is_flexible',
        'working_days',
        'is_active',
    ];

    protected $casts = [
        'is_flexible' => 'boolean',
        'working_days' => 'array',
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
}
