<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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
        'hire_date',
        'termination_date',
        'department_id',
        'position_id',
        'schedule_id',
        'supervisor_id',
        'hourly_rate',
        'overtime_rate',
        'holiday_rate',
        'vacation_days_entitled',
        'vacation_days_used',
        'status',
    ];

    protected $casts = [
        'hire_date' => 'date',
        'termination_date' => 'date',
        'hourly_rate' => 'decimal:2',
        'overtime_rate' => 'decimal:2',
        'holiday_rate' => 'decimal:2',
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
     * Scope for active employees.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Get remaining vacation days.
     */
    public function getVacationDaysRemainingAttribute(): int
    {
        return $this->vacation_days_entitled - $this->vacation_days_used;
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
