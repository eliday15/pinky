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

/**
 * Background job for syncing ZKTeco attendance data.
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
     */
    public function __construct(
        public ?Carbon $fromDate = null,
        public ?int $triggeredBy = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ZktecoSyncService $syncService): void
    {
        $syncService->sync($this->fromDate, $this->triggeredBy);
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return 'zkteco-sync';
    }
}
