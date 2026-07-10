<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CFDI de nómina timbrado (o por timbrar) de un PayrollEntry. Conserva el
 * historial: un entry puede tener CFDIs cancelados y uno activo.
 */
class PayrollCfdi extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_STAMPED = 'stamped';

    public const STATUS_ERROR = 'error';

    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'payroll_entry_id',
        'status',
        'uuid',
        'pac_id',
        'xml_path',
        'pdf_path',
        'pac_response',
        'attempts',
        'stamped_at',
        'canceled_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'stamped_at' => 'datetime',
        'canceled_at' => 'datetime',
    ];

    public function payrollEntry(): BelongsTo
    {
        return $this->belongsTo(PayrollEntry::class);
    }

    /** Scope: CFDIs activos (timbrados o en proceso), no cancelados. */
    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_STAMPED]);
    }
}
