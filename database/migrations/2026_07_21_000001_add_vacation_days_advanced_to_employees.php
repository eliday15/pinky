<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Días de vacaciones ADELANTADOS al colaborador (Dani 2026-07-17).
 *
 * En el cierre de diciembre la empresa para, y a los de nuevo ingreso —que aún
 * no generan derecho por antigüedad— se les ADELANTAN los días obligatorios
 * para que no se queden sin sueldo. Eso es una deuda: cuando después generan su
 * derecho, esos días se descuentan de su saldo hasta cubrir lo prestado.
 *
 * `vacation_days_advanced` es esa deuda pendiente. Bloquea saldo disponible
 * mientras exista y se salda (pasa a `vacation_days_used`) conforme el derecho
 * alcanza — ver Employee::settleVacationAdvance().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->unsignedInteger('vacation_days_advanced')->default(0)->after('vacation_days_reserved');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('vacation_days_advanced');
        });
    }
};
