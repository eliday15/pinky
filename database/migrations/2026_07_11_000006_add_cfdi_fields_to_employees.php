<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos del empleado requeridos por el CFDI 4.0 de nómina (complemento 1.2):
 * CURP, régimen fiscal del receptor, banco/CLABE (forma de pago), tipo de
 * contrato y de jornada (catálogos SAT). Nullable: la validación previa al
 * timbrado reporta faltantes por empleado. Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (! Schema::hasColumn('employees', 'curp')) {
                $table->string('curp', 18)->nullable()->after('rfc');
            }
            if (! Schema::hasColumn('employees', 'fiscal_regime')) {
                // c_RegimenFiscal del receptor: 605 = Sueldos y Salarios.
                $table->string('fiscal_regime', 3)->default('605')->after('curp');
            }
            if (! Schema::hasColumn('employees', 'bank_code')) {
                // c_Banco (3 dígitos) para pago por transferencia.
                $table->string('bank_code', 3)->nullable()->after('fiscal_regime');
            }
            if (! Schema::hasColumn('employees', 'clabe')) {
                $table->string('clabe', 18)->nullable()->after('bank_code');
            }
            if (! Schema::hasColumn('employees', 'contract_type')) {
                // c_TipoContrato: 01 = por tiempo indeterminado.
                $table->string('contract_type', 2)->default('01')->after('clabe');
            }
            if (! Schema::hasColumn('employees', 'workday_type')) {
                // c_TipoJornada: 01 = diurna.
                $table->string('workday_type', 2)->default('01')->after('contract_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            foreach (['curp', 'fiscal_regime', 'bank_code', 'clabe', 'contract_type', 'workday_type'] as $col) {
                if (Schema::hasColumn('employees', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
