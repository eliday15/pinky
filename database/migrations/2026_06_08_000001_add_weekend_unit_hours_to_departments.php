<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Horas que equivalen a "un fin de semana" para el conteo y pago de fin de
     * semana de un departamento.
     *
     * NULL = comportamiento normal (un día de fin de semana trabajado = una
     * unidad). Cuando tiene valor, el # de fines de semana se calcula a partir
     * de las horas realmente trabajadas en sábado/domingo: unidades =
     * horas_trabajadas ÷ weekend_unit_hours. Ej.: Almacén PT usa 6 → trabajar
     * 12 h un fin de semana cuenta (y se paga) como 2 fines de semana.
     */
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->unsignedSmallInteger('weekend_unit_hours')->nullable()->after('default_break_minutes');
        });

        // Backfill para instalaciones existentes (en una BD recién migrada sin
        // departamentos todavía esto no afecta filas; el seeder lo cubre).
        DB::table('departments')->where('code', 'ALMACENPT')->update(['weekend_unit_hours' => 6]);
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('weekend_unit_hours');
        });
    }
};
