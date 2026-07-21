<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Settings del cierre obligatorio de diciembre (Dani 2026-07-17).
 *
 * `SystemSetting::set()` sólo actualiza filas existentes, así que las llaves
 * deben existir. Se insertan aquí de forma idempotente (el start.sh de producción
 * corre `migrate --force` y se traga los errores, por eso nada puede reventar si
 * la migración se vuelve a correr).
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            [
                'key' => 'december_mandatory_vacation_days',
                'value' => '0',
                'type' => 'integer',
                'group' => 'general',
                'label' => 'Dias obligatorios de vacaciones en diciembre',
                'description' => 'Dias que se apartan a toda la empresa para el cierre de diciembre. No se pueden solicitar en otra fecha.',
            ],
            [
                'key' => 'december_mandatory_vacation_year',
                'value' => '0',
                'type' => 'integer',
                'group' => 'general',
                'label' => 'Anio de la ultima aplicacion del cierre de diciembre',
                'description' => 'Se actualiza solo al aplicar los dias obligatorios a la empresa.',
            ],
        ];

        foreach ($rows as $row) {
            $exists = DB::table('system_settings')->where('key', $row['key'])->exists();

            if (! $exists) {
                DB::table('system_settings')->insert($row + [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('system_settings')->whereIn('key', [
            'december_mandatory_vacation_days',
            'december_mandatory_vacation_year',
        ])->delete();
    }
};
