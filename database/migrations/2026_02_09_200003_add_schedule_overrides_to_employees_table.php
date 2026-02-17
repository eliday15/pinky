<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add schedule_overrides JSON column to employees table.
 *
 * Allows per-employee customization of schedule values (entry_time,
 * exit_time, break_minutes, etc.) without creating a new schedule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->json('schedule_overrides')->nullable()->after('schedule_id');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('schedule_overrides');
        });
    }
};
