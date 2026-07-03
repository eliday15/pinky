<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pago en efectivo en 2 pasos: marca de "entrega preparada" (paso 1).
 *
 * cash_delivery_confirmed_at se setea cuando el cajero confirma que preparó el
 * efectivo (definió/retiró los billetes) sobre un periodo con el efectivo ya
 * cerrado. Es requisito para cobrar (paso 2). Se reinicia al re-cerrar el
 * efectivo porque los montos/billetes cambian.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('payroll_periods', 'cash_delivery_confirmed_at')) {
            return;
        }

        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->timestamp('cash_delivery_confirmed_at')->nullable()->after('cash_closed_at');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->dropColumn('cash_delivery_confirmed_at');
        });
    }
};
