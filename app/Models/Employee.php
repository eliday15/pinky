<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Employee model representing a company worker.
 */
class Employee extends Model
{
    use HasFactory, SoftDeletes, Auditable;

    /**
     * Module name for audit logging.
     */
    protected string $auditModule = 'employees';

    /**
     * Fields to exclude from audit logs.
     */
    protected array $auditExcluded = ['created_at', 'updated_at', 'deleted_at'];

    protected $fillable = [
        'employee_number',
        'contpaqi_code',
        'zkteco_user_id',
        'user_id',
        'first_name',
        'last_name',
        'full_name',
        'email',
        'phone',
        'address_street',
        'address_city',
        'address_state',
        'address_zip',
        'photo_path',
        'emergency_phone',
        'credential_type',
        'credential_number',
        'hire_date',
        'termination_date',
        'department_id',
        'position_id',
        'schedule_id',
        'schedule_overrides',
        'supervisor_id',
        'hourly_rate',
        'overtime_rate',
        'holiday_rate',
        'is_minimum_wage',
        'is_trial_period',
        'trial_period_end_date',
        'imss_number',
        'daily_salary',
        'monthly_bonus_type',
        'monthly_bonus_amount',
        'vacation_days_entitled',
        'vacation_days_used',
        'vacation_days_reserved',
        'vacation_premium_percentage',
        'status',
    ];

    protected $casts = [
        'hire_date' => 'date',
        'termination_date' => 'date',
        'trial_period_end_date' => 'date',
        'hourly_rate' => 'decimal:2',
        'overtime_rate' => 'decimal:2',
        'holiday_rate' => 'decimal:2',
        'daily_salary' => 'decimal:2',
        'monthly_bonus_amount' => 'decimal:2',
        'vacation_premium_percentage' => 'decimal:2',
        'is_minimum_wage' => 'boolean',
        'is_trial_period' => 'boolean',
        'schedule_overrides' => 'array',
    ];

    /**
     * Get the user account for this employee.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the department this employee belongs to.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Get the position of this employee.
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /**
     * Get the schedule assigned to this employee.
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    /**
     * Get the supervisor (direct manager) of this employee.
     */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }

    /**
     * Get all subordinates (direct reports) of this employee.
     */
    public function subordinates(): HasMany
    {
        return $this->hasMany(Employee::class, 'supervisor_id');
    }

    /**
     * Get all attendance records for this employee.
     */
    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    /**
     * Get all incidents for this employee.
     */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    /**
     * Get all payroll entries for this employee.
     */
    public function payrollEntries(): HasMany
    {
        return $this->hasMany(PayrollEntry::class);
    }

    /**
     * Get late accumulations for this employee.
     */
    public function lateAccumulations(): HasMany
    {
        return $this->hasMany(LateAccumulation::class);
    }

    /**
     * Get all authorizations (overtime, permissions, etc.) for this employee.
     */
    public function authorizations(): HasMany
    {
        return $this->hasMany(Authorization::class);
    }

    /**
     * Get emergency contacts for this employee.
     */
    public function emergencyContacts(): HasMany
    {
        return $this->hasMany(EmergencyContact::class);
    }

    /**
     * Get compensation types assigned to this employee.
     */
    public function compensationTypes(): BelongsToMany
    {
        return $this->belongsToMany(CompensationType::class, 'employee_compensation_type')
            ->withPivot('custom_percentage', 'custom_fixed_amount', 'is_active')
            ->withTimestamps();
    }

    /**
     * Get the effective schedule values for this employee, applying overrides.
     *
     * Returns the schedule with per-employee overrides merged on top.
     */
    public function getEffectiveSchedule(): ?object
    {
        if (! $this->schedule) {
            return null;
        }

        $base = $this->schedule;
        $overrides = $this->schedule_overrides ?? [];

        return (object) [
            'entry_time' => $overrides['entry_time'] ?? $base->entry_time,
            'exit_time' => $overrides['exit_time'] ?? $base->exit_time,
            'break_minutes' => (int) ($overrides['break_minutes'] ?? $base->break_minutes),
            'daily_work_hours' => (float) ($overrides['daily_work_hours'] ?? $base->daily_work_hours),
            'late_tolerance_minutes' => (int) ($overrides['late_tolerance_minutes'] ?? $base->late_tolerance_minutes),
            'working_days' => $overrides['working_days'] ?? $base->working_days,
            'is_flexible' => $base->is_flexible,
            'name' => $base->name,
        ];
    }

    /**
     * Scope for active employees.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope for minimum wage employees.
     */
    public function scopeMinimumWage($query)
    {
        return $query->where('is_minimum_wage', true);
    }

    /**
     * Scope for above minimum wage employees.
     */
    public function scopeAboveMinimumWage($query)
    {
        return $query->where('is_minimum_wage', false);
    }

    /**
     * Calculate vacation entitlement based on years of service.
     *
     * Args:
     *     vacationTable: Collection of VacationTable entries
     *
     * Returns:
     *     Number of vacation days entitled, or null if no matching entry
     */
    public function calculateVacationEntitlement($vacationTable = null): ?int
    {
        if (! $this->hire_date) {
            return null;
        }

        $years = max(1, (int) $this->hire_date->diffInYears(now()));

        if (! $vacationTable) {
            $vacationTable = VacationTable::orderBy('years_of_service')->get();
        }

        $entry = $vacationTable->where('years_of_service', '<=', $years)->last();

        return $entry?->vacation_days;
    }

    /**
     * Get remaining vacation days (entitled - used - reserved).
     */
    public function getVacationDaysRemainingAttribute(): int
    {
        return max(0, $this->vacation_days_entitled - $this->vacation_days_used - ($this->vacation_days_reserved ?? 0));
    }

    /**
     * Get vacation days available (alias for remaining, accounts for reserved days).
     */
    public function getVacationDaysAvailableAttribute(): int
    {
        return $this->vacation_days_remaining;
    }

    /**
     * Get the effective daily salary.
     *
     * If daily_salary is set explicitly, use that. Otherwise derive from
     * hourly_rate * daily work hours from the schedule.
     */
    public function getDailySalaryComputedAttribute(): float
    {
        if ($this->daily_salary && (float) $this->daily_salary > 0) {
            return (float) $this->daily_salary;
        }

        $schedule = $this->getEffectiveSchedule();
        $dailyHours = $schedule?->daily_work_hours ?? 8;

        return round((float) $this->hourly_rate * $dailyHours, 2);
    }

    /**
     * Calculate the monthly bonus for this employee.
     *
     * Args:
     *     absences: Number of absence days in the period
     *     workingDaysInPeriod: Total working days in the period
     *
     * Returns:
     *     Calculated bonus amount
     */
    public function calculateMonthlyBonus(int $absences = 0, int $workingDaysInPeriod = 26): float
    {
        $type = $this->monthly_bonus_type ?? 'none';
        $amount = (float) ($this->monthly_bonus_amount ?? 0);

        if ($type === 'none' || $amount <= 0) {
            return 0.0;
        }

        if ($type === 'fixed') {
            return $amount;
        }

        // Variable: reduce proportionally by absences
        if ($workingDaysInPeriod <= 0) {
            return $amount;
        }

        $attendedDays = max(0, $workingDaysInPeriod - $absences);
        return round($amount * ($attendedDays / $workingDaysInPeriod), 2);
    }

    /**
     * Check if the employee is currently in trial period.
     */
    public function isInTrialPeriod(): bool
    {
        if (! $this->is_trial_period) {
            return false;
        }

        if ($this->trial_period_end_date) {
            return $this->trial_period_end_date->isFuture() || $this->trial_period_end_date->isToday();
        }

        return true;
    }

    /**
     * Scope for employees with incomplete profiles (missing schedule, supervisor, or compensation types).
     */
    public function scopeIncomplete($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('schedule_id')
                ->orWhereNull('supervisor_id')
                ->orWhereDoesntHave('compensationTypes');
        });
    }

    /**
     * Scope for employees in trial period.
     */
    public function scopeInTrialPeriod($query)
    {
        return $query->where('is_trial_period', true)
            ->where(function ($q) {
                $q->whereNull('trial_period_end_date')
                    ->orWhere('trial_period_end_date', '>=', now()->toDateString());
            });
    }

    /**
     * Get the identifier to use for CONTPAQi exports.
     * Uses contpaqi_code if set, otherwise falls back to employee_number.
     */
    public function getContpaqiIdentifierAttribute(): string
    {
        return $this->contpaqi_code ?? $this->employee_number;
    }
}
