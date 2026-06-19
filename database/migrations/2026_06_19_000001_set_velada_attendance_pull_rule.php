<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Velada se puede jalar desde checadas en autorizaciones.
 *
 * Cuando la velada pasó a pagarse por noche (per_day + fixed, migración
 * 2026_06_15_000001) dejó de aparecer en la tarjeta "Cargar desde checadas":
 * ya no era per_hour y no tenía pull rule. Marcamos VEL con la pull rule
 * 'velada' para que vuelva a jalarse desde checadas como Cena / Comida / Fin de
 * Semana — una entrada por cada noche detectada en las marcas. Al aprobarla se
 * captura sola la Cena acompañante (CompanionConceptService, tipo night_shift).
 *
 * Emparejado por code; no-op seguro si VEL fue renombrado/eliminado.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('compensation_types')
            ->where('code', 'VEL')
            ->update(['attendance_pull_rule' => 'velada']);
    }

    public function down(): void
    {
        DB::table('compensation_types')
            ->where('code', 'VEL')
            ->where('attendance_pull_rule', 'velada')
            ->update(['attendance_pull_rule' => null]);
    }
};
