<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clave del catálogo SAT c_TipoPercepcion para cada concepto (CFDI nómina
 * 1.2): 001 sueldos, 019 horas extra, 002 aguinaldo, 021 prima vacacional,
 * 029 bonos/premios, etc. Nullable: el builder usa 038 "Otros ingresos por
 * salarios" como fallback y la validación previa avisa. Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('compensation_types', 'sat_perception_code')) {
            Schema::table('compensation_types', function (Blueprint $table) {
                $table->string('sat_perception_code', 3)->nullable()->after('pays_via_transfer');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('compensation_types', 'sat_perception_code')) {
            Schema::table('compensation_types', function (Blueprint $table) {
                $table->dropColumn('sat_perception_code');
            });
        }
    }
};
