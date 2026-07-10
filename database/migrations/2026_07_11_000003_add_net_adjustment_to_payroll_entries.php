<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajuste al neto (concepto 99 de Contpaq): centavos que redondean el neto
 * transferido del formalizado al múltiplo de $0.20 más cercano. Se guarda
 * aparte para declararlo en el CFDI y explicar el recibo. Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payroll_entries', 'net_adjustment')) {
            Schema::table('payroll_entries', function (Blueprint $table) {
                $table->decimal('net_adjustment', 8, 2)->default(0)->after('subsidy_amount');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payroll_entries', 'net_adjustment')) {
            Schema::table('payroll_entries', function (Blueprint $table) {
                $table->dropColumn('net_adjustment');
            });
        }
    }
};
