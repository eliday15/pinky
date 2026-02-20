<?php

/**
 * API routes for the local Python sync agent.
 *
 * These endpoints allow the agent running on the office PC to:
 * - Poll for pending sync requests
 * - Report sync start/completion
 * - Send heartbeats so the web UI knows the agent is alive
 */

use App\Http\Controllers\SyncAgentController;
use App\Http\Middleware\SyncAgentAuth;
use Illuminate\Support\Facades\Route;

Route::middleware(SyncAgentAuth::class)->prefix('sync-agent')->group(function () {
    Route::get('/poll', [SyncAgentController::class, 'poll']);
    Route::post('/{syncLog}/start', [SyncAgentController::class, 'start']);
    Route::post('/{syncLog}/done', [SyncAgentController::class, 'done']);
    Route::post('/heartbeat', [SyncAgentController::class, 'heartbeat']);
});
