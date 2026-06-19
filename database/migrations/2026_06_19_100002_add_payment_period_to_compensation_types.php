<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pago en efectivo: dónde se paga cada concepto (semanal vs mensual).
 *
 * payment_period controla en qué nómina cae el concepto:
 *   - 'monthly' (default): paga en periodos que pagan extras (mensual/quincenal),
 *     exactamente como hoy. Mantener este default preserva el comportamiento
 *     actual de todos los conceptos seedeados.
 *   - 'weekly': paga en periodos que pagan base (semanal/quincenal).
 *
 * String (no enum) para evitar fricción en SQLite; se valida en el controller.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('compensation_types', 'payment_period')) {
            return;
        }

        Schema::table('compensation_types', function (Blueprint $table) {
            $table->string('payment_period')->default('monthly')->after('priority');
        });
    }

    public function down(): void
    {
        Schema::table('compensation_types', function (Blueprint $table) {
            $table->dropColumn('payment_period');
        });
    }
};
