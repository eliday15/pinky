<?php

namespace App\Jobs;

use App\Services\ZktecoSyncService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Background job for syncing ZKTeco attendance data.
 *
 * Step 1: Runs the Python pyzk script to pull data from ZKTeco devices into MySQL.
 * Step 2: Runs the Laravel sync service to process raw data into attendance_records.
 *
 * Implements ShouldBeUnique to prevent multiple sync jobs from running
 * simultaneously.
 */
class SyncZktecoJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds after which the job's unique lock will be released.
     */
    public int $uniqueFor = 1800; // 30 minutes

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 600; // 10 minutes

    /**
     * Create a new job instance.
     *
     * @param Carbon|null $fromDate Start date for attendance sync
     * @param int|null $triggeredBy User ID who triggered the sync
     * @param int|null $syncLogId Existing SyncLog ID (remote agent mode)
     */
    public function __construct(
        public ?Carbon $fromDate = null,
        public ?int $triggeredBy = null,
        public ?int $syncLogId = null,
    ) {}

    /**
     * Execute the job.
     *
     * In local mode: runs the Python script then processes with Laravel.
     * In remote mode (syncLogId set): skips Python — the agent already
     * fetched device data — and only runs Laravel processing.
     */
    public function handle(ZktecoSyncService $syncService): void
    {
        $skipPython = config('zkteco.sync.remote_python') || $this->syncLogId !== null;

        if (! $skipPython) {
            $this->runPythonSync();
        }

        $syncService->sync($this->fromDate, $this->triggeredBy, $this->syncLogId);
    }

    /**
     * Run the Python ZKTeco sync script to pull data from devices.
     *
     * Executes the pyzk script which connects to all configured ZKTeco
     * devices and inserts raw attendance/user records into MySQL.
     */
    private function runPythonSync(): void
    {
        $scriptPath = base_path('pinky_script');
        $pythonBin = $scriptPath . '/venv/bin/python';
        $mainScript = $scriptPath . '/main.py';

        if (!file_exists($pythonBin) || !file_exists($mainScript)) {
            Log::warning('ZKTeco Python script not found, skipping device sync.', [
                'python_bin' => $pythonBin,
                'main_script' => $mainScript,
            ]);
            return;
        }

        Log::info('ZKTeco Sync: Running Python script to fetch data from devices...');

        $result = Process::path($scriptPath)
            ->timeout(300)
            ->run([$pythonBin, $mainScript, '--sync']);

        if ($result->successful()) {
            Log::info('ZKTeco Sync: Python script completed successfully.', [
                'output' => $result->output(),
            ]);
        } else {
            Log::error('ZKTeco Sync: Python script failed.', [
                'exit_code' => $result->exitCode(),
                'output' => $result->output(),
                'error' => $result->errorOutput(),
            ]);
        }
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return 'zkteco-sync';
    }
}
