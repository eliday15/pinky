<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo de desayunos: NIP personal para cobrar el desayuno en el kiosco.
 *
 * - breakfast_pin: hash (bcrypt) del NIP que el empleado teclea en el kiosco
 *   de desayunos. Es independiente del cash_pin (NIP de cobro de nómina en
 *   efectivo) para no exponer ese NIP en un teclado que se usa a diario.
 *   Nunca se guarda en texto plano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (! Schema::hasColumn('employees', 'breakfast_pin')) {
                $table->string('breakfast_pin')->nullable()->after('cash_pin');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['breakfast_pin']);
        });
    }
};
