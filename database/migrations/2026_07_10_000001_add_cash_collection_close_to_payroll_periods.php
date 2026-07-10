<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cierre del cobro en efectivo (fin del paso 2) y devolución del sobrante.
 *
 * Cuando el cobrador termina de cobrar, "cierra la nómina": se congela cuánto
 * efectivo debe regresar a la empresa (cash_return_amount = suma de lo no
 * cobrado) y se bloquea el cobro en el periodo. Lo no cobrado sigue pendiente
 * en cash_payouts y se acumula al empleado en el siguiente cierre de efectivo.
 * El super admin confirma después la recepción del efectivo devuelto.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('payroll_periods', 'cash_collection_closed_at')) {
            return;
        }

        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->timestamp('cash_collection_closed_at')->nullable()->after('cash_delivery_confirmed_at');
            $table->foreignId('cash_collection_closed_by')->nullable()->after('cash_collection_closed_at')->constrained('app_users')->nullOnDelete();
            $table->decimal('cash_return_amount', 12, 2)->nullable()->after('cash_collection_closed_by');
            $table->timestamp('cash_return_received_at')->nullable()->after('cash_return_amount');
            $table->foreignId('cash_return_received_by')->nullable()->after('cash_return_received_at')->constrained('app_users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_collection_closed_by');
            $table->dropConstrainedForeignId('cash_return_received_by');
            $table->dropColumn(['cash_collection_closed_at', 'cash_return_amount', 'cash_return_received_at']);
        });
    }
};
