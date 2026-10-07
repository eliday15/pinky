<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Aclara los textos de la ventana de desayunos en Configuración.
 *
 * Caso 2026-10-06 (Luis Fernando, EMP-0409): breakfast_window_minutes estaba
 * en 10 desde julio — su descripción ("minutos antes de la entrada...") se leía
 * igual que la del cierre, así que la APERTURA se capturó como si fuera el
 * margen. Con apertura 10 y cierre 10 la ventana quedó vacía y el kiosco
 * rechazaba a todos ("Aún es temprano" a las 05:43 y "Fuera de horario" a las
 * 05:50). El guardado ya valida cierre < apertura; esto renombra ambos textos
 * para que no vuelvan a confundirse.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_settings')->where('key', 'breakfast_window_minutes')->update([
            'label' => 'Apertura de la Ventana (minutos)',
            'description' => 'Cuántos minutos ANTES de su hora de entrada se ABRE el kiosco para el empleado (ej. 60 = puede cobrar desde una hora antes). No es el cierre: ese se configura en "Cierre antes de la Entrada" y debe ser menor que esta apertura, o la ventana queda vacía.',
            'updated_at' => now(),
        ]);

        DB::table('system_settings')->where('key', 'breakfast_close_minutes_before_entry')->update([
            'label' => 'Cierre antes de la Entrada (minutos)',
            'description' => 'El desayuno se entrega hasta estos minutos ANTES de la hora de entrada (Luis 2026-10-02: 10 min; con menos margen ya no pasa). El minuto del cierre completo sí pasa: "hasta las 05:50" incluye 05:50:59. 0 = se entrega hasta la hora exacta de entrada. Debe ser menor que la apertura.',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Textos informativos: no hay estado que revertir.
    }
};
