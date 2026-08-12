<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Importe del finiquito (Dani 2026-08-12): el sistema NO calcula
     * finiquitos — el monto se captura a mano en la ficha del empleado junto a
     * su fecha de baja, y el Resumen semanal lo imprime en la sección
     * FINIQUITO (antes la columna salía en blanco para llenarse a pluma).
     */
    public function up(): void
    {
        if (Schema::hasColumn('employees', 'finiquito_amount')) {
            return;
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->decimal('finiquito_amount', 10, 2)->nullable()->after('termination_date');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('finiquito_amount');
        });
    }
};
