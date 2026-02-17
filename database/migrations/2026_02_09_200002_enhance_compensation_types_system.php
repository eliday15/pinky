<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add calculation_type and fixed_amount to compensation_types
        Schema::table('compensation_types', function (Blueprint $table) {
            $table->enum('calculation_type', ['multiplier', 'fixed'])->default('multiplier')->after('description');
            $table->decimal('fixed_amount', 10, 2)->nullable()->after('multiplier');
        });

        // Create department_compensation_type pivot table
        Schema::create('department_compensation_type', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('compensation_type_id')->constrained()->cascadeOnDelete();
            $table->decimal('default_multiplier', 5, 2)->nullable();
            $table->decimal('default_fixed_amount', 10, 2)->nullable();
            $table->timestamps();

            $table->unique(['department_id', 'compensation_type_id'], 'dept_comp_type_unique');
        });

        // Add default_fixed_amount to position_compensation_type
        Schema::table('position_compensation_type', function (Blueprint $table) {
            $table->decimal('default_fixed_amount', 10, 2)->nullable()->after('default_multiplier');
        });

        // Add custom_fixed_amount to employee_compensation_type
        Schema::table('employee_compensation_type', function (Blueprint $table) {
            $table->decimal('custom_fixed_amount', 10, 2)->nullable()->after('custom_multiplier');
        });
    }

    public function down(): void
    {
        Schema::table('employee_compensation_type', function (Blueprint $table) {
            $table->dropColumn('custom_fixed_amount');
        });

        Schema::table('position_compensation_type', function (Blueprint $table) {
            $table->dropColumn('default_fixed_amount');
        });

        Schema::dropIfExists('department_compensation_type');

        Schema::table('compensation_types', function (Blueprint $table) {
            $table->dropColumn(['calculation_type', 'fixed_amount']);
        });
    }
};
