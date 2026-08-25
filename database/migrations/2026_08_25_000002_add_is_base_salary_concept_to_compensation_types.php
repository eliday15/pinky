<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Concepto que ES el sueldo del trabajador (Elias 2026-08-25).
 *
 * Algunos sueldos —los del personal en PERIODO DE PRUEBA, por ejemplo— se
 * capturan como concepto y se pagaban en la nómina mensual. Al unificar el pago
 * (semana + extras del mes en un solo recibo) ese concepto quedaría junto al
 * sueldo base y el trabajador cobraría el sueldo DOS veces.
 *
 * Marcando el concepto con esta casilla, el concepto NO se paga cuando el
 * periodo ya le está pagando sueldo base a ese empleado (manda el sueldo base).
 * Si el empleado no cobra base (sueldo diario en 0), el concepto sigue siendo
 * su único pago y se paga igual que siempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compensation_types', function (Blueprint $table) {
            if (! Schema::hasColumn('compensation_types', 'is_base_salary_concept')) {
                $table->boolean('is_base_salary_concept')->default(false)->after('is_recurring');
            }
        });
    }

    public function down(): void
    {
        Schema::table('compensation_types', function (Blueprint $table) {
            $table->dropColumn('is_base_salary_concept');
        });
    }
};
