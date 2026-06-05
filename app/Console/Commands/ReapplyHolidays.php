<?php

namespace App\Console\Commands;

use App\Models\AttendanceRecord;
use App\Models\Holiday;
use App\Models\PayrollPeriod;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill is_holiday on existing attendance_records and reclassify
 * status='absent' rows that fall on an official holiday to status='holiday'.
 *
 * Needed because the holidays table can be expanded after the fact (e.g. a
 * new year's worth of DOF holidays), and previously synced records may have
 * been marked absent on what is now a registered holiday.
 *
 * La conversión absent→holiday está acotada (auditoría #11 / DECISIONES,
 * derivadas): solo días donde el empleado tenía jornada, sin checada alguna,
 * y cuyo periodo de nómina no esté pagado. Una falta con checadas es un
 * evento real (salida temprana / retardo extremo), una fila en día sin
 * jornada es ruido — ninguna de las dos se debe convertir a ciegas — y un
 * periodo pagado es inmutable.
 */
class ReapplyHolidays extends Command
{
    protected $signature = 'holidays:reapply
        {--from= : Only process records from this date (YYYY-MM-DD), inclusive}
        {--to= : Only process records up to this date (YYYY-MM-DD), inclusive}
        {--dry-run : Show what would change without writing}';

    protected $description = 'Mark is_holiday=true and convert status=absent to holiday on attendance records that match an entry in the holidays table (only scheduled workdays without punches in unpaid periods).';

    public function handle(): int
    {
        $holidayDates = Holiday::pluck('date')->map(fn ($d) => Carbon::parse($d)->toDateString())->all();

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
        $this->line("  - Missing is_holiday flag: {$needsHolidayFlag}");

        // Candidatas a conversión: solo faltas SIN checada alguna. Una falta
        // con check_in/check_out es un evento real de asistencia.
        $absentTotal = (clone $query)->where('status', 'absent')->count();
        $candidates = (clone $query)
            ->where('status', 'absent')
            ->whereNull('check_in')
            ->whereNull('check_out')
            ->with('employee.schedule')
            ->get();

        $paidRanges = PayrollPeriod::where('status', 'paid')->get(['start_date', 'end_date']);

        $skippedNoSchedule = 0;
        $skippedPaidPeriod = 0;

        $convertible = $candidates->filter(function (AttendanceRecord $record) use ($paidRanges, &$skippedNoSchedule, &$skippedPaidPeriod) {
            $date = Carbon::parse($record->work_date);

            // Guard 1: el empleado tenía jornada ese día — si no, la fila
            // 'absent' es ruido de datos, no un festivo que justificar.
            $dayName = strtolower($date->format('l'));
            if (! $record->employee?->isEffectiveWorkingDay($dayName)) {
                $skippedNoSchedule++;

                return false;
            }

            // Guard 2: el periodo de nómina pagado es inmutable.
            $inPaidPeriod = $paidRanges->contains(
                fn ($period) => $date->betweenIncluded(Carbon::parse($period->start_date), Carbon::parse($period->end_date))
            );
            if ($inPaidPeriod) {
                $skippedPaidPeriod++;

                return false;
            }

            return true;
        });

        $skippedWithPunches = $absentTotal - $candidates->count();

        $this->line("  - Marked as 'absent' (will become 'holiday'): {$convertible->count()}");
        if ($skippedWithPunches > 0) {
            $this->line("  - Skipped {$skippedWithPunches} absent record(s) with punches (real attendance events).");
        }
        if ($skippedNoSchedule > 0) {
            $this->line("  - Skipped {$skippedNoSchedule} absent record(s) on non-working days (data noise, not holidays).");
        }
        if ($skippedPaidPeriod > 0) {
            $this->line("  - Skipped {$skippedPaidPeriod} absent record(s) inside PAID payroll periods (immutable).");
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run — no changes written.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($query, $convertible) {
            if ($convertible->isNotEmpty()) {
                AttendanceRecord::whereIn('id', $convertible->pluck('id'))->update(['status' => 'holiday']);
            }
            (clone $query)->where('is_holiday', false)->update(['is_holiday' => true]);
        });

        $this->info('Done. Holiday flags and statuses backfilled.');

        return self::SUCCESS;
    }
}
