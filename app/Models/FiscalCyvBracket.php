<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Rango de la tabla escalonada de CyV patronal (reforma de pensiones):
 * % patronal según SBC diario en UMA. Sembrada con la tabla 2026.
 */
class FiscalCyvBracket extends Model
{
    protected $fillable = [
        'upper_uma',
        'employer_pct',
    ];

    protected $casts = [
        'upper_uma' => 'decimal:4',
        'employer_pct' => 'decimal:4',
    ];
}
