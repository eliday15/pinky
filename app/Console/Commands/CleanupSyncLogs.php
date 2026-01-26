<?php

namespace App\Console\Commands;

use App\Models\SyncLog;
use Illuminate\Console\Command;

/**
 * Command to mark stuck sync logs as failed.
 *
 * Sync logs can get stuck in "running" status if the process times out
 * or crashes. This command cleans them up by marking them as failed.
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

        $updated = SyncLog::where('status', 'running')
            ->where('started_at', '<', now()->subMinutes($minutes))
            ->update([
                'status' => 'failed',
                'completed_at' => now(),
                'errors' => json_encode([
                    'message' => "Timeout - proceso excedio {$minutes} minutos",
                ]),
            ]);

        if ($updated > 0) {
            $this->info("Marcados {$updated} sync logs como fallidos.");
        } else {
            $this->info("No se encontraron sync logs atascados.");
        }

        return Command::SUCCESS;
    }
}
