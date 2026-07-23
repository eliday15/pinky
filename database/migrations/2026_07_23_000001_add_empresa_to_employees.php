<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Razón social / canal de pago del empleado, usado por el "Reporte al contador"
 * para separar cada empleado en su hoja (VP / AVL / POR_FUERA). El sistema no
 * distinguía VP de AVL: son patrones distintos y sólo lo sabe RRHH. Por omisión
 * todos quedan en 'VP' y se reasignan uno a uno desde la ficha del empleado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employees', 'empresa')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->string('empresa', 20)->default('VP')->after('contpaqi_code');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('employees', 'empresa')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropColumn('empresa');
            });
        }
    }
};
