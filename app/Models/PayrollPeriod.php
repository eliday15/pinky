<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollPeriod extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'start_date',
        'end_date',
        'payment_date',
        'status',
        'requires_recalculation',
        'recalculation_flagged_at',
        'created_by',
        'approved_by',
    ];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'payment_date' => 'date:Y-m-d',
        'requires_recalculation' => 'boolean',
        'recalculation_flagged_at' => 'datetime',
    ];

    /**
     * Get the user who created this period.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who approved this period.
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get all payroll entries for this period.
     */
    public function entries(): HasMany
    {
        return $this->hasMany(PayrollEntry::class);
    }

    /**
     * Scope for periods by status.
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Get total gross pay for this period.
     */
    public function getTotalGrossPayAttribute(): float
    {
        return $this->entries()->sum('gross_pay');
    }

    /**
     * Get total net pay for this period.
     */
    public function getTotalNetPayAttribute(): float
    {
        return $this->entries()->sum('net_pay');
    }

    /**
     * Check if period can be edited.
     */
    public function canEdit(): bool
    {
        return in_array($this->status, ['draft', 'calculating', 'review']);
    }

    /**
     * ¿Este periodo paga el sueldo BASE (semanal/quincenal legacy)?
     *
     * Fuente única de la regla de alcance base/extras: la consume la nómina
     * (PayrollCalculatorService) y el export CONTPAQi — antes cada uno tenía
     * su copia y podían divergir (auditoría #64).
     */
    public function paysBase(): bool
    {
        return in_array($this->type, ['weekly', 'biweekly'], true);
    }

    /**
     * ¿Este periodo paga los EXTRAS (mensual/quincenal legacy): horas extra,
     * velada, festivo, fin de semana, conceptos especiales, vacaciones y bonos?
     */
    public function paysExtras(): bool
    {
        return in_array($this->type, ['monthly', 'biweekly'], true);
    }
}
