<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Updates the authorizations table to include:
     * - 'holiday_worked' in the type enum
     * - 'paid' in the status enum
     */
    public function up(): void
    {
        // For SQLite, we need to recreate the table with the new enum values
        // For MySQL, we can alter the column directly

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite doesn't support ENUM, it uses CHECK constraints
            // The column is likely stored as TEXT, so no migration needed for SQLite
            // The validation is handled at the application level
            return;
        }

        // MySQL/MariaDB: Alter the ENUM columns
        DB::statement("ALTER TABLE authorizations MODIFY COLUMN type ENUM(
            'overtime',
            'night_shift',
            'exit_permission',
            'entry_permission',
            'schedule_change',
            'holiday_worked',
            'special'
        ) NOT NULL");

        DB::statement("ALTER TABLE authorizations MODIFY COLUMN status ENUM(
            'pending',
            'approved',
            'rejected',
            'paid'
        ) DEFAULT 'pending'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        // First update any 'paid' status back to 'approved' before changing the enum
        DB::table('authorizations')
            ->where('status', 'paid')
            ->update(['status' => 'approved']);

        // Then update any 'holiday_worked' type back to 'special'
        DB::table('authorizations')
            ->where('type', 'holiday_worked')
            ->update(['type' => 'special']);

        // Revert to original ENUM values
        DB::statement("ALTER TABLE authorizations MODIFY COLUMN type ENUM(
            'overtime',
            'night_shift',
            'exit_permission',
            'entry_permission',
            'schedule_change',
            'special'
        ) NOT NULL");

        DB::statement("ALTER TABLE authorizations MODIFY COLUMN status ENUM(
            'pending',
            'approved',
            'rejected'
        ) DEFAULT 'pending'");
    }
};
