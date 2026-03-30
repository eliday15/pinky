<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $now = Carbon::now();

        DB::table('incident_types')->insert([
            [
                'name'               => 'Permiso de Salida',
                'code'               => 'PSA',
                'category'           => 'permission',
                'is_paid'            => false,
                'deducts_vacation'   => false,
                'requires_approval'  => true,
                'requires_document'  => false,
                'affects_attendance' => true,
                'has_time_range'     => true,
                'color'              => '#3B82F6',
                'is_active'          => true,
                'priority'           => 10,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'name'               => 'Permiso de Entrada',
                'code'               => 'PEN',
                'category'           => 'permission',
                'is_paid'            => false,
                'deducts_vacation'   => false,
                'requires_approval'  => true,
                'requires_document'  => false,
                'affects_attendance' => true,
                'has_time_range'     => true,
                'color'              => '#6366F1',
                'is_active'          => true,
                'priority'           => 20,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'name'               => 'Cambio de Horario',
                'code'               => 'CDH',
                'category'           => 'special',
                'is_paid'            => false,
                'deducts_vacation'   => false,
                'requires_approval'  => true,
                'requires_document'  => false,
                'affects_attendance' => false,
                'has_time_range'     => false,
                'color'              => '#8B5CF6',
                'is_active'          => true,
                'priority'           => 30,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
        ]);

        DB::table('incident_types')
            ->where('code', 'INC')
            ->update(['requires_document' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('incident_types')
            ->whereIn('code', ['PSA', 'PEN', 'CDH'])
            ->delete();

        DB::table('incident_types')
            ->where('code', 'INC')
            ->update(['requires_document' => false]);
    }
};
