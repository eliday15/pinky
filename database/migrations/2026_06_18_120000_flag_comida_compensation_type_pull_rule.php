<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Opt the existing "Comida" concept into the comida pull rule so the weekend
     * companion finally resolves.
     *
     * Regla de Luis (2026-06-10): al aprobar un "Fin de Semana" se captura solo
     * la "Comida" acompañante. CompanionConceptService busca el concepto activo
     * cuyo attendance_pull_rule = 'comida', pero hasta ahora NINGÚN concepto lo
     * tenía (las migraciones solo marcaron "Cena" → meal y "Fin De Semana" →
     * weekend), así que la compañera nunca se generaba en silencio. Esto lo
     * arregla y, de paso, permite a RRHH jalar "Comida" desde checadas igual que
     * "Cena". Emparejado por code; no-op seguro si fue renombrado/eliminado.
     */
    public function up(): void
    {
        DB::table('compensation_types')
            ->where('code', 'COM')
            ->update(['attendance_pull_rule' => 'comida']);
    }

    public function down(): void
    {
        DB::table('compensation_types')
            ->where('code', 'COM')
            ->where('attendance_pull_rule', 'comida')
            ->update(['attendance_pull_rule' => null]);
    }
};
