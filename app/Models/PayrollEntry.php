<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PayrollEntry model for tracking employee payroll calculations.
 */
class PayrollEntry extends Model
{
    use HasFactory, Auditable;

    /**
     * The module name for audit logging.
     */
    protected string $auditModule = 'payroll';

    /**
     * Fields to exclude from audit logs.
     */
    protected array $auditExcluded = ['created_at', 'updated_at', 'calculation_breakdown'];

    protected $fillable = [
        'payroll_period_id',
        'employee_id',
        'hourly_rate',
        'overtime_multiplier',
        'holiday_multiplier',
        'regular_hours',
        'overtime_hours',
        'holiday_hours',
        'weekend_hours',
        'night_shift_hours',
        'days_worked',
        'days_absent',
        'days_late',
        'punctuality_days',
        'night_shift_days',
        'late_absences_generated',
        'vacation_days_paid',
        'regular_pay',
        'overtime_pay',
        'holiday_pay',
        'weekend_pay',
        'vacation_pay',
        'punctuality_bonus',
        'dinner_allowance',
        'night_shift_bonus',
        'weekly_bonus',
        'monthly_bonus',
        'bonuses',
        'deductions',
        'gross_pay',
        'net_pay',
        'calculation_breakdown',
    ];

    protected $casts = [
        'hourly_rate' => 'decimal:2',
        'overtime_multiplier' => 'decimal:2',
        'holiday_multiplier' => 'decimal:2',
        'regular_hours' => 'decimal:2',
        'overtime_hours' => 'decimal:2',
        'holiday_hours' => 'decimal:2',
        'weekend_hours' => 'decimal:2',
        'night_shift_hours' => 'decimal:2',
        'regular_pay' => 'decimal:2',
        'overtime_pay' => 'decimal:2',
        'holiday_pay' => 'decimal:2',
        'weekend_pay' => 'decimal:2',
        'vacation_pay' => 'decimal:2',
        'punctuality_bonus' => 'decimal:2',
        'dinner_allowance' => 'decimal:2',
        'night_shift_bonus' => 'decimal:2',
        'weekly_bonus' => 'decimal:2',
        'monthly_bonus' => 'decimal:2',
        'bonuses' => 'decimal:2',
        'deductions' => 'decimal:2',
        'gross_pay' => 'decimal:2',
        'net_pay' => 'decimal:2',
        'calculation_breakdown' => 'array',
    ];

    /**
     * Get the payroll period this entry belongs to.
     */
    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class);
    }

    /**
     * Get the employee for this entry.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Calculate gross pay from components.
     */
    public function calculateGrossPay(): float
    {
        return $this->regular_pay
            + $this->overtime_pay
            + $this->holiday_pay
            + $this->weekend_pay
            + $this->vacation_pay
            + $this->bonuses;
    }

    /**
     * Calculate net pay.
     */
    public function calculateNetPay(): float
    {
        return $this->calculateGrossPay() - $this->deductions;
    }

    /**
     * Get total hours worked.
     */
    public function getTotalHoursAttribute(): float
    {
        return $this->regular_hours
            + $this->overtime_hours
            + $this->holiday_hours
            + $this->weekend_hours;
    }
}
