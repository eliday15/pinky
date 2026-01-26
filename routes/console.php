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

Schedule::command('zkteco:sync --days=1')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/zkteco-sync.log'));

// Clean up stuck sync logs every hour
Schedule::command('sync:cleanup --minutes=30')
    ->hourly()
    ->appendOutputTo(storage_path('logs/sync-cleanup.log'));
