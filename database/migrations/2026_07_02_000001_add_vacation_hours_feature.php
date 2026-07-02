<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Horas a cuenta de vacaciones" (Dani 2026-07-01): un tipo de incidencia
     * cuyo permiso de entrada tarde / salida temprano descuenta HORAS del saldo
     * de vacaciones (1 día = 8 h) y evita la falta por umbral mientras queden
     * horas.
     *
     * - incident_types.uses_vacation_hours: marca el tipo que descuenta horas del
     *   saldo de vacaciones y suprime la falta (entrada tarde Y salida temprano).
     * - employees.vacation_hours_used: horas de vacaciones ya consumidas por esos
     *   permisos. El saldo de días sigue en enteros; las horas se llevan aparte:
     *   horas disponibles = (derecho - usados) × 8 − vacation_hours_used.
     */
    public function up(): void
    {
        Schema::table('incident_types', function (Blueprint $table) {
            $table->boolean('uses_vacation_hours')->default(false)->after('has_time_range');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->decimal('vacation_hours_used', 6, 2)->default(0)->after('vacation_days_used');
        });
    }

    public function down(): void
    {
        Schema::table('incident_types', function (Blueprint $table) {
            $table->dropColumn('uses_vacation_hours');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('vacation_hours_used');
        });
    }
};
