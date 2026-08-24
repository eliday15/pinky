<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permiso DENTRO de jornada (Dani 2026-08-24): el colaborador sale un rato y
 * regresa a terminar su turno (p. ej. de 13:00 a 15:00 y sale a las 18:00).
 * Se captura con fecha + hora de salida + hora de regreso; al aprobarse, esa
 * ventana se descuenta de las horas trabajadas (sin TE fantasma), del retardo
 * y de la salida temprana en la parte que cubre, y queda registrada en
 * permission_hours. Con goce (is_paid): el día sigue 'present' y paga normal.
 * PSA (solo salida) y PEN (solo entrada) siguen igual.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('incident_types')->where('code', 'PDJ')->exists()) {
            return;
        }

        $now = Carbon::now();

        DB::table('incident_types')->insert([
            'name' => 'Permiso dentro de jornada',
            'code' => 'PDJ',
            'category' => 'permission',
            'is_paid' => true,
            'deducts_vacation' => false,
            'requires_approval' => true,
            'requires_document' => false,
            'affects_attendance' => true,
            'has_time_range' => true,
            'color' => '#0EA5E9',
            'is_active' => true,
            'priority' => 15,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('incident_types')->where('code', 'PDJ')->delete();
    }
};
