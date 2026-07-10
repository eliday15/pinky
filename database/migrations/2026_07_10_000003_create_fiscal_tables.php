<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tablas configurables del motor fiscal: tarifa de ISR (Art. 96) y tabla de
 * subsidio al empleo, por periodicidad. Los escalares (UMA, salario mínimo,
 * % IMSS obrero, tope SBC) viven en system_settings (FiscalSettingsSeeder).
 * Editables desde la pantalla de configuración; cambian cada año.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fiscal_isr_brackets')) {
            Schema::create('fiscal_isr_brackets', function (Blueprint $table) {
                $table->id();
                $table->string('period_type', 20)->default('weekly'); // weekly | monthly
                $table->decimal('lower_limit', 12, 2);
                $table->decimal('fixed_fee', 12, 2);
                $table->decimal('percent_over_excess', 8, 4); // % sobre excedente del límite inferior
                $table->timestamps();
                $table->index(['period_type', 'lower_limit']);
            });
        }

        if (! Schema::hasTable('fiscal_subsidy_brackets')) {
            Schema::create('fiscal_subsidy_brackets', function (Blueprint $table) {
                $table->id();
                $table->string('period_type', 20)->default('weekly');
                $table->decimal('lower_limit', 12, 2);
                $table->decimal('upper_limit', 12, 2)->nullable(); // null = sin tope superior
                $table->decimal('subsidy', 12, 2);
                $table->timestamps();
                $table->index(['period_type', 'lower_limit']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_subsidy_brackets');
        Schema::dropIfExists('fiscal_isr_brackets');
    }
};
