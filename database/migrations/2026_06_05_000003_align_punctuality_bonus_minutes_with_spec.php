<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Auditoría #76: la propuesta comercial promete el bono de puntualidad al
 * llegar 10 minutos antes, pero el seed original dejó el setting en 5.
 * Solo se corrige si sigue en el default viejo — un valor distinto sería
 * una decisión deliberada de RR.HH. y se respeta.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_settings')
            ->where('key', 'punctuality_bonus_minutes')
            ->where('value', '5')
            ->update(['value' => '10']);
    }

    public function down(): void
    {
        DB::table('system_settings')
            ->where('key', 'punctuality_bonus_minutes')
            ->where('value', '10')
            ->update(['value' => '5']);
    }
};
