<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add 'requested' status to sync_logs for remote agent workflow.
     *
     * When the app runs in remote_python mode, a SyncLog is created with
     * status 'requested'. The local Python agent polls for these and
     * transitions them through running → completed/failed.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE sync_logs MODIFY COLUMN status ENUM('requested', 'running', 'completed', 'failed') NOT NULL DEFAULT 'requested'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE sync_logs MODIFY COLUMN status ENUM('running', 'completed', 'failed') NOT NULL DEFAULT 'running'");
    }
};
