<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase D (DECISIONES_NEGOCIO_2026-06-04.md §3, §4, §6):
 *
 * - vacation_premium_pay: la prima vacacional por fin se paga en nómina como
 *   concepto separado (días de vacación × sueldo diario × %).
 * - sick_leave_pay / sick_leave_days: las incapacidades con goce se pagan
 *   (según is_paid del tipo) y los días se persisten en el entry.
 * - incident_types.count_mode: días hábiles vs calendario configurable por
 *   tipo. Default hábiles; INC (incapacidad) pasa a calendario (estándar
 *   IMSS). El mismo modo aplica en captura, saldo y nómina.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_entries', function (Blueprint $table) {
            $table->decimal('vacation_premium_pay', 12, 2)->default(0)->after('vacation_pay');
            $table->decimal('sick_leave_pay', 12, 2)->default(0)->after('vacation_premium_pay');
            $table->integer('sick_leave_days')->default(0)->after('vacation_days_paid');
        });

        Schema::table('incident_types', function (Blueprint $table) {
            $table->string('count_mode', 20)->default('working_days')->after('category');
        });

        DB::table('incident_types')
            ->where('code', 'INC')
            ->update(['count_mode' => 'calendar_days']);
    }

    public function down(): void
    {
        Schema::table('payroll_entries', function (Blueprint $table) {
            $table->dropColumn(['vacation_premium_pay', 'sick_leave_pay', 'sick_leave_days']);
        });

        Schema::table('incident_types', function (Blueprint $table) {
            $table->dropColumn('count_mode');
        });
    }
};
