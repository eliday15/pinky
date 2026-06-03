<?php

namespace App\Console\Commands;

use App\Models\SyncLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Command to mark stuck sync logs as failed.
 *
 * Sync logs can get stuck in "running" (process killed) or "requested" (remote
 * agent never picked it up) status. This command marks them failed and releases
 * any orphaned scheduler overlap lock so subsequent ticks are not blocked.
 */
class CleanupSyncLogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:cleanup {--minutes=30 : Minutes threshold to consider a sync stuck}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mark stuck sync logs (running for too long) as failed';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');

        // Cover both 'running' (process killed mid-run) and 'requested' (remote
        // agent crashed before starting) — a stuck 'requested' row otherwise blocks
        // every manual sync with a false "already in progress".
        $updated = SyncLog::whereIn('status', ['running', 'requested'])
            ->where('started_at', '<', now()->subMinutes($minutes))
            ->update([
                'status' => 'failed',
                'completed_at' => now(),
                'errors' => json_encode([
                    'message' => "Timeout - proceso excedio {$minutes} minutos",
                ]),
            ]);

        if ($updated > 0) {
            // Belt-and-suspenders lock release. withoutOverlapping(10) already
            // auto-expires the lock, but a killed process can leave it dangling
            // until TTL; clearing it here unblocks the next tick immediately.
            // DB cache driver stores these as '<prefix>framework/schedule-<sha1>'.
            try {
                $released = DB::table('cache_locks')
                    ->where('key', 'like', '%framework/schedule-%')
                    ->delete();
                if ($released > 0) {
                    Log::warning("sync:cleanup released {$released} orphaned scheduler lock(s).");
                }
            } catch (\Throwable $e) {
                Log::warning('sync:cleanup could not release scheduler lock: '.$e->getMessage());
            }

            Log::critical("sync:cleanup marcó {$updated} sync(s) atascado(s) como fallidos (>{$minutes} min). Revisar la sincronización del checador.");
            $this->info("Marcados {$updated} sync logs como fallidos.");
        } else {
            $this->info('No se encontraron sync logs atascados.');
        }

        return Command::SUCCESS;
    }
}
