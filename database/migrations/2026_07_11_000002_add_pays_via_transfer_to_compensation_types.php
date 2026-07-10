<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * pays_via_transfer: si un concepto se paga por TRANSFERENCIA (banco) para los
 * empleados formalizados, en vez de en efectivo. Contpaq paga ciertas
 * percepciones (aguinaldo, cumpleaños, prima) junto con el sueldo, por
 * transferencia; este flag permite marcar el concepto para que caiga ahí en la
 * nómina que paga base. Default false = comportamiento actual (efectivo).
 * Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('compensation_types', 'pays_via_transfer')) {
            Schema::table('compensation_types', function (Blueprint $table) {
                $table->boolean('pays_via_transfer')->default(false)->after('payment_period');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('compensation_types', 'pays_via_transfer')) {
            Schema::table('compensation_types', function (Blueprint $table) {
                $table->dropColumn('pays_via_transfer');
            });
        }
    }
};
