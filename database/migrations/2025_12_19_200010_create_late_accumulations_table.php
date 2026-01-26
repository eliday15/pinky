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
        Schema::create('late_accumulations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->integer('year');
            $table->integer('week');
            $table->integer('late_count')->default(0);
            $table->boolean('absence_generated')->default(false);
            $table->foreignId('generated_incident_id')->nullable()->constrained('incidents')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'year', 'week']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('late_accumulations');
    }
};
