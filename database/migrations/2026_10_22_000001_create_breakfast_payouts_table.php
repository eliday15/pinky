<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cortes de pago al vendedor de desayunos (Luis 2026-10-22).
 *
 * Cada corte cubre un rango de fechas elegido por quien paga y congela los
 * desayunos que entraron en él (breakfast_claims.breakfast_payout_id). Los
 * cortes son consecutivos: una vez pagado un rango no se puede volver a pagar
 * ninguna fecha anterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('breakfast_payouts')) {
            Schema::create('breakfast_payouts', function (Blueprint $table) {
                $table->id();
                $table->date('start_date');
                $table->date('end_date');
                $table->unsignedInteger('claims_count');
                $table->decimal('amount', 10, 2);
                $table->foreignId('vendor_employee_id')->nullable()->constrained('employees')->nullOnDelete();
                $table->foreignId('paid_by')->nullable()->constrained('app_users')->nullOnDelete();
                $table->timestamps();

                $table->index('end_date');
            });
        }

        if (! Schema::hasColumn('breakfast_claims', 'breakfast_payout_id')) {
            Schema::table('breakfast_claims', function (Blueprint $table) {
                $table->foreignId('breakfast_payout_id')->nullable()->after('registered_by')
                    ->constrained('breakfast_payouts')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('breakfast_claims', 'breakfast_payout_id')) {
            Schema::table('breakfast_claims', function (Blueprint $table) {
                $table->dropConstrainedForeignId('breakfast_payout_id');
            });
        }

        Schema::dropIfExists('breakfast_payouts');
    }
};
