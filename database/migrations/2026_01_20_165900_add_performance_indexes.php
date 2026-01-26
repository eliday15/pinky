<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations - Add indexes for better query performance.
     */
    public function up(): void
    {
        // Attendance records indexes
        Schema::table('attendance_records', function (Blueprint $table) {
            // Composite index for common queries
            $table->index(['work_date', 'employee_id'], 'idx_attendance_date_employee');
            $table->index(['work_date', 'status'], 'idx_attendance_date_status');
            $table->index(['employee_id', 'work_date'], 'idx_attendance_employee_date');
        });

        // Employees indexes
        Schema::table('employees', function (Blueprint $table) {
            $table->index('department_id', 'idx_employees_department');
            $table->index('supervisor_id', 'idx_employees_supervisor');
            $table->index('schedule_id', 'idx_employees_schedule');
            $table->index('position_id', 'idx_employees_position');
            $table->index('status', 'idx_employees_status');
            $table->index('zkteco_user_id', 'idx_employees_zkteco');
        });

        // Sync logs index
        Schema::table('sync_logs', function (Blueprint $table) {
            $table->index(['status', 'completed_at'], 'idx_sync_logs_status_completed');
        });

        // Authorizations indexes
        Schema::table('authorizations', function (Blueprint $table) {
            $table->index(['employee_id', 'date'], 'idx_auth_employee_date');
            $table->index(['status', 'date'], 'idx_auth_status_date');
        });

        // Incidents indexes
        Schema::table('incidents', function (Blueprint $table) {
            $table->index(['employee_id', 'start_date'], 'idx_incidents_employee_start');
            $table->index(['status', 'start_date'], 'idx_incidents_status_start');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropIndex('idx_attendance_date_employee');
            $table->dropIndex('idx_attendance_date_status');
            $table->dropIndex('idx_attendance_employee_date');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex('idx_employees_department');
            $table->dropIndex('idx_employees_supervisor');
            $table->dropIndex('idx_employees_schedule');
            $table->dropIndex('idx_employees_position');
            $table->dropIndex('idx_employees_status');
            $table->dropIndex('idx_employees_zkteco');
        });

        Schema::table('sync_logs', function (Blueprint $table) {
            $table->dropIndex('idx_sync_logs_status_completed');
        });

        Schema::table('authorizations', function (Blueprint $table) {
            $table->dropIndex('idx_auth_employee_date');
            $table->dropIndex('idx_auth_status_date');
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->dropIndex('idx_incidents_employee_start');
            $table->dropIndex('idx_incidents_status_start');
        });
    }
};
