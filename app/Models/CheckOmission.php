<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Autorización de omisión de checada (Dani 2026-07-09).
 *
 * Cuando un colaborador no registra su entrada o su salida, se genera una falta
 * automática. El jefe/supervisor del departamento AUTORIZA la omisión con un
 * motivo y el administrador la APRUEBA (flujo de 2 pasos). El motivo decide el
 * efecto sobre la asistencia del día una vez aprobada.
 */
class CheckOmission extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    /** The module name for audit logging. */
    protected string $auditModule = 'check_omissions';

    /**
     * Motivos de omisión (catálogo cerrado).
     */
    // "Entrega de mercancía" → al aprobarse NO se aplica la falta; el día se paga
    // completo (día trabajado normal).
    public const REASON_DELIVERY = 'entrega_mercancia';

    // "Trabajo foráneo" → mismo efecto que la entrega de mercancía: el día se
    // paga completo (Dani 2026-07-15).
    public const REASON_FOREIGN_WORK = 'trabajo_foraneo';

    // "Otro (especificar)" → al aprobarse el día se convierte en un RETARDO, que
    // sí cuenta para el acumulado mensual de retardos → falta.
    public const REASON_OTHER = 'otro';

    /**
     * Estados del flujo de 2 pasos.
     */
    public const STATUS_AUTHORIZED = 'authorized'; // jefe autorizó, falta aprobar

    public const STATUS_APPROVED = 'approved';     // admin aprobó (efecto aplicado)

    public const STATUS_REJECTED = 'rejected';     // rechazado

    protected $fillable = [
        'employee_id',
        'attendance_record_id',
        'work_date',
        'reason',
        'comments',
        'status',
        'authorized_by',
        'authorized_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'created_by',
    ];

    protected $casts = [
        'work_date' => 'date:Y-m-d',
        'authorized_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    /**
     * Catálogo de motivos disponibles (código → etiqueta en español).
     *
     * @return array<string, string>
     */
    public static function reasonOptions(): array
    {
        return [
            self::REASON_DELIVERY => 'Entrega de mercancía',
            self::REASON_FOREIGN_WORK => 'Trabajo foráneo',
            self::REASON_OTHER => 'Otro (especificar)',
        ];
    }

    /**
     * Motivos que, al aprobarse, pagan el día COMPLETO (no aplican la falta ni
     * la convierten en retardo).
     *
     * @return array<int, string>
     */
    public static function fullDayReasons(): array
    {
        return [self::REASON_DELIVERY, self::REASON_FOREIGN_WORK];
    }

    /** ¿El motivo paga el día completo (entrega de mercancía / trabajo foráneo)? */
    public function paysFullDay(): bool
    {
        return in_array($this->reason, self::fullDayReasons(), true);
    }

    /** Etiqueta legible del motivo. */
    public function reasonLabel(): string
    {
        return self::reasonOptions()[$this->reason] ?? $this->reason;
    }

    /** ¿El motivo "Entrega de mercancía" (paga completo, sin falta)? */
    public function isDelivery(): bool
    {
        return $this->reason === self::REASON_DELIVERY;
    }

    /** ¿El motivo "Otro" (se convierte en retardo)? */
    public function isOther(): bool
    {
        return $this->reason === self::REASON_OTHER;
    }

    // ---- Relaciones -------------------------------------------------------

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class);
    }

    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ---- Scopes -----------------------------------------------------------

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_AUTHORIZED);
    }

    // ---- Transiciones de estado ------------------------------------------

    /** Paso 2: el administrador aprueba. El efecto se aplica en el controlador. */
    public function approve(User $approver): void
    {
        $this->update([
            'status' => self::STATUS_APPROVED,
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ]);
    }

    /** Rechazo por el administrador. */
    public function reject(User $rejecter, ?string $reason = null): void
    {
        $this->update([
            'status' => self::STATUS_REJECTED,
            'rejected_by' => $rejecter->id,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }
}
