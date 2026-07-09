<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vuelve authorization_type NULLABLE (revierte 2026_05_08_150000).
 *
 * Bug (Luis 2026-07-09): un concepto de compensación con "Ninguno" en Tipo de
 * Autorización mandaba authorization_type = null, y la columna era NOT NULL, así
 * que el INSERT reventaba con "NOT NULL constraint failed" → 500 sin mensaje
 * visible ("no me deja dar de alta, no da error"). Afectaba a TODO concepto sin
 * autorización: descuentos, bonos, cantidades fijas recurrentes, etc.
 *
 * Ahora "Ninguno" se guarda como null limpio. Los conceptos que sí se autorizan
 * conservan su authorization_type; los recurrentes/descuentos no necesitan uno.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compensation_types', function (Blueprint $table) {
            $table->string('authorization_type', 50)
                ->nullable()
                ->default(null)
                ->change();
        });
    }

    public function down(): void
    {
        // Backfill de seguridad antes de volver a NOT NULL.
        \Illuminate\Support\Facades\DB::table('compensation_types')
            ->whereNull('authorization_type')
            ->update(['authorization_type' => 'special']);

        Schema::table('compensation_types', function (Blueprint $table) {
            $table->string('authorization_type', 50)
                ->default('special')
                ->nullable(false)
                ->change();
        });
    }
};
