<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Conceptos BLINDADOS (Elias 2026-08-12): un concepto con is_locked no se
     * puede modificar ni borrar desde la UI — nadie, ni superadmin (corregirlo
     * exige tocar la base a propósito). Nació por "Falta Justificada": paga UN
     * DÍA del empleado (per_day × sueldo diario, percentage 0) para reponer un
     * día que se olvidó pagar la semana anterior, y estuvo a nada de pagar $797
     * por una reconfiguración accidental.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('compensation_types', 'is_locked')) {
            Schema::table('compensation_types', function (Blueprint $table) {
                $table->boolean('is_locked')->default(false)->after('is_recurring');
            });
        }

        // Blindar "Falta Justificada" (idempotente, por código).
        DB::table('compensation_types')
            ->where('code', 'Falta Just')
            ->update(['is_locked' => true]);
    }

    public function down(): void
    {
        Schema::table('compensation_types', function (Blueprint $table) {
            $table->dropColumn('is_locked');
        });
    }
};
