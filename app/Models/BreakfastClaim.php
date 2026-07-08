<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BreakfastClaim: desayuno cobrado por un empleado en el kiosco.
 *
 * Se crea solo cuando el empleado pasó todas las validaciones (NIP, rostro y
 * ventana antes de su hora de entrada). unit_cost congela el precio vigente al
 * momento del cobro; la nómina semanal del vendedor suma estos snapshots, por
 * lo que un cambio de precio a mitad de semana no altera lo ya cobrado.
 */
class BreakfastClaim extends Model
{
    use Auditable, HasFactory;

    /**
     * Module name for audit logging.
     */
    protected string $auditModule = 'breakfasts';

    /**
     * Fields to exclude from audit logs.
     */
    protected array $auditExcluded = ['created_at', 'updated_at'];

    protected $fillable = [
        'employee_id',
        'claim_date',
        'claimed_at',
        'unit_cost',
        'face_match_distance',
        'evidence_photo_path',
        'registered_by',
    ];

    protected $casts = [
        'claim_date' => 'date:Y-m-d',
        'claimed_at' => 'datetime',
        'unit_cost' => 'decimal:2',
        'face_match_distance' => 'decimal:4',
    ];

    /**
     * Get the employee that claimed this breakfast.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Get the user (kiosk session) that registered the claim.
     */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    /**
     * Scope claims whose date falls inside the given range (inclusive).
     */
    public function scopeDateBetween(Builder $query, string $startDate, string $endDate): Builder
    {
        return $query->whereBetween('claim_date', [$startDate, $endDate]);
    }
}
