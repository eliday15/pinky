<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix incident type rules:
 * - Deactivate CDH (Cambio de Horario) and FRT (Falta por retardos)
 * - Only FIN and SUS should be sin goce de sueldo (is_paid = false)
 * - All others should be con goce (is_paid = true)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Deactivate types that shouldn't appear
        DB::table('incident_types')
            ->whereIn('code', ['CDH', 'FRT'])
            ->update(['is_active' => false]);

        // Only FIN and SUS are sin goce de sueldo
        DB::table('incident_types')
            ->whereIn('code', ['FIN', 'SUS'])
            ->update(['is_paid' => false]);

        // All others are con goce de sueldo
        DB::table('incident_types')
            ->whereNotIn('code', ['FIN', 'SUS'])
            ->update(['is_paid' => true]);
    }

    public function down(): void
    {
        // Reactivate
        DB::table('incident_types')
            ->whereIn('code', ['CDH', 'FRT'])
            ->update(['is_active' => true]);

        // Restore original is_paid values
        DB::table('incident_types')
            ->whereIn('code', ['PSG', 'FJU', 'FRT'])
            ->update(['is_paid' => false]);
    }
};
