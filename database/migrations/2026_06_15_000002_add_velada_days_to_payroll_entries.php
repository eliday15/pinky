<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conteo de veladas pagadas en el periodo (1 por noche trabajada y autorizada).
 *
 * La velada ahora se paga por noche a monto fijo, así que la nómina guarda
 * cuántas veladas se pagaron (espejo de night_shift_days) para mostrarlas en el
 * detalle de nómina y mantener consistencia con el reporte (velada_count).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_entries', function (Blueprint $table) {
            $table->unsignedSmallInteger('velada_days')->default(0)->after('velada_pay');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_entries', function (Blueprint $table) {
            $table->dropColumn('velada_days');
        });
    }
};
