<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Compensation type model for configurable pay concepts.
 *
 * Represents types like overtime, velada, holiday pay, etc.
 * Each type is either a fixed amount or a percentage of the daily salary.
 * Supports different application modes: per_hour, per_day, one_time.
 */
class CompensationType extends Model
{
    use HasFactory, Auditable;

    /**
     * Application mode constants.
     */
    public const APPLICATION_PER_HOUR = 'per_hour';

    public const APPLICATION_PER_DAY = 'per_day';

    public const APPLICATION_ONE_TIME = 'one_time';

    /**
     * Module name for audit logging.
     */
    protected string $auditModule = 'compensation_types';

    /**
     * Fields to exclude from audit logs.
     */
    protected array $auditExcluded = ['created_at', 'updated_at'];

    protected $fillable = [
        'name',
        'code',
        'description',
        'calculation_type',
        'percentage_value',
        'fixed_amount',
        'is_active',
        'application_mode',
        'authorization_type',
        'priority',
    ];

    protected $casts = [
        'percentage_value' => 'decimal:2',
        'fixed_amount' => 'decimal:2',
        'is_active' => 'boolean',
        'priority' => 'integer',
    ];

    /**
     * Calculate the compensation amount for a given base salary.
     *
     * Args:
     *     baseSalary: The employee's daily salary to calculate against
     *
     * Returns:
     *     The calculated compensation amount
     */
    public function calculateAmount(float $baseSalary): float
    {
        if ($this->calculation_type === 'fixed') {
            return (float) ($this->fixed_amount ?? 0);
        }

        // percentage: (percentage_value / 100) * baseSalary
        return round($baseSalary * ((float) ($this->percentage_value ?? 0) / 100), 2);
    }

    /**
     * Calculate compensation based on application mode and calculation type.
     *
     * Args:
     *     hourlyRate: The employee's hourly rate
     *     dailySalary: The employee's daily salary
     *     hours: Number of hours (for per_hour mode)
     *     days: Number of days (for per_day mode)
     *     resolvedPercentage: Override percentage from employee/position/dept pivot
     *     resolvedFixed: Override fixed amount from employee/position/dept pivot
     *
     * Returns:
     *     The calculated compensation amount
     */
    public function calculateCompensation(
        float $hourlyRate,
        float $dailySalary,
        float $hours = 0,
        float $days = 0,
        ?float $resolvedPercentage = null,
        ?float $resolvedFixed = null,
    ): float {
        $percentage = $resolvedPercentage ?? (float) ($this->percentage_value ?? 0);
        $fixedAmount = $resolvedFixed ?? (float) ($this->fixed_amount ?? 0);

        return match ($this->application_mode) {
            self::APPLICATION_PER_HOUR => $this->calculation_type === 'percentage'
                ? round($hours * $hourlyRate * (1 + $percentage / 100), 2)
                : round($hours * $fixedAmount, 2),
            self::APPLICATION_PER_DAY => $this->calculation_type === 'percentage'
                ? round($days * $dailySalary * ($percentage / 100), 2)
                : round($days * $fixedAmount, 2),
            self::APPLICATION_ONE_TIME => $this->calculation_type === 'percentage'
                ? round($dailySalary * ($percentage / 100), 2)
                : round($fixedAmount, 2),
            default => 0,
        };
    }

    /**
     * Get employees that have this compensation type assigned.
     */
    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'employee_compensation_type')
            ->withPivot('custom_percentage', 'custom_fixed_amount', 'is_active')
            ->withTimestamps();
    }

    /**
     * Get positions that have this compensation type as a template.
     */
    public function positions(): BelongsToMany
    {
        return $this->belongsToMany(Position::class, 'position_compensation_type')
            ->withPivot('default_percentage', 'default_fixed_amount')
            ->withTimestamps();
    }

    /**
     * Get departments that have this compensation type assigned.
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'department_compensation_type')
            ->withPivot('default_percentage', 'default_fixed_amount')
            ->withTimestamps();
    }

    /**
     * Scope for active compensation types.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for compensation types mapped to a specific authorization type.
     *
     * Args:
     *     query: The query builder instance
     *     type: The authorization type to filter by (e.g., 'overtime', 'night_shift')
     */
    public function scopeForAuthorizationType($query, string $type)
    {
        return $query->where('authorization_type', $type);
    }
}
