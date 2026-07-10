<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos fiscales por empleado para el motor de retenciones (Nivel 1):
 * - rfc: referencia (y CFDI futuro).
 * - sdi/sbc: Salario Diario Integrado y Base de Cotización, importados de
 *   Contpaq (el factor de integración depende de la antigüedad, no se re-deriva).
 * - infonavit_credit_type/value: crédito Infonavit del trabajador (CF fijo o FD
 *   factor de descuento), importado del PDF de Contpaq.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (! Schema::hasColumn('employees', 'rfc')) {
                $table->string('rfc', 20)->nullable()->after('imss_number');
            }
            if (! Schema::hasColumn('employees', 'sdi')) {
                $table->decimal('sdi', 10, 2)->nullable()->after('rfc');
            }
            if (! Schema::hasColumn('employees', 'sbc')) {
                $table->decimal('sbc', 10, 2)->nullable()->after('sdi');
            }
            if (! Schema::hasColumn('employees', 'infonavit_credit_type')) {
                $table->enum('infonavit_credit_type', ['none', 'cf', 'fd'])
                    ->default('none')->after('sbc');
            }
            if (! Schema::hasColumn('employees', 'infonavit_credit_value')) {
                $table->decimal('infonavit_credit_value', 12, 4)->nullable()->after('infonavit_credit_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            foreach (['rfc', 'sdi', 'sbc', 'infonavit_credit_type', 'infonavit_credit_value'] as $col) {
                if (Schema::hasColumn('employees', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
