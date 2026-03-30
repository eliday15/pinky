<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove exit_permission, entry_permission, and schedule_change from the
 * authorizations type ENUM now that they have been migrated to incidents.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Safety check: ensure no remaining rows use the old types
        $remaining = DB::table('authorizations')
            ->whereIn('type', ['exit_permission', 'entry_permission', 'schedule_change'])
            ->count();

        if ($remaining > 0) {
            throw new \RuntimeException(
                "Cannot remove ENUM values: {$remaining} authorizations still use admin types. Run migration 000005 first."
            );
        }

        // MySQL/MariaDB ENUM modification
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE authorizations MODIFY COLUMN type ENUM('overtime', 'night_shift', 'holiday_worked', 'special') NOT NULL");
        }
        // SQLite: ENUM is not enforced, application-level validation is sufficient
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE authorizations MODIFY COLUMN type ENUM('overtime', 'night_shift', 'exit_permission', 'entry_permission', 'schedule_change', 'holiday_worked', 'special') NOT NULL");
        }
    }
};
