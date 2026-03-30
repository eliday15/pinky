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
        Schema::create('position_incident_type', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_id')->constrained('positions')->cascadeOnDelete();
            $table->foreignId('incident_type_id')->constrained('incident_types')->cascadeOnDelete();
            $table->unique(['position_id', 'incident_type_id'], 'pos_incident_type_unique');
            $table->timestamps();
        });

        Schema::create('department_incident_type', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('incident_type_id')->constrained('incident_types')->cascadeOnDelete();
            $table->unique(['department_id', 'incident_type_id'], 'dept_incident_type_unique');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('department_incident_type');
        Schema::dropIfExists('position_incident_type');
    }
};
