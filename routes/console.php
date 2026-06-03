<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
|
| ZKTeco sync runs every 5 minutes to keep attendance data up-to-date.
| To enable, add this cron entry to your server:
| * * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
|
*/

$syncCommand = config('zkteco.sync.remote_python')
    ? 'zkteco:sync --days=7 --skip-python'
    : 'zkteco:sync --days=7';

// withoutOverlapping(10): the overlap lock auto-expires after 10 minutes so a
// hung or killed run cannot block every subsequent sync for Laravel's 24h
// default. A normal sync finishes in <1 min, so 10 min is a safe "dead run"
// bound. (A stuck 24h lock once froze attendance processing for ~6 hours.)
Schedule::command($syncCommand)
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->appendOutputTo(storage_path('logs/zkteco-sync.log'));

// Mark stuck sync logs failed + release orphaned locks. Runs every 10 min (not
// hourly) so a wedged run is reaped quickly instead of lingering up to an hour.
Schedule::command('sync:cleanup --minutes=15')
    ->everyTenMinutes()
    ->appendOutputTo(storage_path('logs/sync-cleanup.log'));

// Dead-man's switch: alert (Log::critical -> stderr/Coolify logs + Slack if set)
// when no sync has completed in 20 min. The 2026-06-02 ~6h freeze went unnoticed
// until a WhatsApp complaint; this surfaces a stall within ~20 min instead.
Schedule::command('sync:health-check --minutes=20')
    ->everyTenMinutes()
    ->appendOutputTo(storage_path('logs/sync-health.log'));
