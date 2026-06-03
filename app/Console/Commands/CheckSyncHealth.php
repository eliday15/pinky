<?php

namespace App\Console\Commands;

use App\Models\SyncLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Dead-man's switch for the ZKTeco attendance sync.
 *
 * The sync runs every 5 minutes. If the newest COMPLETED sync is older than the
 * threshold (default 20 min = 4 missed ticks) — or a run is wedged in 'running' —
 * the pipeline is stalled and this command raises a critical alert. Before this
 * existed, a ~6 hour freeze on 2026-06-02 was discovered only via a WhatsApp
 * complaint about missing check-outs.
 */
class CheckSyncHealth extends Command
{
    protected $signature = 'sync:health-check {--minutes=20 : Alert if no completed sync within this many minutes}';

    protected $description = 'Alert when the ZKTeco attendance sync has not completed recently (stall detector)';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');

        // Never let the health probe itself hang.
        try {
            DB::statement('SET SESSION max_execution_time = 10000');
        } catch (\Throwable $e) {
            // non-fatal
        }

        $lastCompleted = SyncLog::where('type', 'zkteco')
            ->where('status', 'completed')
            ->latest('completed_at')
            ->first();

        $stuckRunning = SyncLog::where('type', 'zkteco')
            ->where('status', 'running')
            ->where('started_at', '<', now()->subMinutes(15))
            ->count();

        $ageMinutes = $lastCompleted && $lastCompleted->completed_at
            ? (int) round($lastCompleted->completed_at->diffInMinutes(now()))
            : null;

        $isStale = $ageMinutes === null || $ageMinutes > $minutes;

        if (! $isStale && $stuckRunning === 0) {
            $this->info("Sync OK: última sincronización completada hace {$ageMinutes} min.");

            return Command::SUCCESS;
        }

        $detail = $lastCompleted
            ? "la última sincronización completada (#{$lastCompleted->id}) fue hace {$ageMinutes} min (umbral {$minutes})"
            : 'no hay ninguna sincronización completada registrada';

        $msg = "ALERTA checador: la sincronización de asistencia parece detenida — {$detail}; "
            ."{$stuckRunning} corrida(s) atascada(s) en 'running'.";

        Log::critical($msg);
        $this->error($msg);

        // Best-effort push to Slack when a webhook log channel is configured.
        try {
            if (config('logging.channels.slack.url')) {
                Log::channel('slack')->critical($msg);
            }
        } catch (\Throwable $e) {
            Log::warning('sync:health-check no pudo enviar la alerta a Slack: '.$e->getMessage());
        }

        return Command::FAILURE;
    }
}
