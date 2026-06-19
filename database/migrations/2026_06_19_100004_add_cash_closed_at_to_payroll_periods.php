<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pago en efectivo: marca de "efectivo cerrado/preparado" para un periodo.
 *
 * cash_closed_at se setea al cerrar el efectivo (calcular billetes y crear los
 * cash_payouts) sobre un periodo ya aprobado. No agrega un status nuevo al
 * enum del periodo: el cierre de efectivo es una capa aparte del flujo
 * draft→…→paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->timestamp('cash_closed_at')->nullable()->after('recalculation_flagged_at');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->dropColumn('cash_closed_at');
        });
    }
};
