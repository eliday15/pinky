<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Casilla "A cuenta de horas (HxV)" al capturar Vacaciones (Dani 2026-07-29).
     *
     * Cuando se marca en una incidencia de Vacaciones (`deducts_vacation`), al
     * APROBAR la hoja los días NO se consumen como vacación: se convierten a la
     * bolsa de horas (`vacation_hours_credited += días × 8`) para gastarse luego
     * por horas (permisos de entrada tarde / salida temprano que ya debitan la
     * bolsa). Así RRHH ya no hace la conversión manual en la pantalla de bolsa.
     *
     * Una incidencia con esta bandera es un "vale de conversión": no representa
     * días tomados, por eso queda INVISIBLE para nómina/asistencia.
     */
    public function up(): void
    {
        if (Schema::hasColumn('incidents', 'converts_to_vacation_hours')) {
            return;
        }

        Schema::table('incidents', function (Blueprint $table) {
            $table->boolean('converts_to_vacation_hours')->default(false)->after('pay_worked_days');
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn('converts_to_vacation_hours');
        });
    }
};
