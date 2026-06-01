<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add a generic "other compensation" pay bucket to payroll entries.
 *
 * Holds the amount of any approved authorization concept that is not
 * overtime/velada/holiday/weekend (e.g. CENA, COM, DOM and future
 * special compensation types), so gross pay stays correct and the
 * breakdown is auditable instead of dumping these into overtime_pay.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payroll_entries', function (Blueprint $table) {
            $table->decimal('other_compensation_pay', 12, 2)->default(0)->after('weekend_pay');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payroll_entries', function (Blueprint $table) {
            $table->dropColumn('other_compensation_pay');
        });
    }
};
