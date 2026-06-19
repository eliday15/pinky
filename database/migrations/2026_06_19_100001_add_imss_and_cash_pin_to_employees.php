<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pago en efectivo: marca de inscripción al IMSS y PIN personal de cobro.
 *
 * - is_imss_enrolled: si el empleado YA está inscrito al IMSS, su sueldo base
 *   se paga por banco/CONTPAQi y NO sale en efectivo; los extras siempre son
 *   efectivo.
 * - cash_pin: hash (bcrypt) del PIN que el empleado usa para cobrar su efectivo.
 *   Nunca se guarda en texto plano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (! Schema::hasColumn('employees', 'is_imss_enrolled')) {
                $table->boolean('is_imss_enrolled')->default(false)->after('imss_number');
            }
            if (! Schema::hasColumn('employees', 'cash_pin')) {
                $table->string('cash_pin')->nullable()->after('is_imss_enrolled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['is_imss_enrolled', 'cash_pin']);
        });
    }
};
