<?php

namespace App\Console\Commands;

use App\Services\ZktecoSyncService;
use Illuminate\Console\Command;

class SyncZkteco extends Command
{
    protected $signature = 'zkteco:sync {--days=7 : Number of days to sync}';
    protected $description = 'Sync employees and attendance from ZKTeco database';

    public function handle(ZktecoSyncService $syncService): int
    {
        $days = (int) $this->option('days');
        $fromDate = now()->subDays($days);

        $this->info("Syncing ZKTeco data from {$fromDate->toDateString()}...");

        try {
            $log = $syncService->sync($fromDate);

            $this->info("Sync completed successfully!");
            $this->newLine();
            $this->info("Employee Statistics:");
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Employees Imported', $log->employees_imported ?? 0],
                    ['Employees Updated', $log->employees_updated ?? 0],
                    ['Employees Marked Inactive', $log->employees_marked_inactive ?? 0],
                ]
            );
            $this->newLine();
            $this->info("Attendance Statistics:");
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Records Fetched', $log->records_fetched ?? 0],
                    ['Records Processed', $log->records_processed ?? 0],
                    ['Records Created', $log->records_created ?? 0],
                    ['Status', $log->status],
                ]
            );

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Sync failed: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
