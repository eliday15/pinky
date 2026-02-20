<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Refactor compensation types from multiplier-based to percentage/fixed system.
 *
 * Changes:
 * - calculation_type enum: 'multiplier|fixed' -> 'fixed|percentage'
 * - Renames 'multiplier' column to 'percentage_value'
 * - Renames pivot columns: custom_multiplier -> custom_percentage, default_multiplier -> default_percentage
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Convert existing multiplier values to percentage equivalents
        // A multiplier of 1.50 = 50% of salary, 2.00 = 100% of salary
        // Formula: percentage_value = (multiplier - 1) * 100
        // But for this system, percentage means "X% of daily_salary" directly
        // So multiplier 1.5 -> 50%, multiplier 2.0 -> 100%, multiplier 3.0 -> 200%

        // Add new percentage_value column
        Schema::table('compensation_types', function (Blueprint $table) {
            $table->decimal('percentage_value', 5, 2)->nullable()->after('fixed_amount');
        });

        // Migrate multiplier data to percentage_value
        DB::table('compensation_types')
            ->where('calculation_type', 'multiplier')
            ->whereNotNull('multiplier')
            ->update([
                'percentage_value' => DB::raw('(multiplier - 1) * 100'),
            ]);

        if (DB::getDriverName() === 'sqlite') {
            // SQLite: enum() creates a CHECK constraint that blocks new values.
            // Recreate the column as plain TEXT to allow 'percentage'.
            DB::statement("ALTER TABLE compensation_types ADD COLUMN calculation_type_new TEXT NOT NULL DEFAULT 'percentage'");
            DB::statement("UPDATE compensation_types SET calculation_type_new = CASE WHEN calculation_type = 'multiplier' THEN 'percentage' ELSE calculation_type END");
            DB::statement('ALTER TABLE compensation_types DROP COLUMN calculation_type');
            DB::statement('ALTER TABLE compensation_types RENAME COLUMN calculation_type_new TO calculation_type');
        } else {
            // MySQL: first expand ENUM to include 'percentage', then update values, then shrink
            DB::statement("ALTER TABLE compensation_types MODIFY calculation_type ENUM('multiplier', 'fixed', 'percentage') NOT NULL DEFAULT 'multiplier'");

            DB::table('compensation_types')
                ->where('calculation_type', 'multiplier')
                ->update(['calculation_type' => 'percentage']);

            DB::statement("ALTER TABLE compensation_types MODIFY calculation_type ENUM('fixed', 'percentage') NOT NULL DEFAULT 'percentage'");
        }

        // Drop old multiplier column
        Schema::table('compensation_types', function (Blueprint $table) {
            $table->dropColumn('multiplier');
        });

        // 2. Rename pivot columns in employee_compensation_type
        Schema::table('employee_compensation_type', function (Blueprint $table) {
            $table->renameColumn('custom_multiplier', 'custom_percentage');
        });

        // 3. Rename pivot columns in position_compensation_type
        Schema::table('position_compensation_type', function (Blueprint $table) {
            $table->renameColumn('default_multiplier', 'default_percentage');
        });

        // 4. Rename pivot columns in department_compensation_type
        Schema::table('department_compensation_type', function (Blueprint $table) {
            $table->renameColumn('default_multiplier', 'default_percentage');
        });
    }

    public function down(): void
    {
        // 4. Rename back pivot columns in department_compensation_type
        Schema::table('department_compensation_type', function (Blueprint $table) {
            $table->renameColumn('default_percentage', 'default_multiplier');
        });

        // 3. Rename back pivot columns in position_compensation_type
        Schema::table('position_compensation_type', function (Blueprint $table) {
            $table->renameColumn('default_percentage', 'default_multiplier');
        });

        // 2. Rename back pivot columns in employee_compensation_type
        Schema::table('employee_compensation_type', function (Blueprint $table) {
            $table->renameColumn('custom_percentage', 'custom_multiplier');
        });

        // 1. Add multiplier column back
        Schema::table('compensation_types', function (Blueprint $table) {
            $table->decimal('multiplier', 5, 2)->default(1.00)->after('description');
        });

        // Convert percentage_value back to multiplier
        DB::table('compensation_types')
            ->where('calculation_type', 'percentage')
            ->whereNotNull('percentage_value')
            ->update([
                'multiplier' => DB::raw('(percentage_value / 100) + 1'),
            ]);

        if (DB::getDriverName() === 'sqlite') {
            DB::statement("ALTER TABLE compensation_types ADD COLUMN calculation_type_old TEXT NOT NULL DEFAULT 'multiplier'");
            DB::statement("UPDATE compensation_types SET calculation_type_old = CASE WHEN calculation_type = 'percentage' THEN 'multiplier' ELSE calculation_type END");
            DB::statement('ALTER TABLE compensation_types DROP COLUMN calculation_type');
            DB::statement('ALTER TABLE compensation_types RENAME COLUMN calculation_type_old TO calculation_type');
        } else {
            DB::table('compensation_types')
                ->where('calculation_type', 'percentage')
                ->update(['calculation_type' => 'multiplier']);

            DB::statement("ALTER TABLE compensation_types MODIFY calculation_type ENUM('multiplier', 'fixed') NOT NULL DEFAULT 'multiplier'");
        }

        Schema::table('compensation_types', function (Blueprint $table) {
            $table->dropColumn('percentage_value');
        });
    }
};
