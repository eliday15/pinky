<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Horas a cuenta de vacaciones" estaba marcado SIN goce de sueldo (Dani
 * 2026-07-15).
 *
 * El tipo tenía category='permission' + is_paid=0, así que el motor de nómina
 * lo contaba en permission_unpaid_days y restaba el día COMPLETO del sueldo
 * base (regularPay = (días − permission_unpaid_days) × sueldo_diario). El
 * colaborador pagaba doble por un permiso de 3 h: perdía las horas de su bolsa
 * de vacaciones Y un día entero de sueldo.
 *
 * Las horas salen del saldo de vacaciones, que ya es tiempo pagado: el día NO
 * se descuenta. Se corrige el dato existente y el controlador fuerza la regla
 * para que no vuelva a entrar por la UI.
 *
 * Idempotente: sólo toca los tipos que gastan la bolsa y que quedaron en 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('incident_types')
            ->where('uses_vacation_hours', true)
            ->where('is_paid', false)
            ->update(['is_paid' => true]);
    }

    public function down(): void
    {
        // Sin reversa: volver a "sin goce" reintroduce el descuento del día
        // completo. La configuración correcta es con goce.
    }
};
