<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        if (DB::getDriverName() === 'sqlite') {
            // SQLite enforces enums via a CHECK constraint, so the column must be
            // recreated as plain TEXT to allow the new 'requested' value. The
            // dependent index must be dropped first and recreated afterwards.
            Schema::table('sync_logs', fn (Blueprint $t) => $t->dropIndex('idx_sync_logs_status_completed'));
            DB::statement("ALTER TABLE sync_logs ADD COLUMN status_new TEXT NOT NULL DEFAULT 'requested'");
            DB::statement('UPDATE sync_logs SET status_new = status');
            DB::statement('ALTER TABLE sync_logs DROP COLUMN status');
            DB::statement('ALTER TABLE sync_logs RENAME COLUMN status_new TO status');
            Schema::table('sync_logs', fn (Blueprint $t) => $t->index(['status', 'completed_at'], 'idx_sync_logs_status_completed'));

            return;
        }

        DB::statement("ALTER TABLE sync_logs MODIFY COLUMN status ENUM('requested', 'running', 'completed', 'failed') NOT NULL DEFAULT 'requested'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('sync_logs', fn (Blueprint $t) => $t->dropIndex('idx_sync_logs_status_completed'));
            DB::statement("ALTER TABLE sync_logs ADD COLUMN status_old TEXT NOT NULL DEFAULT 'running'");
            DB::statement('UPDATE sync_logs SET status_old = status');
            DB::statement('ALTER TABLE sync_logs DROP COLUMN status');
            DB::statement('ALTER TABLE sync_logs RENAME COLUMN status_old TO status');
            Schema::table('sync_logs', fn (Blueprint $t) => $t->index(['status', 'completed_at'], 'idx_sync_logs_status_completed'));

            return;
        }

        DB::statement("ALTER TABLE sync_logs MODIFY COLUMN status ENUM('running', 'completed', 'failed') NOT NULL DEFAULT 'running'");
    }
};
