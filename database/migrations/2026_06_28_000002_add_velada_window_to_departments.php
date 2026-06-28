<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ventana de velada POR DEPARTAMENTO (con minutos).
     *
     * NULL = usa la ventana global (velada_detection_start_hour/end_hour, 22:00–
     * 05:00). Cuando el depto tiene velada_start/velada_end, las horas extra que
     * caen en esa franja cuentan como velada en vez de horas extra normales.
     *
     * Ej. BIES (regla de Dani 2026-06-28): trabajan 06:00–15:30, así que su
     * velada va de 15:30 a 22:30 — toda hora extra después de su salida y dentro
     * de esa franja es velada.
     */
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->time('velada_start')->nullable()->after('cena_min_overtime_hours');
            $table->time('velada_end')->nullable()->after('velada_start');
        });

        DB::table('departments')->where('code', 'BIES')->update([
            'velada_start' => '15:30:00',
            'velada_end' => '22:30:00',
        ]);
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn(['velada_start', 'velada_end']);
        });
    }
};
