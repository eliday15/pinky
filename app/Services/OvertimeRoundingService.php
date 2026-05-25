<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use Carbon\Carbon;

/**
 * Company rounding rule for overtime detection.
 *
 * Used by both the bulk authorization "Cargar desde checadas" flow and the
 * weekly overtime report, so the numbers a supervisor sees in the report
 * match what gets pre-filled when they authorize.
 *
 * Rounding ladder (minutes worked outside schedule → authorizable hours):
 *   <30     → 0      (not OT)
 *   30–49   → 0.5h
 *   50–59   → 1.0h
 *   then repeats every hour: hh:00–29 → hh, hh:30–49 → hh+0.5, hh:50–59 → hh+1
 */
class OvertimeRoundingService
{
    /**
     * Round a minutes count to authorizable hours using the company ladder.
     */
    public function roundMinutes(int $minutes): float
    {
        if ($minutes < 30) {
            return 0.0;
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        if ($m < 30) {
            return (float) $h;
        }
        if ($m < 50) {
            return $h + 0.5;
        }
        return (float) ($h + 1);
    }

    /**
     * Detect rounded overtime hours for a (record, schedule, date) tuple.
     *
     * Sums both segments — early-arrival (check_in before scheduled entry)
     * and late-exit (check_out after scheduled exit) — each rounded with the
     * company rule. Segments that round to zero are dropped.
     *
     * When there is no schedule reference at all, falls back to the record's
     * precomputed overtime_hours.
     */
    public function detectOvertimeHours(AttendanceRecord $record, ?object $schedule, string $date): float
    {
        if (! $record->check_in || ! $record->check_out) {
            return 0.0;
        }

        $checkIn = Carbon::parse($date . ' ' . $record->check_in);
        $checkOut = Carbon::parse($date . ' ' . $record->check_out);

        $scheduledEntry = $schedule->entry_time ?? null;
        $scheduledExit = $schedule->exit_time ?? null;

        if (! $scheduledEntry && ! $scheduledExit) {
            $raw = (float) ($record->overtime_hours ?? 0);
            return $this->roundMinutes((int) round($raw * 60));
        }

        $total = 0.0;

        if ($scheduledEntry) {
            $scheduledEntryDt = Carbon::parse($date . ' ' . $scheduledEntry);
            if ($checkIn->lt($scheduledEntryDt)) {
                // abs() defensively — Carbon 3 diffInMinutes is signed.
                $earlyMinutes = abs((int) $checkIn->diffInMinutes($scheduledEntryDt));
                $total += $this->roundMinutes($earlyMinutes);
            }
        }

        if ($scheduledExit) {
            $scheduledExitDt = Carbon::parse($date . ' ' . $scheduledExit);
            if ($checkOut->gt($scheduledExitDt)) {
                $lateMinutes = abs((int) $scheduledExitDt->diffInMinutes($checkOut));
                $total += $this->roundMinutes($lateMinutes);
            }
        }

        return $total;
    }
}
