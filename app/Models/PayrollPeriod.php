<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollPeriod extends Model
{
    use Auditable, HasFactory;

    /**
     * Module name for audit logging.
     */
    protected string $auditModule = 'payroll';

    /**
     * Fields to exclude from audit logs.
     */
    protected array $auditExcluded = ['created_at', 'updated_at'];

    /**
     * Human readable name of this payroll period for the audit trail.
     */
    public function auditSubjectLabel(): string
    {
        $name = $this->name ?? 'Nomina #' . $this->getKey();
        $scope = $this->department?->name;

        return trim("Nomina {$name}" . ($scope ? " ({$scope})" : ''));
    }

    /**
     * A payroll period spans many employees, so it is not tied to one.
     */
    public function auditEmployeeId(): ?int
    {
        return null;
    }

    protected $fillable = [
        'name',
        'type',
        'department_id',
        'start_date',
        'end_date',
        'extras_start_date',
        'extras_end_date',
        'payment_date',
        'status',
        'requires_recalculation',
        'recalculation_flagged_at',
        'cash_closed_at',
        'cash_delivery_confirmed_at',
        'cash_enabled_denominations',
        'cash_collection_closed_at',
        'cash_collection_closed_by',
        'cash_return_amount',
        'cash_return_received_at',
        'cash_return_received_by',
        'created_by',
        'approved_by',
    ];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'extras_start_date' => 'date:Y-m-d',
        'extras_end_date' => 'date:Y-m-d',
        'payment_date' => 'date:Y-m-d',
        'requires_recalculation' => 'boolean',
        'recalculation_flagged_at' => 'datetime',
        'cash_closed_at' => 'datetime',
        'cash_delivery_confirmed_at' => 'datetime',
        'cash_enabled_denominations' => 'array',
        'cash_collection_closed_at' => 'datetime',
        'cash_return_amount' => 'decimal:2',
        'cash_return_received_at' => 'datetime',
    ];

    /**
     * Departamento al que está acotado el periodo (alcance/scope). NULL = nómina
     * GENERAL (todos menos los deptos con su propia nómina).
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * ¿Es la nómina general (sin departamento acotado)?
     */
    public function isGeneral(): bool
    {
        return $this->department_id === null;
    }

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
     * Get all cash payouts (efectivo a entregar) for this period.
     */
    public function cashPayouts(): HasMany
    {
        return $this->hasMany(CashPayout::class);
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

    /**
     * ¿Este periodo paga el sueldo BASE (semanal/quincenal legacy)?
     *
     * Fuente única de la regla de alcance base/extras: la consume la nómina
     * (PayrollCalculatorService) y el export CONTPAQi — antes cada uno tenía
     * su copia y podían divergir (auditoría #64).
     */
    public function paysBase(): bool
    {
        return in_array($this->type, ['weekly', 'biweekly'], true);
    }

    /**
     * ¿Este periodo paga los EXTRAS (mensual/quincenal legacy): horas extra,
     * velada, festivo, fin de semana, conceptos especiales, vacaciones y bonos?
     *
     * Un periodo UNIFICADO (semanal con rango de extras) también los paga: es
     * el pago único de Elias 2026-08-25 — la semana y el mes en un solo recibo.
     */
    public function paysExtras(): bool
    {
        return in_array($this->type, ['monthly', 'biweekly'], true) || $this->isUnified();
    }

    /**
     * ¿Es un pago UNIFICADO? El sueldo base corre sobre la semana del periodo
     * (start_date/end_date) y los EXTRAS sobre el rango del mes
     * (extras_start_date/extras_end_date), en un solo recibo y un solo pago.
     */
    public function isUnified(): bool
    {
        return $this->extras_start_date !== null && $this->extras_end_date !== null;
    }

    /**
     * ¿Este periodo puede absorber los extras de un mes (unificarse)?
     *
     * Solo una nómina BASE todavía editable y con el efectivo sin cerrar: si ya
     * se aprobó, se pagó o se preparó el efectivo, los montos están en la calle
     * y meterle los extras cambiaría lo que ya se contó.
     */
    public function canAbsorbExtras(): bool
    {
        // Solo la SEMANAL: la quincenal legada ya paga todo junto, unificarle
        // extras la haría pagar el mes dos veces.
        return $this->type === 'weekly'
            && ! $this->isUnified()
            && $this->canEdit()
            && ! $this->isCashClosed();
    }

    /**
     * Alcance de cálculo de esta instancia ('base' | 'extras'), presente solo en
     * las VISTAS que arma calculationView() para el cálculo unificado. Nunca se
     * persiste: es una marca en memoria.
     */
    public ?string $calculationScope = null;

    /**
     * Vista NO persistida del periodo acotada a un alcance, para calcular el
     * pago unificado en dos pasadas con la MISMA lógica de siempre:
     *
     *  - 'base':   se comporta como la nómina semanal (sueldo base de la semana).
     *  - 'extras': se comporta como la nómina mensual sobre el rango de extras.
     *
     * Conserva id/departamento para que el contexto en lote y el alcance por
     * departamento sigan aplicando. Jamás se guarda.
     */
    public function calculationView(string $scope): self
    {
        $view = new self;
        $view->exists = true;
        $view->forceFill([
            'id' => $this->id,
            'name' => $this->name,
            'department_id' => $this->department_id,
            'payment_date' => $this->payment_date,
            'status' => $this->status,
            'type' => $scope === 'extras' ? 'monthly' : $this->type,
            'start_date' => $scope === 'extras' ? $this->extras_start_date : $this->start_date,
            'end_date' => $scope === 'extras' ? $this->extras_end_date : $this->end_date,
            'extras_start_date' => null,
            'extras_end_date' => null,
        ]);
        $view->syncOriginal();
        $view->calculationScope = $scope;

        return $view;
    }

    /**
     * Whether the cash for this period has been closed/prepared (denominations
     * computed and cash_payouts created).
     */
    public function isCashClosed(): bool
    {
        return $this->cash_closed_at !== null;
    }

    /**
     * Whether the cash delivery has been confirmed (paso 1 "preparar entrega").
     * Requerido antes de cobrar (paso 2). Se reinicia al re-cerrar el efectivo,
     * porque los montos/billetes cambian y hay que volver a prepararlo.
     */
    public function isCashDeliveryConfirmed(): bool
    {
        return $this->cash_delivery_confirmed_at !== null;
    }

    /**
     * Whether the cash collection (paso 2 "cobrar") was closed by the collector.
     * Al cerrar se congela el efectivo a regresar (cash_return_amount) y ya no
     * se puede cobrar en este periodo; lo no cobrado se acumula al empleado en
     * el siguiente cierre de efectivo.
     */
    public function isCashCollectionClosed(): bool
    {
        return $this->cash_collection_closed_at !== null;
    }

    /**
     * Whether the returned cash (sobrante del cierre) was received back by the
     * company (super admin). Una vez recibido, el cierre ya no se puede reabrir.
     */
    public function isCashReturnReceived(): bool
    {
        return $this->cash_return_received_at !== null;
    }

    /**
     * Get the user (cobrador) who closed the cash collection.
     */
    public function cashCollectionClosedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cash_collection_closed_by');
    }

    /**
     * Get the user (super admin) who received the returned cash.
     */
    public function cashReturnReceivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cash_return_received_by');
    }
}
