<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Empleados que NO checan (sin checador / sin ZKTeco).
 *
 * is_attendance_exempt: true = el empleado no usa checador biométrico ni marca
 * entradas/salidas. No trabaja en oficina pero cobra su sueldo completo; sus
 * faltas y autorizaciones se capturan MANUALMENTE (incidencias). Por defecto
 * false, de modo que TODOS los empleados existentes siguen checando como hasta
 * ahora (el ADD COLUMN NOT NULL DEFAULT false los rellena automáticamente).
 *
 * Idempotente (Schema::hasColumn) porque prod docker/start.sh corre
 * `migrate --force` y se traga errores; un re-run queda como no-op limpio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (! Schema::hasColumn('employees', 'is_attendance_exempt')) {
                $table->boolean('is_attendance_exempt')
                    ->default(false)
                    ->after('is_imss_enrolled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (Schema::hasColumn('employees', 'is_attendance_exempt')) {
                $table->dropColumn('is_attendance_exempt');
            }
        });
    }
};
