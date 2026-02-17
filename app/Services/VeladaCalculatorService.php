<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\Employee;
use App\Models\Schedule;
use App\Models\SystemSetting;
use Carbon\Carbon;

/**
 * Service for calculating velada (night shift overtime after midnight).
 *
 * Logic:
 * - Regular hours = within scheduled shift
 * - Overtime = extra hours before midnight (or before velada start)
 * - Velada = hours worked after midnight (or after velada start hour)
 * - Only authorized hours get paid
 */
class VeladaCalculatorService
{
    /**
     * Calculate overtime and velada split for an attendance record.
     *
     * @param AttendanceRecord $record The attendance record to calculate for
     * @param Employee $employee The employee who owns the record
     * @return array{overtime_hours: float, velada_hours: float, overtime_authorized: float, velada_authorized: float}
     */
    public function calculate(AttendanceRecord $record, Employee $employee): array
    {
        $schedule = $employee->schedule;
        if (!$schedule || !$record->check_in || !$record->check_out) {
            return [
                'overtime_hours' => 0,
                'velada_hours' => 0,
                'overtime_authorized' => 0,
                'velada_authorized' => 0,
            ];
        }

        $veladaStartHour = (int) SystemSetting::get('velada_detection_start_hour', 0);
        $veladaEndHour = (int) SystemSetting::get('velada_detection_end_hour', 6);

        $dateStr = $record->work_date->toDateString();
        $checkIn = Carbon::parse($dateStr . ' ' . Carbon::parse($record->check_in)->format('H:i:s'));
        $checkOut = Carbon::parse($dateStr . ' ' . Carbon::parse($record->check_out)->format('H:i:s'));

        // Handle midnight crossing
        if ($checkOut->lt($checkIn)) {
            $checkOut->addDay();
        }

        // Get per-day schedule overrides
        $dayName = strtolower(Carbon::parse($record->work_date)->format('l'));
        $daySchedule = $schedule->getScheduleForDay($dayName);

        $scheduledExit = Carbon::parse($dateStr . ' ' . Carbon::parse($daySchedule->exit_time)->format('H:i:s'));
        if ($scheduledExit->lt($checkIn)) {
            $scheduledExit->addDay();
        }

        $dailyHours = $daySchedule->daily_work_hours ?? 8;
        $totalWorkedMinutes = $checkIn->diffInMinutes($checkOut);

        // Subtract break
        $breakMinutes = $record->actual_break_minutes ?: ($totalWorkedMinutes > 300 ? ($daySchedule->break_minutes ?? 60) : 0);
        $netWorkedMinutes = max(0, $totalWorkedMinutes - $breakMinutes);
        $netWorkedHours = $netWorkedMinutes / 60;

        $extraHours = max(0, $netWorkedHours - $dailyHours);

        if ($extraHours <= 0) {
            return [
                'overtime_hours' => 0,
                'velada_hours' => 0,
                'overtime_authorized' => 0,
                'velada_authorized' => 0,
            ];
        }

        // Split extra hours into overtime vs velada
        // Velada = hours worked in the window [veladaStartHour, veladaEndHour) of the NEXT day
        $midnight = $checkIn->copy()->addDay()->startOfDay();
        $veladaStart = $midnight->copy()->hour($veladaStartHour);
        $veladaEnd = $midnight->copy()->hour($veladaEndHour);

        $overtimeHours = 0;
        $veladaHours = 0;

        if ($checkOut->gt($veladaStart) && $checkOut->lte($veladaEnd)) {
            // Part of the work falls in velada window
            $veladaMinutes = $veladaStart->diffInMinutes($checkOut);
            $veladaHours = min($extraHours, $veladaMinutes / 60);
            $overtimeHours = max(0, $extraHours - $veladaHours);
        } elseif ($checkOut->gt($veladaEnd)) {
            // Worked past velada window
            $veladaMinutes = $veladaStartHour === $veladaEndHour ? 0 : ($veladaEndHour - $veladaStartHour) * 60;
            $veladaHours = min($extraHours, $veladaMinutes / 60);
            $overtimeHours = max(0, $extraHours - $veladaHours);
        } else {
            // All extra is regular overtime (before midnight/velada window)
            $overtimeHours = $extraHours;
        }

        // Check authorized hours
        $overtimeAuthorized = $this->getAuthorizedHours($record->employee_id, $record->work_date, Authorization::TYPE_OVERTIME);
        $veladaAuthorized = $this->getAuthorizedHours($record->employee_id, $record->work_date, Authorization::TYPE_NIGHT_SHIFT);

        return [
            'overtime_hours' => round($overtimeHours, 2),
            'velada_hours' => round($veladaHours, 2),
            'overtime_authorized' => round(min($overtimeHours, $overtimeAuthorized), 2),
            'velada_authorized' => round(min($veladaHours, $veladaAuthorized), 2),
        ];
    }

    /**
     * Get total authorized hours for a type on a date.
     *
     * @param int $employeeId The employee ID
     * @param mixed $date The date to check
     * @param string $type The authorization type
     * @return float Total authorized hours
     */
    private function getAuthorizedHours(int $employeeId, $date, string $type): float
    {
        return (float) Authorization::where('employee_id', $employeeId)
            ->where('date', $date)
            ->where('type', $type)
            ->whereIn('status', [Authorization::STATUS_APPROVED, Authorization::STATUS_PAID])
            ->sum('hours');
    }
}
