<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cierre de la ventana de desayunos ANTES de la entrada (Luis 2026-10-02:
 * "10 min antes de su entrada pueden adquirir su desayuno, si es menos de
 * 10, no pasa"): el kiosco entrega hasta N minutos antes de la hora de
 * entrada del empleado, para que nadie llegue tarde a su puesto por estar
 * comprando. Editable en Configuración > Desayunos.
 */
return new class extends Migration
{
    private const SETTING = [
        'key' => 'breakfast_close_minutes_before_entry',
        'value' => '10',
        'type' => 'integer',
        'label' => 'Cierre antes de la Entrada (minutos)',
        'description' => 'El desayuno se entrega hasta estos minutos ANTES de la hora de entrada del empleado. Con menos margen que esto para su entrada, ya no se entrega. 0 = se entrega hasta la hora exacta de entrada.',
    ];

    public function up(): void
    {
        if (DB::table('system_settings')->where('key', self::SETTING['key'])->exists()) {
            return;
        }

        DB::table('system_settings')->insert(self::SETTING + [
            'group' => 'breakfast',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', self::SETTING['key'])->delete();
    }
};
