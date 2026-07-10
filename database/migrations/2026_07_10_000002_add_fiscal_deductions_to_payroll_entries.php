<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retenciones fiscales del trabajador en cada entrada de nómina (Nivel 1).
 * Reducen el neto/transferencia de los empleados formalizados; el subsidio al
 * empleo SUMA (se acredita) cuando supera al ISR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('payroll_entries', 'isr_amount')) {
                $table->decimal('isr_amount', 12, 2)->default(0)->after('deductions');
            }
            if (! Schema::hasColumn('payroll_entries', 'imss_amount')) {
                $table->decimal('imss_amount', 12, 2)->default(0)->after('isr_amount');
            }
            if (! Schema::hasColumn('payroll_entries', 'infonavit_amount')) {
                $table->decimal('infonavit_amount', 12, 2)->default(0)->after('imss_amount');
            }
            if (! Schema::hasColumn('payroll_entries', 'subsidy_amount')) {
                $table->decimal('subsidy_amount', 12, 2)->default(0)->after('infonavit_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payroll_entries', function (Blueprint $table) {
            foreach (['isr_amount', 'imss_amount', 'infonavit_amount', 'subsidy_amount'] as $col) {
                if (Schema::hasColumn('payroll_entries', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
