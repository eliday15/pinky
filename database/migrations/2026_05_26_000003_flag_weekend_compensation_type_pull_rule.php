<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Opt the existing "Fin De Semana" concept into the weekend pull rule so it
     * can be loaded from check-ins: one per-day entry for each weekend day the
     * employee worked outside their schedule. Matched by code; safe no-op if it
     * was renamed/removed.
     */
    public function up(): void
    {
        DB::table('compensation_types')
            ->where('code', 'FIN')
            ->update(['attendance_pull_rule' => 'weekend']);
    }

    public function down(): void
    {
        DB::table('compensation_types')
            ->where('code', 'FIN')
            ->where('attendance_pull_rule', 'weekend')
            ->update(['attendance_pull_rule' => null]);
    }
};
