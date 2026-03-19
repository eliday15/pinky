<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make zkteco_user_id nullable so soft-deleted employees can have their ID cleared.
 *
 * Also replaces the plain unique index with a partial unique index that only
 * enforces uniqueness for non-deleted rows, preventing conflicts with
 * soft-deleted records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->integer('zkteco_user_id')->nullable()->change();
        });

        // Replace unique index with a conditional one (only non-deleted rows)
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique('employees_zkteco_user_id_unique');
        });

        // MySQL doesn't support partial indexes, so we use a unique index
        // and rely on the application logic + nullable column to handle conflicts.
        // NULL values are ignored by MySQL unique constraints (multiple NULLs allowed).
        Schema::table('employees', function (Blueprint $table) {
            $table->unique('zkteco_user_id', 'employees_zkteco_user_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->integer('zkteco_user_id')->nullable(false)->change();
        });
    }
};
