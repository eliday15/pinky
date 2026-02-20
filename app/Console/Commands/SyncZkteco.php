<?php

namespace App\Console\Commands;

use App\Services\ZktecoSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class SyncZkteco extends Command
{
    protected $signature = 'zkteco:sync {--days=7 : Number of days to sync} {--skip-python : Skip Python device sync}';
    protected $description = 'Fetch data from ZKTeco devices and sync attendance records';

    public function handle(ZktecoSyncService $syncService): int
    {
        $days = (int) $this->option('days');
        $fromDate = now()->subDays($days);

        // Step 1: Run Python script to pull from devices
        if (!$this->option('skip-python')) {
            $this->runPythonSync();
        }

        // Step 2: Process raw data into attendance_records
        $this->info("Processing attendance records from {$fromDate->toDateString()}...");

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

    /**
     * Run the Python ZKTeco sync script to pull data from devices.
     */
    private function runPythonSync(): void
    {
        $scriptPath = base_path('pinky_script');
        $pythonBin = $scriptPath . '/venv/bin/python';
        $mainScript = $scriptPath . '/main.py';

        if (!file_exists($pythonBin) || !file_exists($mainScript)) {
            $this->warn('Python script not found, skipping device sync.');
            return;
        }

        $this->info('Fetching data from ZKTeco devices...');

        $result = Process::path($scriptPath)
            ->timeout(300)
            ->run([$pythonBin, $mainScript, '--sync']);

        if ($result->successful()) {
            $this->info('Device sync completed.');
            // Show summary from Python output
            $output = $result->output();
            if (preg_match('/Attendance records: (\d+)/', $output, $matches)) {
                $this->info("  Records fetched from devices: {$matches[1]}");
            }
        } else {
            $this->error('Python device sync failed: ' . $result->errorOutput());
        }
    }
}
