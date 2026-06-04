<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase E (DECISIONES_NEGOCIO_2026-06-04.md §7): cuando cambian datos de un
 * periodo ya calculado (incidencias, autorizaciones, checadas), los periodos
 * en review/approved se marcan "requiere recálculo" para que un admin decida;
 * los draft/calculating se recalculan automáticamente; los paid son inmutables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->boolean('requires_recalculation')->default(false)->after('status');
            $table->timestamp('recalculation_flagged_at')->nullable()->after('requires_recalculation');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->dropColumn(['requires_recalculation', 'recalculation_flagged_at']);
        });
    }
};
