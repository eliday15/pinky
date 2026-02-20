<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add anchor employee to positions.
 *
 * The anchor employee is a reference employee whose compensation
 * configuration (hourly rate, compensation types) serves as a template
 * when creating new employees for this position.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->foreignId('anchor_employee_id')->nullable()->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropForeign(['anchor_employee_id']);
            $table->dropColumn('anchor_employee_id');
        });
    }
};
