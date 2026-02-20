<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add default break minutes to departments.
 *
 * Allows each department to define its own default lunch/break time.
 * The fallback chain for break deduction becomes:
 *   schedule.break_minutes -> department.default_break_minutes -> 60
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->smallInteger('default_break_minutes')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('default_break_minutes');
        });
    }
};
