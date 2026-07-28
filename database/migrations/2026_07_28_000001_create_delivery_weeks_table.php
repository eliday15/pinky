<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Personal de entregas por semana" (Dani 2026-07-28).
 *
 * Los que salen a entregas se van turnando, así que no es una lista fija ni un
 * marcador permanente: cada semana RRHH selecciona quiénes salieron. A esos, su
 * velada y tiempo extra AUTORIZADOS se pagan/reflejan completos esa semana
 * (sin topar contra la checada, que no los alcanza a registrar porque andan en
 * la calle).
 *
 * `week_start` = lunes de la semana (Carbon startOfWeek), para alinear con el
 * reporte semanal de tiempo extra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_weeks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('week_start');
            $table->foreignId('created_by')->nullable()->constrained('app_users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'week_start']);
            $table->index('week_start');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_weeks');
    }
};
