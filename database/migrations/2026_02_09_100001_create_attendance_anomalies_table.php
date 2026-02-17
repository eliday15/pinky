<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2: Create the attendance_anomalies table.
 *
 * Tracks anomalies detected in attendance records such as missing
 * check-ins/checkouts, excessive overtime, schedule deviations, etc.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('attendance_anomalies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_record_id')
                ->nullable()
                ->constrained('attendance_records')
                ->nullOnDelete();
            $table->foreignId('employee_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->date('work_date');
            $table->string('anomaly_type'); // missing_checkout, missing_checkin, excessive_overtime, etc.
            $table->enum('severity', ['info', 'warning', 'critical']);
            $table->text('description');
            $table->string('expected_value')->nullable();
            $table->string('actual_value')->nullable();
            $table->integer('deviation_minutes')->nullable();
            $table->enum('status', ['open', 'resolved', 'dismissed', 'linked_to_authorization'])
                ->default('open');
            $table->foreignId('resolved_by')
                ->nullable()
                ->constrained('app_users')
                ->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->foreignId('linked_authorization_id')
                ->nullable()
                ->constrained('authorizations')
                ->nullOnDelete();
            $table->foreignId('linked_incident_id')
                ->nullable()
                ->constrained('incidents')
                ->nullOnDelete();
            $table->boolean('auto_detected')->default(true);
            $table->timestamps();

            // Indexes for common queries
            $table->index(['employee_id', 'work_date']);
            $table->index(['status', 'severity']);
            $table->index('anomaly_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_anomalies');
    }
};
