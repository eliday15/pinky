<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seed the configurable worked-hours threshold for the meal (Cena) pull rule.
     *
     * Lives in the 'attendance' group so it surfaces automatically in
     * Settings → Asistencia. Default 12 hours of total worked time
     * (worked_hours + overtime_hours) qualifies an employee for a dinner.
     */
    public function up(): void
    {
        $exists = DB::table('system_settings')->where('key', 'cena_min_worked_hours')->exists();
        if ($exists) {
            return;
        }

        DB::table('system_settings')->insert([
            'key' => 'cena_min_worked_hours',
            'value' => '12',
            'type' => 'integer',
            'group' => 'attendance',
            'label' => 'Horas Minimas para Cena',
            'description' => 'Horas totales trabajadas en un dia (jornada + extra) que dan derecho a cena al jalar desde checadas.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', 'cena_min_worked_hours')->delete();
    }
};
