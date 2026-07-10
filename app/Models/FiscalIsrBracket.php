<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un renglón de la tarifa de ISR (Art. 96) por periodicidad: ISR = cuota_fija +
 * percent_over_excess × (gravable − lower_limit) del bracket que contiene al
 * gravable. Editable desde configuración; se siembra con la tarifa 2026.
 */
class FiscalIsrBracket extends Model
{
    protected $fillable = ['period_type', 'lower_limit', 'fixed_fee', 'percent_over_excess'];

    protected $casts = [
        'lower_limit' => 'decimal:2',
        'fixed_fee' => 'decimal:2',
        'percent_over_excess' => 'decimal:4',
    ];

    public function scopeForPeriod($query, string $type = 'weekly')
    {
        return $query->where('period_type', $type)->orderBy('lower_limit');
    }
}
