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
 * - Overtime is rounded with the company ladder (OvertimeRoundingService)
 *   BEFORE capping at the authorized hours (DECISIONES_NEGOCIO §10), so the
 *   weekly report and payroll always use the same rounded figure.
 */
class VeladaCalculatorService
{
    public function __construct(
        private readonly OvertimeRoundingService $rounding = new OvertimeRoundingService(),
    ) {}

    /**
     * Calculate overtime and velada split for an attendance record.
     *
     * @param AttendanceRecord $record The attendance record to calculate for
     * @param Employee $employee The employee who owns the record
     * @return array{overtime_hours: float, velada_hours: float, overtime_authorized: float, velada_authorized: float}
     */
    public function calculate(AttendanceRecord $record, Employee $employee): array
    {
        if (!$employee->schedule || !$record->check_in || !$record->check_out) {
            return [
                'overtime_hours' => 0,
                'velada_hours' => 0,
                'overtime_authorized' => 0,
                'velada_authorized' => 0,
            ];
        }

        // Defaults match the seeded/migrated production values (22:00–05:00) and
        // the AuthorizationController velada detection, so an unset setting (e.g.
        // a fresh DB) falls back to the same window everywhere instead of the
        // stale pre-migration 0:00–6:00 split.
        $veladaStartHour = (int) SystemSetting::get('velada_detection_start_hour', 22);
        $veladaEndHour = (int) SystemSetting::get('velada_detection_end_hour', 5);

        $dateStr = $record->work_date->toDateString();
        $checkIn = Carbon::parse($dateStr . ' ' . Carbon::parse($record->check_in)->format('H:i:s'));
        $checkOut = Carbon::parse($dateStr . ' ' . Carbon::parse($record->check_out)->format('H:i:s'));

        // Handle midnight crossing
        if ($checkOut->lt($checkIn)) {
            $checkOut->addDay();
        }

        // Get per-day schedule with employee overrides applied
        $dayName = strtolower(Carbon::parse($record->work_date)->format('l'));
        $daySchedule = $employee->getEffectiveScheduleForDay($dayName);

        $scheduledExit = Carbon::parse($dateStr . ' ' . Carbon::parse($daySchedule->exit_time)->format('H:i:s'));
        if ($scheduledExit->lt($checkIn)) {
            $scheduledExit->addDay();
        }

        $dailyHours = $daySchedule->daily_work_hours ?? 8;
        $totalWorkedMinutes = abs($checkIn->diffInMinutes($checkOut));

        // Subtract break (fallback: schedule -> department -> 60)
        $departmentBreak = $employee->department?->default_break_minutes;
        $breakMinutes = $record->actual_break_minutes ?: ($totalWorkedMinutes > 300 ? ($daySchedule->break_minutes ?? $departmentBreak ?? 60) : 0);
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
        // Velada window: [veladaStartHour, veladaEndHour)
        // When start > end (e.g., 22:00-05:00), window crosses midnight
        if ($veladaStartHour > $veladaEndHour) {
            // Window crosses midnight: start on work_date, end on work_date+1
            $veladaStart = Carbon::parse($dateStr)->hour($veladaStartHour)->minute(0)->second(0);
            $veladaEnd = Carbon::parse($dateStr)->addDay()->hour($veladaEndHour)->minute(0)->second(0);
        } elseif ($veladaStartHour === $veladaEndHour) {
            // No velada window configured
            $veladaStart = null;
            $veladaEnd = null;
        } else {
            // Window within same day after midnight (e.g., 00:00-06:00)
            $midnight = $checkIn->copy()->addDay()->startOfDay();
            $veladaStart = $midnight->copy()->hour($veladaStartHour);
            $veladaEnd = $midnight->copy()->hour($veladaEndHour);
        }

        $overtimeHours = 0;
        $veladaHours = 0;

        if (!$veladaStart || !$veladaEnd) {
            // No velada window — all extra is overtime
            $overtimeHours = $extraHours;
        } elseif ($checkOut->gt($veladaStart) && $checkOut->lte($veladaEnd)) {
            // Part of the work falls in velada window
            $veladaMinutes = abs($veladaStart->diffInMinutes($checkOut));
            $veladaHours = min($extraHours, $veladaMinutes / 60);
            $overtimeHours = max(0, $extraHours - $veladaHours);
        } elseif ($checkOut->gt($veladaEnd)) {
            // Worked past velada window
            $veladaMinutes = abs($veladaStart->diffInMinutes($veladaEnd));
            $veladaHours = min($extraHours, $veladaMinutes / 60);
            $overtimeHours = max(0, $extraHours - $veladaHours);
        } else {
            // All extra is regular overtime (before velada window)
            $overtimeHours = $extraHours;
        }

        // Check authorized hours (fecha como string: comparar un DATE contra
        // un Carbon datetime pierde filas en el límite — quirk de SQLite).
        $overtimeAuthorized = $this->getAuthorizedHours($record->employee_id, $dateStr, Authorization::TYPE_OVERTIME);
        $veladaAuthorized = $this->getAuthorizedHours($record->employee_id, $dateStr, Authorization::TYPE_NIGHT_SHIFT);

        // Escalera de redondeo al PAGO (DECISIONES_NEGOCIO §10): las horas
        // extra se redondean con la regla de la empresa (<30min→0,
        // 30-49→0.5h, 50-59→1h) ANTES de topar a lo autorizado — la misma
        // escalera del reporte semanal, para que reporte y nómina nunca
        // diverjan. La velada se paga por horas exactas en ventana (VEL).
        $overtimePayable = $this->rounding->roundMinutes((int) round($overtimeHours * 60));

        return [
            'overtime_hours' => round($overtimeHours, 2),
            'velada_hours' => round($veladaHours, 2),
            'overtime_authorized' => round(min($overtimePayable, $overtimeAuthorized), 2),
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
            ->whereDate('date', Carbon::parse($date)->toDateString())
            ->where('type', $type)
            ->whereIn('status', [Authorization::STATUS_APPROVED, Authorization::STATUS_PAID])
            ->sum('hours');
    }
}
