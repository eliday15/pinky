<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Acumulado anual por empleado (gravado, exento, ISR retenido, subsidio,
 * días). Lo reconstruye `EmployeeAnnualTotalService::rebuildYear` desde los
 * periodos aprobados; las columnas external_* vienen del import de Contpaq.
 */
class EmployeeAnnualTotal extends Model
{
    protected $fillable = [
        'employee_id',
        'year',
        'taxable_income',
        'exempt_income',
        'isr_withheld',
        'subsidy_paid',
        'days_paid',
        'external_taxable_income',
        'external_isr_withheld',
    ];

    protected $casts = [
        'year' => 'integer',
        'taxable_income' => 'decimal:2',
        'exempt_income' => 'decimal:2',
        'isr_withheld' => 'decimal:2',
        'subsidy_paid' => 'decimal:2',
        'days_paid' => 'decimal:2',
        'external_taxable_income' => 'decimal:2',
        'external_isr_withheld' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Gravado total del año (Pinky + lo importado de Contpaq al corte). */
    public function totalTaxable(): float
    {
        return round((float) $this->taxable_income + (float) $this->external_taxable_income, 2);
    }

    /** ISR retenido total del año (Pinky + Contpaq). */
    public function totalIsrWithheld(): float
    {
        return round((float) $this->isr_withheld + (float) $this->external_isr_withheld, 2);
    }
}
