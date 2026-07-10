<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bolsa explícita de "horas a cuenta de vacaciones" (Dani 2026-07-09).
     *
     * RRHH convierte N días de vacaciones en una bolsa de N×8 horas
     * (`vacation_hours_credited`). El colaborador gasta esas horas de forma
     * parcial en distintas fechas (permisos de entrada tarde / salida temprano),
     * y cada gasto se acumula en `vacation_hours_used` (ya existente).
     *
     * El descuento del saldo de vacaciones es PROPORCIONAL a las horas gastadas
     * (8 h usadas = 1 día): convertir no consume el día; gastar sí. `credited` es
     * el tope de horas que pueden gastarse como permiso y, a la vez, la señal de
     * inscripción (solo los colaboradores que lo requieran → credited > 0).
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->decimal('vacation_hours_credited', 6, 2)->default(0)->after('vacation_hours_used');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('vacation_hours_credited');
        });
    }
};
