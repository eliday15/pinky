<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'started_at',
        'completed_at',
        'status',
        'records_fetched',
        'records_processed',
        'records_created',
        'employees_imported',
        'employees_updated',
        'employees_marked_inactive',
        'errors',
        'triggered_by',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'errors' => 'array',
    ];

    /**
     * Get the user who triggered this sync.
     */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    /**
     * Get the latest sync log.
     */
    public static function latest(): ?self
    {
        return static::orderBy('started_at', 'desc')->first();
    }

    /**
     * Scope for completed syncs.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope for failed syncs.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Get duration in seconds.
     */
    public function getDurationAttribute(): ?int
    {
        if (!$this->completed_at) {
            return null;
        }
        return $this->started_at->diffInSeconds($this->completed_at);
    }
}
