<?php

use App\Models\CompensationType;
use Illuminate\Database\Migrations\Migration;

/**
 * Las horas extra pasan a pagarse por MONTO FIJO por hora.
 *
 * Al eliminar el sueldo por hora como insumo, las horas extra ya no pueden
 * pagarse como porcentaje de la hora. Se pagan por el monto fijo configurado en
 * el concepto (decisión de negocio): HE/HED/HET pasan a calculation_type =
 * 'fixed'. El monto por hora (fixed_amount) lo captura RRHH en el CRUD de
 * Conceptos; sin monto el concepto paga $0 (y se registra un warning, conducta
 * existente en payAuthorizationConcepts). No se borra percentage_value (queda
 * de referencia, pero deja de aplicarse).
 */
return new class extends Migration
{
    public function up(): void
    {
        CompensationType::where('authorization_type', 'overtime')
            ->update(['calculation_type' => 'fixed']);
    }

    public function down(): void
    {
        CompensationType::where('authorization_type', 'overtime')
            ->update(['calculation_type' => 'percentage']);
    }
};
