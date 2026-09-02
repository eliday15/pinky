<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compensation_types', function (Blueprint $table) {
            $table->decimal('fixed_amount', 12, 4)->nullable()->change();
        });

        Schema::table('employee_compensation_type', function (Blueprint $table) {
            $table->decimal('custom_fixed_amount', 12, 4)->nullable()->change();
        });

        Schema::table('position_compensation_type', function (Blueprint $table) {
            $table->decimal('default_fixed_amount', 12, 4)->nullable()->change();
        });

        Schema::table('department_compensation_type', function (Blueprint $table) {
            $table->decimal('default_fixed_amount', 12, 4)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('compensation_types', function (Blueprint $table) {
            $table->decimal('fixed_amount', 10, 2)->nullable()->change();
        });

        Schema::table('employee_compensation_type', function (Blueprint $table) {
            $table->decimal('custom_fixed_amount', 10, 2)->nullable()->change();
        });

        Schema::table('position_compensation_type', function (Blueprint $table) {
            $table->decimal('default_fixed_amount', 10, 2)->nullable()->change();
        });

        Schema::table('department_compensation_type', function (Blueprint $table) {
            $table->decimal('default_fixed_amount', 10, 2)->nullable()->change();
        });
    }
};
