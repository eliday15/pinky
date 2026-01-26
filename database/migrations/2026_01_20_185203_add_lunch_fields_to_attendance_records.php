<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add lunch tracking fields to attendance records.
 *
 * These fields enable tracking of lunch breaks to calculate
 * actual worked hours more accurately instead of using a fixed
 * break deduction.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->time('lunch_out')->nullable()->after('check_out');
            $table->time('lunch_in')->nullable()->after('lunch_out');
            $table->integer('actual_break_minutes')->default(0)->after('lunch_in');
            $table->boolean('is_night_shift')->default(false)->after('is_weekend_work');
            $table->boolean('qualifies_for_night_shift_bonus')->default(false)->after('is_night_shift');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn([
                'lunch_out',
                'lunch_in',
                'actual_break_minutes',
                'is_night_shift',
                'qualifies_for_night_shift_bonus',
            ]);
        });
    }
};
