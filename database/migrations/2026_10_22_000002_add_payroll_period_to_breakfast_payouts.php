<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los cortes de desayunos se le pagan al vendedor DENTRO de su nómina
 * semanal (Luis 2026-10-22, "opción 1"). payroll_period_id es la nómina que
 * paga el corte: cada corte se paga una sola vez. Si la nómina se borra, el
 * corte queda libre para la siguiente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('breakfast_payouts', 'payroll_period_id')) {
            return;
        }

        Schema::table('breakfast_payouts', function (Blueprint $table) {
            $table->foreignId('payroll_period_id')->nullable()->after('paid_by')
                ->constrained('payroll_periods')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('breakfast_payouts', 'payroll_period_id')) {
            Schema::table('breakfast_payouts', function (Blueprint $table) {
                $table->dropConstrainedForeignId('payroll_period_id');
            });
        }
    }
};
