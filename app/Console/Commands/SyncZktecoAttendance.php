<?php

namespace App\Console\Commands;

use App\Services\ZktecoSyncService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncZktecoAttendance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attendance:sync
                            {--from= : Start date for sync (YYYY-MM-DD)}
                            {--test : Only test the connection}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync attendance records from ZKTeco devices';

    /**
     * Execute the console command.
     */
    public function handle(ZktecoSyncService $syncService): int
    {
        if ($this->option('test')) {
            return $this->testConnection($syncService);
        }

        $this->info('Starting ZKTeco sync...');

        $fromDate = $this->option('from')
            ? Carbon::parse($this->option('from'))
            : null;

        if ($fromDate) {
            $this->info("Syncing from: {$fromDate->toDateString()}");
        }

        try {
            $log = $syncService->sync($fromDate);

            $this->newLine();
            $this->info('Sync completed successfully!');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Status', $log->status],
                    ['Records Fetched', $log->records_fetched],
                    ['Records Processed', $log->records_processed],
                    ['Records Created', $log->records_created],
                    ['Duration', $log->duration . ' seconds'],
                ]
            );

            if ($log->errors) {
                $this->newLine();
                $this->warn('Errors encountered:');
                foreach ($log->errors as $error) {
                    if (is_array($error)) {
                        $this->error("- Employee {$error['employee_id']} on {$error['date']}: {$error['message']}");
                    } else {
                        $this->error("- {$error}");
                    }
                }
            }

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Sync failed: ' . $e->getMessage());
            $this->newLine();
            $this->error($e->getTraceAsString());

            return self::FAILURE;
        }
    }

    /**
     * Test the ZKTeco database connection.
     */
    private function testConnection(ZktecoSyncService $syncService): int
    {
        $this->info('Testing ZKTeco database connection...');

        $result = $syncService->testConnection();

        if ($result['success']) {
            $this->newLine();
            $this->info('Connection successful!');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Attendance Records', number_format($result['attendance_records'])],
                    ['Unique Users', number_format($result['unique_users'])],
                    ['Devices', $result['devices']],
                    ['Last Record', $result['last_record']],
                ]
            );

            return self::SUCCESS;
        }

        $this->error('Connection failed: ' . $result['error']);
        return self::FAILURE;
    }
}
