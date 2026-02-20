<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add manual edit tracking fields to attendance_records.
 *
 * When an admin/rrhh manually edits an attendance record, we need to
 * record who edited it, when, and why (required reason).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->foreignId('manually_edited_by')->nullable()->constrained('app_users')->nullOnDelete();
            $table->timestamp('manually_edited_at')->nullable();
            $table->string('manual_edit_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropForeign(['manually_edited_by']);
            $table->dropColumn(['manually_edited_by', 'manually_edited_at', 'manual_edit_reason']);
        });
    }
};
