<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CashPayout: ledger por periodo+empleado del efectivo a entregar.
 *
 * Congela, al cerrar el efectivo de un periodo, cuánto se le debe al empleado:
 * el monto del periodo más el acumulado no cobrado de periodos previos. El
 * cobro con PIN lo marca como pagado; lo no cobrado queda como saldo
 * pendiente (outstanding) que reaparece en el siguiente cierre.
 */
class CashPayout extends Model
{
    use Auditable, HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    /**
     * Module name for audit logging.
     */
    protected string $auditModule = 'payroll';

    /**
     * Fields to exclude from audit logs.
     */
    protected array $auditExcluded = ['created_at', 'updated_at', 'denomination_breakdown'];

    protected $fillable = [
        'payroll_period_id',
        'employee_id',
        'period_amount',
        'opening_balance',
        'total_due',
        'amount_paid',
        'status',
        'collected_at',
        'pin_verified',
        'collected_by',
        'denomination_breakdown',
    ];

    protected $casts = [
        'period_amount' => 'decimal:2',
        'opening_balance' => 'decimal:2',
        'total_due' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'pin_verified' => 'boolean',
        'collected_at' => 'datetime',
        'denomination_breakdown' => 'array',
    ];

    /**
     * Get the payroll period this payout belongs to.
     */
    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class);
    }

    /**
     * Get the employee this payout is for.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Get the user who processed the collection.
     */
    public function collectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    /**
     * Scope for payouts not yet collected.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Amount still owed to the employee (carries to the next period's opening
     * balance if the period closes without collection).
     */
    public function outstanding(): float
    {
        return (float) $this->total_due - (float) $this->amount_paid;
    }
}
