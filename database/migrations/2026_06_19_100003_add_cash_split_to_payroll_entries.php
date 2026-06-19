<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pago en efectivo: separa el neto de cada entry en la porción a pagar en
 * efectivo (cash_amount) y la porción por banco/CONTPAQi (bank_amount).
 *
 * NO modifica regular_pay/gross_pay/net_pay (siguen alimentando CONTPAQi y los
 * tests). Solo derivan: para inscritos al IMSS, el base neto va a bank_amount y
 * los extras a cash_amount; para no inscritos, todo el neto es cash_amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('payroll_entries', 'cash_amount')) {
                $table->decimal('cash_amount', 12, 2)->default(0)->after('net_pay');
            }
            if (! Schema::hasColumn('payroll_entries', 'bank_amount')) {
                $table->decimal('bank_amount', 12, 2)->default(0)->after('cash_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payroll_entries', function (Blueprint $table) {
            $table->dropColumn(['cash_amount', 'bank_amount']);
        });
    }
};
