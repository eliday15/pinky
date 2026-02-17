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
        Schema::create('position_compensation_type', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_id')->constrained()->cascadeOnDelete();
            $table->foreignId('compensation_type_id')->constrained()->cascadeOnDelete();
            $table->decimal('default_multiplier', 5, 2)->nullable();
            $table->timestamps();

            $table->unique(['position_id', 'compensation_type_id'], 'pos_comp_type_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('position_compensation_type');
    }
};
