<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acumulados ANUALES por empleado (insumo del ajuste anual de ISR Art. 97 y
 * del tope exento del aguinaldo). Las columnas "external_*" guardan el
 * acumulado importado de Contpaq al momento del corte (una vez); las demás las
 * reconstruye Pinky desde los periodos aprobados (rebuild idempotente).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_annual_totals')) {
            return;
        }

        Schema::create('employee_annual_totals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            // Reconstruido por Pinky desde periodos aprobados/pagados:
            $table->decimal('taxable_income', 12, 2)->default(0);
            $table->decimal('exempt_income', 12, 2)->default(0);
            $table->decimal('isr_withheld', 12, 2)->default(0);
            $table->decimal('subsidy_paid', 12, 2)->default(0);
            $table->decimal('days_paid', 8, 2)->default(0);
            // Importado de Contpaq al corte (no lo toca el rebuild):
            $table->decimal('external_taxable_income', 12, 2)->default(0);
            $table->decimal('external_isr_withheld', 12, 2)->default(0);
            $table->timestamps();
            $table->unique(['employee_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_annual_totals');
    }
};
