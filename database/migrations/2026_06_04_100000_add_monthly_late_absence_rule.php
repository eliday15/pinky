<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Regla mensual de retardos→falta (DECISIONES_NEGOCIO_2026-06-04.md §1).
 *
 * - incidents.late_month ('YYYY-MM') marca las incidencias FRT generadas por el
 *   cierre mensual y da idempotencia por (empleado, mes).
 * - Reactiva el tipo FRT: vuelve a ser sin goce (es una deducción) y
 *   auto-aprobada (la regla es objetiva, viene de checadas).
 * - monthly_late_absence_start_month: mes de corte a partir del cual aplica la
 *   regla mensual; los meses anteriores fueron manejados por el sistema
 *   semanal legado y nunca se regeneran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->string('late_month', 7)->nullable()->after('reason');
            $table->index(['employee_id', 'late_month']);
        });

        DB::table('incident_types')
            ->where('code', 'FRT')
            ->update([
                'is_active' => true,
                'is_paid' => false,
                'requires_approval' => false,
            ]);

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'monthly_late_absence_start_month'],
            [
                'value' => '2026-06',
                'type' => 'string',
                'group' => 'attendance',
                'label' => 'Inicio de regla mensual de retardos',
                'description' => 'Primer mes (YYYY-MM) en que los retardos acumulados se convierten en faltas al cierre del mes. Meses anteriores no se procesan.',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropIndex(['employee_id', 'late_month']);
            $table->dropColumn('late_month');
        });

        DB::table('incident_types')
            ->where('code', 'FRT')
            ->update(['is_active' => false, 'is_paid' => true]);

        DB::table('system_settings')->where('key', 'monthly_late_absence_start_month')->delete();
    }
};
