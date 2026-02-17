<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: Add velada (night shift) and authorized overtime fields to payroll entries.
 *
 * Enables tracking of velada hours, their authorized portion,
 * the applicable multiplier, calculated pay, and authorized overtime hours.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payroll_entries', function (Blueprint $table) {
            $table->decimal('velada_hours', 5, 2)->default(0)->after('night_shift_hours');
            $table->decimal('velada_authorized_hours', 5, 2)->default(0)->after('velada_hours');
            $table->decimal('velada_multiplier', 5, 2)->default(2.0)->after('velada_authorized_hours');
            $table->decimal('velada_pay', 10, 2)->default(0)->after('velada_multiplier');
            $table->decimal('overtime_authorized_hours', 5, 2)->default(0)->after('velada_pay');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payroll_entries', function (Blueprint $table) {
            $table->dropColumn([
                'velada_hours',
                'velada_authorized_hours',
                'velada_multiplier',
                'velada_pay',
                'overtime_authorized_hours',
            ]);
        });
    }
};
