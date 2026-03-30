<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * IncidentType model for configuring incident categories and their rules.
 */
class IncidentType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'description',
        'category',
        'is_paid',
        'deducts_vacation',
        'requires_approval',
        'requires_document',
        'affects_attendance',
        'has_time_range',
        'color',
        'is_active',
        'priority',
    ];

    protected $casts = [
        'is_paid' => 'boolean',
        'deducts_vacation' => 'boolean',
        'requires_approval' => 'boolean',
        'requires_document' => 'boolean',
        'affects_attendance' => 'boolean',
        'has_time_range' => 'boolean',
        'is_active' => 'boolean',
        'priority' => 'integer',
    ];

    /**
     * Get all incidents of this type.
     */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    /**
     * Get the positions assigned to this incident type.
     */
    public function positions(): BelongsToMany
    {
        return $this->belongsToMany(Position::class, 'position_incident_type');
    }

    /**
     * Get the departments assigned to this incident type.
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'department_incident_type');
    }

    /**
     * Scope for active incident types.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope by category.
     */
    public function scopeCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope for types that affect attendance calculations.
     */
    public function scopeAffectsAttendance($query)
    {
        return $query->where('affects_attendance', true);
    }
}
