<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nómina por departamento (Taller aparte).
 *
 * Un periodo de nómina puede tener alcance (scope):
 * - department_id NULL  => nómina GENERAL: todos los activos EXCEPTO los de un
 *   departamento marcado con su propia nómina.
 * - department_id = X   => nómina de SOLO ese departamento.
 *
 * `departments.has_separate_payroll` marca qué departamentos salen de la
 * general y llevan su propio periodo (hoy: Taller). Escalable a otros deptos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payroll_periods', 'department_id')) {
            Schema::table('payroll_periods', function (Blueprint $table) {
                $table->foreignId('department_id')
                    ->nullable()
                    ->after('status')
                    ->constrained('departments')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('departments', 'has_separate_payroll')) {
            Schema::table('departments', function (Blueprint $table) {
                $table->boolean('has_separate_payroll')
                    ->default(false)
                    ->after('is_active');
            });
        }

        // Taller lleva su propia nómina (idempotente).
        if (Schema::hasColumn('departments', 'has_separate_payroll')) {
            DB::table('departments')->where('code', 'TALLER')->update(['has_separate_payroll' => true]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payroll_periods', 'department_id')) {
            Schema::table('payroll_periods', function (Blueprint $table) {
                $table->dropConstrainedForeignId('department_id');
            });
        }

        if (Schema::hasColumn('departments', 'has_separate_payroll')) {
            Schema::table('departments', function (Blueprint $table) {
                $table->dropColumn('has_separate_payroll');
            });
        }
    }
};
