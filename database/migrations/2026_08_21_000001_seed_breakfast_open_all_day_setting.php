<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Modo pruebas del kiosco de desayunos (Luis 2026-08-18: "me puedes abrir los
 * horarios para hacer pruebas"): un switch en Configuración > Desayunos que,
 * mientras está activo, entrega desayunos a cualquier hora y cualquier día —
 * ignora la ventana antes de la entrada y el horario del empleado. Las demás
 * reglas (activo, foto, NIP, 1 por día) siguen aplicando. Auto-servible:
 * Luis lo prende para probar y lo apaga para volver a la ventana normal.
 */
return new class extends Migration
{
    private const SETTING = [
        'key' => 'breakfast_open_all_day',
        'value' => '0',
        'type' => 'boolean',
        'label' => 'Ventana Abierta (Pruebas)',
        'description' => 'Mientras está activo, el kiosco entrega desayunos a cualquier hora y cualquier día: ignora la ventana antes de la entrada y el horario del empleado. Las demás reglas (empleado activo, foto, contraseña de cobro, un desayuno por día) siguen aplicando. Apágalo para volver al horario normal.',
    ];

    public function up(): void
    {
        $exists = DB::table('system_settings')->where('key', self::SETTING['key'])->exists();
        if ($exists) {
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
