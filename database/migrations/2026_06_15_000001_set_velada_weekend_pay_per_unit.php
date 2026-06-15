<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Velada y fin de semana se pagan por UNIDAD a monto fijo, no por hora.
 *
 * Regla de negocio (WhatsApp 2026-06-15): la velada se paga el "Monto" asignado
 * al empleado por cada noche trabajada (1 velada = monto, n veladas = n × monto)
 * y el fin de semana por unidades de horas (Almacén PT: 12 h ÷ 6 = 2 × monto).
 *
 * Para que el Monto por concepto del empleado (custom_fixed_amount) se pague tal
 * cual —sin multiplicar por horas ni por el multiplicador 2x— el concepto debe
 * ser application_mode = per_day + calculation_type = fixed. Antes VEL estaba
 * como percentage/per_hour, por lo que el Monto se ignoraba y pagaba
 * horas × tarifa × 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Velada (VEL): monto fijo por noche trabajada y autorizada.
        DB::table('compensation_types')
            ->where('code', 'VEL')
            ->update([
                'application_mode' => 'per_day',
                'calculation_type' => 'fixed',
                'updated_at' => now(),
            ]);

        // Fin de semana: monto fijo por unidad/día. Se identifica por su pull
        // rule de fin de semana para no depender del código exacto del concepto
        // (FIN se creó en producción, no está en los seeders).
        DB::table('compensation_types')
            ->where('attendance_pull_rule', 'weekend')
            ->update([
                'application_mode' => 'per_day',
                'calculation_type' => 'fixed',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Revertir solo VEL a su configuración por hora original. El resto de
        // conceptos de fin de semana se configuraron en producción y no se
        // pueden restaurar con certeza desde aquí.
        DB::table('compensation_types')
            ->where('code', 'VEL')
            ->update([
                'application_mode' => 'per_hour',
                'calculation_type' => 'percentage',
                'updated_at' => now(),
            ]);
    }
};
