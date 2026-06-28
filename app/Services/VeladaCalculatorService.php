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

        // Ventana de velada en minutos del día. Por departamento si está
        // configurada (p. ej. BIES 15:30–22:30), si no la global (22:00–05:00,
        // que coincide con la AuthorizationController y el valor migrado/sembrado
        // para que una BD nueva use la misma ventana en todos lados).
        [$veladaStartMin, $veladaEndMin] = $this->resolveVeladaWindow($employee);

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

        // Split extra hours into overtime vs velada. Velada window [start, end):
        if ($veladaStartMin === $veladaEndMin) {
            // No velada window configured.
            $veladaStart = null;
            $veladaEnd = null;
        } elseif ($veladaStartMin > $veladaEndMin) {
            // Crosses midnight (e.g., 22:00–05:00): start on work_date, end +1 día.
            $veladaStart = Carbon::parse($dateStr)->startOfDay()->addMinutes($veladaStartMin);
            $veladaEnd = Carbon::parse($dateStr)->startOfDay()->addDay()->addMinutes($veladaEndMin);
        } else {
            // Ventana del mismo día (BIES 15:30–22:30, o el legado 00:00–06:00).
            // Se ancla a la fecha del check-in; si toda la ventana termina antes
            // del check-in (un 00:00–06:00 para un turno que empezó la noche
            // anterior), pertenece al día siguiente.
            $veladaStart = Carbon::parse($dateStr)->startOfDay()->addMinutes($veladaStartMin);
            $veladaEnd = Carbon::parse($dateStr)->startOfDay()->addMinutes($veladaEndMin);
            if ($veladaEnd->lte($checkIn)) {
                $veladaStart->addDay();
                $veladaEnd->addDay();
            }
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
     * Resolve the velada window for an employee, in minutes-of-day [start, end].
     * Per-department when the department defines velada_start/velada_end
     * (e.g. BIES 15:30–22:30); otherwise the global setting (default 22:00–05:00).
     *
     * @return array{0: int, 1: int}
     */
    private function resolveVeladaWindow(Employee $employee): array
    {
        $dept = $employee->department;
        if ($dept && $dept->velada_start && $dept->velada_end) {
            return [
                $this->timeToMinutes((string) $dept->velada_start),
                $this->timeToMinutes((string) $dept->velada_end),
            ];
        }

        return [
            ((int) SystemSetting::get('velada_detection_start_hour', 22)) * 60,
            ((int) SystemSetting::get('velada_detection_end_hour', 5)) * 60,
        ];
    }

    /** Minutes-of-day for a 'HH:MM[:SS]' time string. */
    private function timeToMinutes(string $time): int
    {
        $parsed = Carbon::parse($time);

        return $parsed->hour * 60 + $parsed->minute;
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
