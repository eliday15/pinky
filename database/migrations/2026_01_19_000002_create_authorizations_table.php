<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the authorizations table for managing overtime, night shifts,
     * exit/entry permissions, schedule changes, and special authorizations.
     */
    public function up(): void
    {
        Schema::create('authorizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('app_users');
            $table->foreignId('approved_by')->nullable()->constrained('app_users');
            $table->enum('type', [
                'overtime',          // Horas extra
                'night_shift',       // Velada
                'exit_permission',   // Permiso de salida
                'entry_permission',  // Permiso de entrada
                'schedule_change',   // Cambio de horario
                'special',           // Autorización especial
            ]);
            $table->date('date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->decimal('hours', 5, 2)->nullable();
            $table->text('reason');
            $table->string('evidence_path')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->boolean('is_pre_authorization')->default(true);
            $table->foreignId('attendance_record_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            // Index for common queries
            $table->index(['employee_id', 'date']);
            $table->index(['status', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('authorizations');
    }
};
