<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tolerancia para completar las horas de un fin de semana (Dani 2026-11-11:
 * "si al colaborador le falta incluso un minuto para completar las 12 horas,
 * pierde el derecho a su segundo fin de semana del día. La idea es manejar una
 * tolerancia de 10 minutos"). Aplica al doble de 12 h y a cada bloque de
 * Almacén PT. Editable en Configuración > Nómina.
 */
return new class extends Migration
{
    private const SETTING = [
        'key' => 'weekend_hours_tolerance_minutes',
        'value' => '10',
        'type' => 'integer',
        'label' => 'Tolerancia Fin de Semana (minutos)',
        'description' => 'Minutos que pueden faltar para completar las horas de un fin de semana y aun así contarlo: las 12 h del fin doble, o cada bloque de horas en Almacén PT. 0 = sin tolerancia.',
    ];

    public function up(): void
    {
        if (DB::table('system_settings')->where('key', self::SETTING['key'])->exists()) {
            return;
        }

        DB::table('system_settings')->insert(self::SETTING + [
            'group' => 'payroll',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', self::SETTING['key'])->delete();
    }
};
