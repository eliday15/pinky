<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Personal de entregas" por RANGO de fechas en vez de por semana fija (Dani
 * 2026-07-28): RRHH elige "de qué fecha a qué fecha" salió a entregas cada
 * colaborador. La tabla `delivery_weeks` era nueva y estaba vacía, así que se
 * reemplaza sin migrar datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('delivery_weeks');

        Schema::create('delivery_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->foreignId('created_by')->nullable()->constrained('app_users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'start_date', 'end_date']);
            $table->index(['start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_periods');

        Schema::create('delivery_weeks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('week_start');
            $table->foreignId('created_by')->nullable()->constrained('app_users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'week_start']);
        });
    }
};
