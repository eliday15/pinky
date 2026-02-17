<?php

namespace App\Services;

use App\Models\AttendanceAnomaly;
use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\Employee;
use App\Models\SystemSetting;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Service for detecting anomalies in attendance records.
 *
 * Runs after each sync and can also be triggered manually.
 * Detects missing punches, unauthorized overtime/velada, excessive breaks,
 * late arrivals, early departures, schedule deviations, and duplicate punches.
 */
class AnomalyDetectorService
{
    /**
     * Detect all anomalies for a set of attendance records.
     *
     * @param Collection $records Collection of AttendanceRecord models
     * @return int Number of new anomalies detected
     */
    public function detectForRecords(Collection $records): int
    {
        $anomalyCount = 0;

        foreach ($records as $record) {
            $anomalyCount += $this->detectForRecord($record);
        }

        return $anomalyCount;
    }

    /**
     * Detect anomalies for a single attendance record.
     *
     * @param AttendanceRecord $record The attendance record to analyze
     * @return int Number of new anomalies detected
     */
    public function detectForRecord(AttendanceRecord $record): int
    {
        $employee = $record->employee;
        $schedule = $employee->schedule;

        if (!$schedule) {
            return 0;
        }

        // Get per-day schedule overrides
        $dayName = strtolower(Carbon::parse($record->work_date)->format('l'));
        $daySchedule = $schedule->getScheduleForDay($dayName);

        $anomalies = [];

        // Run all detection methods
        $anomalies = array_merge($anomalies, $this->detectMissingCheckout($record, $daySchedule));
        $anomalies = array_merge($anomalies, $this->detectMissingCheckin($record, $daySchedule));
        $anomalies = array_merge($anomalies, $this->detectUnauthorizedOvertime($record, $employee));
        $anomalies = array_merge($anomalies, $this->detectUnauthorizedVelada($record, $employee));
        $anomalies = array_merge($anomalies, $this->detectExcessiveBreak($record, $daySchedule));
        $anomalies = array_merge($anomalies, $this->detectMissingLunch($record, $daySchedule));
        $anomalies = array_merge($anomalies, $this->detectLateArrival($record, $daySchedule));
        $anomalies = array_merge($anomalies, $this->detectEarlyDeparture($record, $daySchedule));
        $anomalies = array_merge($anomalies, $this->detectScheduleDeviation($record, $daySchedule));
        $anomalies = array_merge($anomalies, $this->detectDuplicatePunches($record));

        // Save anomalies
        $count = 0;
        foreach ($anomalies as $anomalyData) {
            // Check if same anomaly already exists (avoid duplicates)
            $exists = AttendanceAnomaly::where('attendance_record_id', $record->id)
                ->where('anomaly_type', $anomalyData['anomaly_type'])
                ->where('work_date', $record->work_date)
                ->exists();

            if (!$exists) {
                AttendanceAnomaly::create(array_merge($anomalyData, [
                    'attendance_record_id' => $record->id,
                    'employee_id' => $record->employee_id,
                    'work_date' => $record->work_date,
                    'auto_detected' => true,
                ]));
                $count++;
            }
        }

        // Update record anomaly count
        $totalAnomalies = AttendanceAnomaly::where('attendance_record_id', $record->id)
            ->where('status', 'open')
            ->count();

        $record->update([
            'has_anomalies' => $totalAnomalies > 0,
            'anomaly_count' => $totalAnomalies,
        ]);

        return $count;
    }

    /**
     * Detect missing checkout.
     *
     * @param AttendanceRecord $record The attendance record
     * @param mixed $schedule The employee's schedule
     * @return array List of anomaly data arrays
     */
    private function detectMissingCheckout(AttendanceRecord $record, $schedule): array
    {
        if ($record->check_in && !$record->check_out && $record->status !== 'absent') {
            return [[
                'anomaly_type' => 'missing_checkout',
                'severity' => 'warning',
                'description' => 'El empleado registro entrada pero no registro salida.',
                'expected_value' => $schedule->exit_time,
                'actual_value' => null,
            ]];
        }
        return [];
    }

    /**
     * Detect missing checkin.
     *
     * @param AttendanceRecord $record The attendance record
     * @param mixed $schedule The employee's schedule
     * @return array List of anomaly data arrays
     */
    private function detectMissingCheckin(AttendanceRecord $record, $schedule): array
    {
        if (!$record->check_in && $record->check_out) {
            return [[
                'anomaly_type' => 'missing_checkin',
                'severity' => 'warning',
                'description' => 'El empleado registro salida pero no registro entrada.',
                'expected_value' => $schedule->entry_time,
                'actual_value' => null,
            ]];
        }
        return [];
    }

    /**
     * Detect unauthorized overtime (overtime without approved authorization).
     *
     * @param AttendanceRecord $record The attendance record
     * @param Employee $employee The employee
     * @return array List of anomaly data arrays
     */
    private function detectUnauthorizedOvertime(AttendanceRecord $record, Employee $employee): array
    {
        if ($record->overtime_hours <= 0) {
            return [];
        }

        $hasAuthorization = Authorization::where('employee_id', $employee->id)
            ->where('date', $record->work_date)
            ->where('type', Authorization::TYPE_OVERTIME)
            ->whereIn('status', [Authorization::STATUS_APPROVED, Authorization::STATUS_PAID])
            ->exists();

        if (!$hasAuthorization) {
            return [[
                'anomaly_type' => 'unauthorized_overtime',
                'severity' => 'warning',
                'description' => "Se detectaron {$record->overtime_hours} horas extra sin autorizacion aprobada.",
                'expected_value' => '0',
                'actual_value' => (string) $record->overtime_hours,
                'deviation_minutes' => (int) ($record->overtime_hours * 60),
            ]];
        }
        return [];
    }

    /**
     * Detect unauthorized velada (night work after midnight without authorization).
     *
     * @param AttendanceRecord $record The attendance record
     * @param Employee $employee The employee
     * @return array List of anomaly data arrays
     */
    private function detectUnauthorizedVelada(AttendanceRecord $record, Employee $employee): array
    {
        if (($record->velada_hours ?? 0) <= 0) {
            return [];
        }

        $hasAuthorization = Authorization::where('employee_id', $employee->id)
            ->where('date', $record->work_date)
            ->where('type', Authorization::TYPE_NIGHT_SHIFT)
            ->whereIn('status', [Authorization::STATUS_APPROVED, Authorization::STATUS_PAID])
            ->exists();

        if (!$hasAuthorization) {
            return [[
                'anomaly_type' => 'unauthorized_velada',
                'severity' => 'critical',
                'description' => "Se detectaron {$record->velada_hours} horas de velada sin autorizacion aprobada.",
                'expected_value' => '0',
                'actual_value' => (string) $record->velada_hours,
                'deviation_minutes' => (int) ($record->velada_hours * 60),
            ]];
        }
        return [];
    }

    /**
     * Detect excessive break (lunch longer than allowed).
     *
     * @param AttendanceRecord $record The attendance record
     * @param mixed $schedule The employee's schedule
     * @return array List of anomaly data arrays
     */
    private function detectExcessiveBreak(AttendanceRecord $record, $schedule): array
    {
        $maxDeviation = (int) SystemSetting::get('lunch_max_deviation_minutes', 15);
        $scheduledBreak = $schedule->break_minutes ?? 60;

        if ($record->actual_break_minutes > 0 && $record->actual_break_minutes > ($scheduledBreak + $maxDeviation)) {
            $deviation = $record->actual_break_minutes - $scheduledBreak;
            return [[
                'anomaly_type' => 'excessive_break',
                'severity' => 'info',
                'description' => "Comida excedio por {$deviation} minutos (tomado: {$record->actual_break_minutes} min, programado: {$scheduledBreak} min).",
                'expected_value' => (string) $scheduledBreak,
                'actual_value' => (string) $record->actual_break_minutes,
                'deviation_minutes' => $deviation,
            ]];
        }
        return [];
    }

    /**
     * Detect missing lunch punch.
     *
     * @param AttendanceRecord $record The attendance record
     * @param mixed $schedule The employee's schedule
     * @return array List of anomaly data arrays
     */
    private function detectMissingLunch(AttendanceRecord $record, $schedule): array
    {
        $lunchRequired = SystemSetting::get('lunch_required', true);
        if (!$lunchRequired) {
            return [];
        }

        // Only flag if worked > 5 hours and schedule has break defined
        if ($record->worked_hours >= 5 && ($schedule->break_minutes ?? 0) > 0) {
            if (!$record->lunch_out && !$record->lunch_in) {
                return [[
                    'anomaly_type' => 'missing_lunch',
                    'severity' => 'info',
                    'description' => 'No se registro checada de comida.',
                    'expected_value' => $schedule->break_start ?? '12:00',
                    'actual_value' => null,
                ]];
            }
        }
        return [];
    }

    /**
     * Detect late arrival (significant, beyond normal tolerance).
     *
     * @param AttendanceRecord $record The attendance record
     * @param mixed $schedule The employee's schedule
     * @return array List of anomaly data arrays
     */
    private function detectLateArrival(AttendanceRecord $record, $schedule): array
    {
        if ($record->late_minutes > 30) { // Only flag significant lateness
            return [[
                'anomaly_type' => 'late_arrival',
                'severity' => $record->late_minutes > 60 ? 'warning' : 'info',
                'description' => "Llegada con {$record->late_minutes} minutos de retraso.",
                'expected_value' => $schedule->entry_time,
                'actual_value' => $record->check_in,
                'deviation_minutes' => $record->late_minutes,
            ]];
        }
        return [];
    }

    /**
     * Detect early departure.
     *
     * @param AttendanceRecord $record The attendance record
     * @param mixed $schedule The employee's schedule
     * @return array List of anomaly data arrays
     */
    private function detectEarlyDeparture(AttendanceRecord $record, $schedule): array
    {
        if (($record->early_departure_minutes ?? 0) > 15) {
            $hasPermission = Authorization::where('employee_id', $record->employee_id)
                ->where('date', $record->work_date)
                ->where('type', Authorization::TYPE_EXIT_PERMISSION)
                ->whereIn('status', [Authorization::STATUS_APPROVED, Authorization::STATUS_PAID])
                ->exists();

            if (!$hasPermission) {
                return [[
                    'anomaly_type' => 'early_departure',
                    'severity' => $record->early_departure_minutes > 60 ? 'warning' : 'info',
                    'description' => "Salida anticipada de {$record->early_departure_minutes} minutos sin permiso.",
                    'expected_value' => $schedule->exit_time,
                    'actual_value' => $record->check_out,
                    'deviation_minutes' => $record->early_departure_minutes,
                ]];
            }
        }
        return [];
    }

    /**
     * Detect schedule deviation (arrived much too early or too late).
     *
     * @param AttendanceRecord $record The attendance record
     * @param mixed $schedule The employee's schedule
     * @return array List of anomaly data arrays
     */
    private function detectScheduleDeviation(AttendanceRecord $record, $schedule): array
    {
        if (!$record->check_in || !$schedule->entry_time) {
            return [];
        }

        $expected = Carbon::parse($record->work_date->toDateString() . ' ' . Carbon::parse($schedule->entry_time)->format('H:i:s'));
        $actual = Carbon::parse($record->work_date->toDateString() . ' ' . Carbon::parse($record->check_in)->format('H:i:s'));
        $diffMinutes = $actual->diffInMinutes($expected, false); // negative = early

        // Flag if arrived more than 60 minutes early (possible wrong schedule)
        if ($diffMinutes < -60) {
            return [[
                'anomaly_type' => 'schedule_deviation',
                'severity' => 'info',
                'description' => 'Entrada significativamente antes del horario programado (' . abs($diffMinutes) . ' min antes).',
                'expected_value' => $schedule->entry_time,
                'actual_value' => $record->check_in,
                'deviation_minutes' => abs($diffMinutes),
            ]];
        }
        return [];
    }

    /**
     * Detect duplicate punches (too many punches in a day).
     *
     * @param AttendanceRecord $record The attendance record
     * @return array List of anomaly data arrays
     */
    private function detectDuplicatePunches(AttendanceRecord $record): array
    {
        $rawPunches = $record->raw_punches ?? [];
        if (count($rawPunches) > 8) {
            return [[
                'anomaly_type' => 'duplicate_punches',
                'severity' => 'info',
                'description' => 'Se detectaron ' . count($rawPunches) . ' checadas en el dia (mas de lo normal).',
                'expected_value' => '4-6',
                'actual_value' => (string) count($rawPunches),
            ]];
        }
        return [];
    }

    /**
     * Detect anomalies for all records in a date range.
     *
     * @param Carbon $startDate Start of the date range
     * @param Carbon $endDate End of the date range
     * @return int Number of new anomalies detected
     */
    public function detectForDateRange(Carbon $startDate, Carbon $endDate): int
    {
        $records = AttendanceRecord::with(['employee.schedule'])
            ->whereBetween('work_date', [$startDate, $endDate])
            ->get();

        return $this->detectForRecords($records);
    }
}
