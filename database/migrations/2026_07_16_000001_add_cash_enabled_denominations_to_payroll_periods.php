<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Denominaciones habilitadas para preparar el efectivo, por periodo (Luis
 * 2026-07-16).
 *
 * El custodio "desmarca" las denominaciones que no tenga (ej. $1000) en el
 * Paso 1. Antes esa elección vivía sólo en el localStorage del navegador del
 * custodio, así que el cobrador —en otra máquina— seguía viendo el desglose con
 * billetes de $1000. Ahora se guarda por periodo para que el Paso 1 (custodio)
 * y el Paso 2 (cobrador) muestren el MISMO desglose.
 *
 * NULL = todas las denominaciones habilitadas (comportamiento por defecto).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->json('cash_enabled_denominations')->nullable()->after('cash_delivery_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->dropColumn('cash_enabled_denominations');
        });
    }
};
