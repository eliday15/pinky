<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FASE 6.3: Add breakfast tracking to attendance records.
 *
 * This migration adds the ability to track whether an employee
 * had breakfast and at what time, for meal allowance calculations.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->boolean('had_breakfast')->default(false)->after('qualifies_for_punctuality_bonus');
            $table->time('breakfast_time')->nullable()->after('had_breakfast');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn(['had_breakfast', 'breakfast_time']);
        });
    }
};
