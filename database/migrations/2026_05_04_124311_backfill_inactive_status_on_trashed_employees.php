<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill: any soft-deleted employee that still says status='active'
     * gets flipped to 'inactive'. The softDeleted callback now sets this
     * automatically, but rows deleted before the callback existed need
     * a one-time pass.
     */
    public function up(): void
    {
        $count = DB::table('employees')
            ->whereNotNull('deleted_at')
            ->where('status', 'active')
            ->update(['status' => 'inactive']);

        if ($count > 0) {
            echo "Updated {$count} soft-deleted employees from active → inactive.\n";
        }
    }

    public function down(): void
    {
        // No safe down: we cannot tell which rows we changed.
    }
};
