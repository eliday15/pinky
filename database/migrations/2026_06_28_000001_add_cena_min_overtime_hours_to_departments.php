<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Umbral de tiempo EXTRA (horas fuera del horario) a partir del cual el
     * departamento genera una cena al "Cargar desde checadas".
     *
     * NULL = comportamiento normal (la cena se dispara por jornada larga
     * >= cena_min_worked_hours, velada o fin de semana). Cuando tiene valor, un
     * día con horas extra >= cena_min_overtime_hours también ofrece la cena.
     * Ej.: CORTE usa 2.5 → con 2.5 h extra ya aparece la cena (regla de Dani,
     * 2026-06-28).
     */
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->decimal('cena_min_overtime_hours', 4, 2)->nullable()->after('weekend_unit_hours');
        });

        DB::table('departments')->where('code', 'CORTE')->update(['cena_min_overtime_hours' => 2.5]);
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('cena_min_overtime_hours');
        });
    }
};
