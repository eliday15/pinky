<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marca semanal de "personal de entregas" (Dani 2026-07-28).
 *
 * Un renglón = un colaborador salió a entregas en la semana `week_start` (lunes).
 * Mientras exista, su velada y tiempo extra autorizados se pagan/reflejan
 * completos esa semana, sin topar contra la checada.
 */
class DeliveryWeek extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'week_start',
        'created_by',
    ];

    protected $casts = [
        'week_start' => 'date:Y-m-d',
    ];

    /** Lunes de la semana que contiene la fecha dada (misma base que el reporte). */
    public static function weekStartFor($date): string
    {
        return Carbon::parse($date)->startOfWeek()->toDateString();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
