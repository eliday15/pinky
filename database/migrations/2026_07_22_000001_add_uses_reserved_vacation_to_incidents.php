<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Jalar" días de los obligatorios de diciembre en emergencias (Dani
 * 2026-07-22).
 *
 * Normalmente los días apartados para el cierre de diciembre no se pueden
 * solicitar. Sólo el Administrador puede, en casos especiales, aprobar una
 * vacación que tome de esa reserva. `reserved_days_taken` guarda CUÁNTOS días
 * apartados jaló esta incidencia — para dejar rastro y poder devolverlos exactos
 * si la incidencia se elimina.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->unsignedInteger('reserved_days_taken')->default(0)->after('days_count');
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn('reserved_days_taken');
        });
    }
};
