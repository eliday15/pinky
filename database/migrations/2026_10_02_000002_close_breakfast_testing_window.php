<?php

use App\Models\SystemSetting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Luis cerró las pruebas el 2 de octubre: volver al horario de cada
        // empleado con un margen mínimo de diez minutos antes de su entrada.
        SystemSetting::set('breakfast_open_all_day', false);
        SystemSetting::set('breakfast_close_minutes_before_entry', 10);
    }

    public function down(): void
    {
        // Revertir código no debe reabrir entregas fuera del horario autorizado.
    }
};
