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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_number')->unique();
            $table->integer('zkteco_user_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained('app_users')->nullOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('full_name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->date('hire_date');
            $table->date('termination_date')->nullable();
            $table->foreignId('department_id')->constrained();
            $table->foreignId('position_id')->constrained();
            $table->foreignId('schedule_id')->constrained();
            $table->decimal('hourly_rate', 10, 2);
            $table->decimal('overtime_rate', 3, 2)->default(1.50);
            $table->decimal('holiday_rate', 3, 2)->default(2.00);
            $table->integer('vacation_days_entitled')->default(6);
            $table->integer('vacation_days_used')->default(0);
            $table->enum('status', ['active', 'inactive', 'terminated'])->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
