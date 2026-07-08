<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El kiosco de desayunos usa la MISMA contraseña de cobro de nómina
 * (cash_pin), no un NIP aparte: se elimina la columna breakfast_pin que se
 * agregó en 2026_07_08_000001 y nunca llegó a capturarse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (Schema::hasColumn('employees', 'breakfast_pin')) {
                $table->dropColumn('breakfast_pin');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (! Schema::hasColumn('employees', 'breakfast_pin')) {
                $table->string('breakfast_pin')->nullable()->after('cash_pin');
            }
        });
    }
};
