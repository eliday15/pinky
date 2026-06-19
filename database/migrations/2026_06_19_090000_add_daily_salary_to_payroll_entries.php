<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persiste el sueldo diario usado en el cálculo de cada entry. El modelo y el
 * PayrollCalculatorService ya lo escriben (fillable + updateOrCreate); faltaba
 * la columna en la tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_entries', function (Blueprint $table) {
            $table->decimal('daily_salary', 10, 2)->default(0)->after('hourly_rate');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_entries', function (Blueprint $table) {
            $table->dropColumn('daily_salary');
        });
    }
};
