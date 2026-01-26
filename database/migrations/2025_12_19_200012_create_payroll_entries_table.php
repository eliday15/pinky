<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payroll_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained();

            // Tasas aplicadas
            $table->decimal('hourly_rate', 10, 2);
            $table->decimal('overtime_multiplier', 3, 2);
            $table->decimal('holiday_multiplier', 3, 2);

            // Horas
            $table->decimal('regular_hours', 6, 2)->default(0);
            $table->decimal('overtime_hours', 6, 2)->default(0);
            $table->decimal('holiday_hours', 6, 2)->default(0);
            $table->decimal('weekend_hours', 6, 2)->default(0);

            // Días
            $table->integer('days_worked')->default(0);
            $table->integer('days_absent')->default(0);
            $table->integer('days_late')->default(0);
            $table->integer('vacation_days_paid')->default(0);

            // Montos
            $table->decimal('regular_pay', 12, 2)->default(0);
            $table->decimal('overtime_pay', 12, 2)->default(0);
            $table->decimal('holiday_pay', 12, 2)->default(0);
            $table->decimal('weekend_pay', 12, 2)->default(0);
            $table->decimal('vacation_pay', 12, 2)->default(0);
            $table->decimal('bonuses', 12, 2)->default(0);
            $table->decimal('deductions', 12, 2)->default(0);
            $table->decimal('gross_pay', 12, 2)->default(0);
            $table->decimal('net_pay', 12, 2)->default(0);

            $table->json('calculation_breakdown')->nullable();
            $table->timestamps();

            $table->unique(['payroll_period_id', 'employee_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_entries');
    }
};
