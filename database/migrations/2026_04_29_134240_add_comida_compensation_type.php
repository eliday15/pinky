<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Insert the "Comida" compensation type so it can be assigned to authorizations.
     *
     * Mirrors the existing "Cena" entry — per_day, fixed amount, special auth_type.
     * The amount is set to 0 by default; RRHH adjusts it from settings.
     */
    public function up(): void
    {
        $exists = DB::table('compensation_types')->where('code', 'COM')->exists();
        if ($exists) {
            return;
        }

        DB::table('compensation_types')->insert([
            'name' => 'Comida',
            'code' => 'COM',
            'description' => 'Bono de comida cuando se trabaja en día festivo o jornada extendida.',
            'calculation_type' => 'fixed',
            'fixed_amount' => 0.00,
            'percentage_value' => null,
            'is_active' => true,
            'application_mode' => 'per_day',
            'authorization_type' => 'special',
            'priority' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('compensation_types')->where('code', 'COM')->delete();
    }
};
