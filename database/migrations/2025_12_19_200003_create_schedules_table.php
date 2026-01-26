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
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->time('entry_time');
            $table->time('exit_time');
            $table->time('break_start')->nullable();
            $table->time('break_end')->nullable();
            $table->integer('break_minutes')->default(60);
            $table->integer('late_tolerance_minutes')->default(10);
            $table->integer('daily_work_hours')->default(8);
            $table->boolean('is_flexible')->default(false);
            $table->json('working_days');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
