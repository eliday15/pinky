<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('is_active')->constrained()->nullOnDelete();
            $table->foreignId('supervisor_position_id')->nullable()->after('department_id')
                ->constrained('positions')->nullOnDelete();
            $table->foreignId('default_schedule_id')->nullable()->after('supervisor_position_id')
                ->constrained('schedules')->nullOnDelete();
            $table->decimal('default_overtime_rate', 5, 2)->default(1.50)->after('base_hourly_rate');
            $table->decimal('default_holiday_rate', 5, 2)->default(2.00)->after('default_overtime_rate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
            $table->dropForeign(['supervisor_position_id']);
            $table->dropForeign(['default_schedule_id']);
            $table->dropColumn([
                'department_id',
                'supervisor_position_id',
                'default_schedule_id',
                'default_overtime_rate',
                'default_holiday_rate',
            ]);
        });
    }
};
