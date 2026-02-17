<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2: Add anomaly tracking fields to attendance_records.
 *
 * Adds velada hours, authorized hours tracking, and anomaly
 * summary fields to enable quick filtering of records with issues.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->decimal('velada_hours', 5, 2)->default(0)->after('overtime_hours');
            $table->decimal('overtime_authorized_hours', 5, 2)->default(0)->after('velada_hours');
            $table->decimal('velada_authorized_hours', 5, 2)->default(0)->after('overtime_authorized_hours');
            $table->boolean('has_anomalies')->default(false);
            $table->integer('anomaly_count')->default(0);
            $table->integer('lunch_deviation_minutes')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn([
                'velada_hours',
                'overtime_authorized_hours',
                'velada_authorized_hours',
                'has_anomalies',
                'anomaly_count',
                'lunch_deviation_minutes',
            ]);
        });
    }
};
