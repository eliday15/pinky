<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LateAccumulation extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'year',
        'week',
        'late_count',
        'absence_generated',
        'generated_incident_id',
    ];

    protected $casts = [
        'absence_generated' => 'boolean',
    ];

    /**
     * Get the employee this accumulation belongs to.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Get the generated incident if any.
     */
    public function generatedIncident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'generated_incident_id');
    }

    /**
     * Check if absence should be generated.
     * FASE 6.1: Uses configurable threshold from SystemSetting.
     */
    public function shouldGenerateAbsence(): bool
    {
        $threshold = (int) SystemSetting::get('late_to_absence_count', 6);

        return $this->late_count >= $threshold && !$this->absence_generated;
    }

    /**
     * Increment the late count.
     */
    public function incrementLate(): void
    {
        $this->increment('late_count');
    }
}
