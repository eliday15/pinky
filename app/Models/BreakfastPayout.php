<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * BreakfastPayout: corte de pago al vendedor de desayunos.
 *
 * Cubre un rango de fechas consecutivo al corte anterior y congela los
 * desayunos que pagó (breakfast_claims.breakfast_payout_id).
 */
class BreakfastPayout extends Model
{
    use Auditable;

    /**
     * Module name for audit logging.
     */
    protected string $auditModule = 'breakfasts';

    /**
     * Fields to exclude from audit logs.
     */
    protected array $auditExcluded = ['created_at', 'updated_at'];

    protected $fillable = [
        'start_date',
        'end_date',
        'claims_count',
        'amount',
        'vendor_employee_id',
        'paid_by',
    ];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'claims_count' => 'integer',
        'amount' => 'decimal:2',
    ];

    /**
     * Human readable name of this payout for the audit trail.
     */
    public function auditSubjectLabel(): string
    {
        return 'Pago de desayunos del '.$this->start_date?->format('d/m/Y')
            .' al '.$this->end_date?->format('d/m/Y');
    }

    /**
     * Desayunos pagados en este corte.
     */
    public function claims(): HasMany
    {
        return $this->hasMany(BreakfastClaim::class);
    }

    /**
     * Vendedor al que se le pagó.
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'vendor_employee_id');
    }

    /**
     * Usuario que registró el pago.
     */
    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
