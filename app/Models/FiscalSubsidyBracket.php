<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tabla del subsidio para el empleo por periodicidad: si el gravable cae en
 * [lower_limit, upper_limit] el trabajador recibe `subsidy` que se acredita
 * contra el ISR (si lo supera, el excedente suma al pago). Siembra 2026.
 */
class FiscalSubsidyBracket extends Model
{
    protected $fillable = ['period_type', 'lower_limit', 'upper_limit', 'subsidy'];

    protected $casts = [
        'lower_limit' => 'decimal:2',
        'upper_limit' => 'decimal:2',
        'subsidy' => 'decimal:2',
    ];

    public function scopeForPeriod($query, string $type = 'weekly')
    {
        return $query->where('period_type', $type)->orderBy('lower_limit');
    }
}
