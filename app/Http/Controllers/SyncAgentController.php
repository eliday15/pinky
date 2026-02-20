<?php

namespace App\Http\Controllers;

use App\Jobs\SyncZktecoJob;
use App\Models\SyncLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * API controller for the local Python sync agent.
 *
 * The agent runs on a Windows PC in the office LAN, polls these endpoints
 * to discover pending sync requests, and reports results back after
 * fetching data from ZKTeco devices.
 */
class SyncAgentController extends Controller
{
    /**
     * Poll for the oldest pending sync request.
     *
     * Returns the first SyncLog with status 'requested', or null if none.
     * The agent calls this every ~30 seconds.
     *
     * @return JsonResponse
     */
    public function poll(): JsonResponse
    {
        $pending = SyncLog::where('status', 'requested')
            ->orderBy('created_at', 'asc')
            ->first();

        return response()->json([
            'data' => $pending,
        ]);
    }

    /**
     * Mark a sync request as running.
     *
     * Called by the agent when it starts processing a sync request.
     *
     * @param SyncLog $syncLog The sync log to mark as running
     * @return JsonResponse
     */
    public function start(SyncLog $syncLog): JsonResponse
    {
        if ($syncLog->status !== 'requested') {
            return response()->json([
                'error' => "SyncLog #{$syncLog->id} is not in 'requested' status (current: {$syncLog->status}).",
            ], 409);
        }

        $syncLog->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        Log::info("Sync agent started SyncLog #{$syncLog->id}.");

        return response()->json(['data' => $syncLog->fresh()]);
    }

    /**
     * Mark a sync request as done and trigger Laravel-side processing.
     *
     * The agent posts device fetch stats here after writing raw data to MySQL.
     * This dispatches SyncZktecoJob (skip Python) to process the raw
     * data into attendance_records.
     *
     * @param Request $request The incoming request with agent stats
     * @param SyncLog $syncLog The sync log to complete
     * @return JsonResponse
     */
    public function done(Request $request, SyncLog $syncLog): JsonResponse
    {
        if ($syncLog->status !== 'running') {
            return response()->json([
                'error' => "SyncLog #{$syncLog->id} is not in 'running' status (current: {$syncLog->status}).",
            ], 409);
        }

        $validated = $request->validate([
            'success' => ['required', 'boolean'],
            'devices_synced' => ['nullable', 'integer', 'min:0'],
            'devices_failed' => ['nullable', 'integer', 'min:0'],
            'total_users' => ['nullable', 'integer', 'min:0'],
            'total_attendance' => ['nullable', 'integer', 'min:0'],
            'error' => ['nullable', 'string', 'max:2000'],
        ]);

        if (! $validated['success']) {
            $syncLog->update([
                'status' => 'failed',
                'completed_at' => now(),
                'errors' => ['agent_error' => $validated['error'] ?? 'Unknown agent error'],
            ]);

            Log::error("Sync agent reported failure for SyncLog #{$syncLog->id}.", [
                'error' => $validated['error'] ?? 'Unknown',
            ]);

            return response()->json(['data' => $syncLog->fresh()]);
        }

        Log::info("Sync agent completed device fetch for SyncLog #{$syncLog->id}. Dispatching Laravel processing.", [
            'devices_synced' => $validated['devices_synced'] ?? 0,
            'total_attendance' => $validated['total_attendance'] ?? 0,
        ]);

        $syncLog->update([
            'records_fetched' => $validated['total_attendance'] ?? 0,
        ]);

        // Dispatch the job to process raw data → attendance_records (skips Python)
        SyncZktecoJob::dispatch(now()->subDays(7), $syncLog->triggered_by, $syncLog->id);

        return response()->json(['data' => $syncLog->fresh()]);
    }

    /**
     * Receive a heartbeat from the agent.
     *
     * Stores the last heartbeat timestamp in cache so the web UI can
     * show whether the agent is online.
     *
     * @return JsonResponse
     */
    public function heartbeat(): JsonResponse
    {
        Cache::put('sync_agent_last_heartbeat', now()->toIso8601String(), now()->addMinutes(10));

        return response()->json(['data' => ['status' => 'ok']]);
    }
}
