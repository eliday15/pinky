<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Position extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'description',
        'position_type',
        'base_hourly_rate',
        'is_active',
    ];

    protected $casts = [
        'base_hourly_rate' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * Get all employees with this position.
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * Scope for active positions.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
