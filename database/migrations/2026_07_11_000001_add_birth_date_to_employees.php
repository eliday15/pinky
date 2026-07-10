<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fecha de nacimiento del empleado: insumo del bono de CUMPLEAÑOS (1 día de
 * sueldo en la semana del cumpleaños, como Contpaq). Nullable: mientras no se
 * capture, el empleado simplemente no cobra cumpleaños. Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employees', 'birth_date')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->date('birth_date')->nullable()->after('hire_date');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('employees', 'birth_date')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropColumn('birth_date');
            });
        }
    }
};
