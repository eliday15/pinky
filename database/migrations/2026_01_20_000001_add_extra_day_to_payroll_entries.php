<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FASE 3.4: Add extra days field to payroll entries.
 *
 * This migration adds the ability to track extra days worked
 * and their corresponding pay in the payroll system.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payroll_entries', function (Blueprint $table) {
            $table->integer('extra_days')->default(0)->after('late_absences_generated');
            $table->decimal('extra_days_pay', 10, 2)->default(0)->after('extra_days');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payroll_entries', function (Blueprint $table) {
            $table->dropColumn(['extra_days', 'extra_days_pay']);
        });
    }
};
