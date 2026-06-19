<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pago en efectivo: ledger por periodo+empleado del efectivo a entregar.
 *
 * Cada fila congela, al cerrar el efectivo de un periodo, cuánto se le debe al
 * empleado en efectivo: el monto del periodo (cash_amount redondeado al peso)
 * más el acumulado (opening_balance) de lo que no cobró en periodos previos.
 * El cobro con PIN marca status='paid'; lo no cobrado permanece como saldo
 * pendiente (outstanding) y reaparece en el siguiente cierre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained();
            $table->decimal('period_amount', 12, 2)->default(0);
            $table->decimal('opening_balance', 12, 2)->default(0);
            $table->decimal('total_due', 12, 2)->default(0);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->string('status')->default('pending');
            $table->timestamp('collected_at')->nullable();
            $table->boolean('pin_verified')->default(false);
            $table->foreignId('collected_by')->nullable()->constrained('app_users')->nullOnDelete();
            $table->json('denomination_breakdown')->nullable();
            $table->timestamps();

            $table->unique(['payroll_period_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_payouts');
    }
};
