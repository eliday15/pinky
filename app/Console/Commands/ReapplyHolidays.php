<?php

namespace App\Console\Commands;

use App\Models\AttendanceRecord;
use App\Models\Holiday;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill is_holiday on existing attendance_records and reclassify any
 * status='absent' rows that fall on an official holiday to status='holiday'.
 *
 * Needed because the holidays table can be expanded after the fact (e.g. a
 * new year's worth of DOF holidays), and previously synced records may have
 * been marked absent on what is now a registered holiday.
 */
class ReapplyHolidays extends Command
{
    protected $signature = 'holidays:reapply
        {--from= : Only process records from this date (YYYY-MM-DD), inclusive}
        {--to= : Only process records up to this date (YYYY-MM-DD), inclusive}
        {--dry-run : Show what would change without writing}';

    protected $description = 'Mark is_holiday=true and convert status=absent to holiday on attendance records that match an entry in the holidays table.';

    public function handle(): int
    {
        $holidayDates = Holiday::pluck('date')->map(fn ($d) => \Carbon\Carbon::parse($d)->toDateString())->all();

        if (empty($holidayDates)) {
            $this->warn('No holidays in the holidays table — nothing to apply.');
            return self::SUCCESS;
        }

        $query = AttendanceRecord::query()->whereIn('work_date', $holidayDates);

        if ($from = $this->option('from')) {
            $query->where('work_date', '>=', $from);
        }
        if ($to = $this->option('to')) {
            $query->where('work_date', '<=', $to);
        }

        $totalMatches = (clone $query)->count();
        $this->info("Found {$totalMatches} attendance record(s) on registered holidays.");

        $needsHolidayFlag = (clone $query)->where('is_holiday', false)->count();
        $needsStatusFix = (clone $query)->where('status', 'absent')->count();

        $this->line("  - Missing is_holiday flag: {$needsHolidayFlag}");
        $this->line("  - Marked as 'absent' (will become 'holiday'): {$needsStatusFix}");

        if ($this->option('dry-run')) {
            $this->info('Dry run — no changes written.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($query) {
            (clone $query)->where('status', 'absent')->update(['status' => 'holiday']);
            (clone $query)->where('is_holiday', false)->update(['is_holiday' => true]);
        });

        $this->info('Done. Holiday flags and statuses backfilled.');

        return self::SUCCESS;
    }
}
