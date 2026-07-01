<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Umbral de horas a partir del cual el trabajo de FIN DE SEMANA paga tiempo
     * extra (Opción A de Dani, 2026-06-29, para el depto SALDOS).
     *
     * NULL = comportamiento normal (el fin de semana se paga como FIN por día y
     * el tiempo extra —si hay autorización— empieza tras la jornada normal, 8 h).
     * Con valor (SALDOS = 7): en un día de fin de semana el tiempo extra empieza
     * tras esas horas, así que se paga el FIN normal Y, además, las horas que
     * excedan de 7 como tiempo extra. Solo aplica a días de fin de semana; entre
     * semana el umbral sigue siendo la jornada del horario.
     */
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->decimal('weekend_overtime_after_hours', 4, 2)->nullable()->after('cena_min_overtime_hours');
        });

        DB::table('departments')->where('code', 'SALDOS')->update(['weekend_overtime_after_hours' => 7]);
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('weekend_overtime_after_hours');
        });
    }
};
