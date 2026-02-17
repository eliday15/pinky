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
 */
class CompensationType extends Model
{
    use HasFactory, Auditable;

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
    ];

    protected $casts = [
        'percentage_value' => 'decimal:2',
        'fixed_amount' => 'decimal:2',
        'is_active' => 'boolean',
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
}
