<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Personal de entregas" por rango de fechas (Dani 2026-07-28).
 *
 * Un renglón = un colaborador salió a entregas del `start_date` al `end_date`.
 * Mientras un rango cubra una fecha, ese día su velada y tiempo extra
 * autorizados se pagan/reflejan completos, sin topar contra la checada.
 */
class DeliveryPeriod extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'start_date',
        'end_date',
        'created_by',
    ];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
    ];

    /** Rangos que cubren la fecha dada (start_date <= fecha <= end_date). */
    public function scopeCoveringDate(Builder $query, $date): Builder
    {
        $d = Carbon::parse($date)->toDateString();

        return $query->whereDate('start_date', '<=', $d)->whereDate('end_date', '>=', $d);
    }

    /** Rangos que se traslapan con [from, to]. */
    public function scopeOverlapping(Builder $query, $from, $to): Builder
    {
        return $query
            ->whereDate('start_date', '<=', Carbon::parse($to)->toDateString())
            ->whereDate('end_date', '>=', Carbon::parse($from)->toDateString());
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
