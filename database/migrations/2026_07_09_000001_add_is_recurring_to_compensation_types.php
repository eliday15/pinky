<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conceptos RECURRENTES (Luis/fábrica 2026-07-09): una cantidad fija que se le
 * da al empleado CADA periodo (semanal o mensual, según payment_period), de
 * forma automática, sin necesitar una autorización ni condición de asistencia.
 *
 * is_recurring = true convierte un concepto de compensación en un pago fijo
 * que la nómina agrega solo por cada empleado inscrito, en el periodo que
 * corresponde a su payment_period. Default false: los conceptos existentes no
 * cambian (siguen pagándose vía autorización).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('compensation_types', 'is_recurring')) {
            return;
        }

        Schema::table('compensation_types', function (Blueprint $table) {
            $table->boolean('is_recurring')->default(false)->after('payment_period');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('compensation_types', 'is_recurring')) {
            return;
        }

        Schema::table('compensation_types', function (Blueprint $table) {
            $table->dropColumn('is_recurring');
        });
    }
};
