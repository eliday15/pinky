<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pago UNIFICADO: la nómina semanal puede además pagar los EXTRAS del mes
 * (Elias 2026-08-25).
 *
 * Antes el mes se pagaba en un periodo aparte ("Mes 29 jul - 23 ago"), así que
 * el mismo día de pago el trabajador recibía DOS pagos: el sueldo de la semana
 * y los extras del mes. Ahora el rango de extras se guarda en el propio periodo
 * semanal: el sueldo base sigue calculándose sobre su semana
 * (start_date/end_date) y los extras sobre este rango, en UN solo recibo.
 *
 * NULL en ambas = periodo normal (semanal solo base, mensual solo extras).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table) {
            if (! Schema::hasColumn('payroll_periods', 'extras_start_date')) {
                $table->date('extras_start_date')->nullable()->after('end_date');
            }
            if (! Schema::hasColumn('payroll_periods', 'extras_end_date')) {
                $table->date('extras_end_date')->nullable()->after('extras_start_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->dropColumn(['extras_start_date', 'extras_end_date']);
        });
    }
};
