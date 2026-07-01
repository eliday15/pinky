<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Activa la regla "2.5 h de tiempo extra = 1 cena" en TELAS y CORTE
     * (Dani 2026-07-01: "solo sería para los departamentos de Telas y Corte").
     *
     * Telas nunca había tenido el umbral configurado; Corte ya lo tenía desde
     * 2026_06_28_000001 y aquí se re-afirma en 2.5 por si se hubiera quedado sin
     * valor. Idempotente: fijar el mismo 2.5 no cambia nada.
     */
    public function up(): void
    {
        DB::table('departments')
            ->whereIn('code', ['TELAS', 'CORTE'])
            ->update(['cena_min_overtime_hours' => 2.5]);
    }

    public function down(): void
    {
        // No se revierte: Telas conserva el umbral; Corte ya lo tenía antes.
    }
};
