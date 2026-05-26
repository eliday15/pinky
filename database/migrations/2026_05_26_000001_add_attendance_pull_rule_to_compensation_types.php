<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a configurable "pull from attendance" rule to compensation types.
     *
     * When set to 'meal', the type can be auto-loaded from check-ins in the bulk
     * authorization screen: one entry per (employee, day) where the employee
     * worked a long day (>= cena_min_worked_hours), crossed midnight (velada),
     * or worked a weekend day outside their schedule. These never auto-approve.
     *
     * Null (the default) preserves the existing behavior — overtime/velada keep
     * their own per-hour detection, every other type has no attendance pull.
     */
    public function up(): void
    {
        Schema::table('compensation_types', function (Blueprint $table) {
            $table->string('attendance_pull_rule', 30)
                ->nullable()
                ->after('authorization_type');
        });

        // Opt the existing "Cena" concept into the meal rule so it works out of
        // the box. Matched by code; safe no-op if it was renamed/removed.
        DB::table('compensation_types')
            ->where('code', 'Cena')
            ->update(['attendance_pull_rule' => 'meal']);
    }

    public function down(): void
    {
        Schema::table('compensation_types', function (Blueprint $table) {
            $table->dropColumn('attendance_pull_rule');
        });
    }
};
