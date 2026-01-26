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
        'created_by',
        'approved_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'payment_date' => 'date',
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
}
