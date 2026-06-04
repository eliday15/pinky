<?php

namespace App\Services;

use App\Models\AttendanceAnomaly;
use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\Employee;
use App\Models\Incident;
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
     * @param  OvertimeRoundingService  $rounder  Shared company rounding rule so
     *                                            anomalies use the SAME standard as
     *                                            authorizations (<30 min → 0h).
     */
    public function __construct(
        private readonly OvertimeRoundingService $rounder = new OvertimeRoundingService(),
    ) {}

    /**
     * Detect all anomalies for a set of attendance records.
     *
     * @param  Collection  $records  Collection of AttendanceRecord models
     * @return int Number of new anomalies detected
     */
    public function detectForRecords(Collection $records): int
    {
        $anomalyCount = 0;

        foreach ($records as $record) {
            // Isolate each record — a single corrupt row must never abort the
            // whole detection pass (the scheduler runs every 5 min and we want
            // it to keep healing data, not stall on one bad record).
            try {
                $anomalyCount += $this->detectForRecord($record);
            } catch (\Throwable $e) {
                Log::warning('AnomalyDetector: skipped record '.$record->id.' due to '.$e->getMessage());
            }
        }

        return $anomalyCount;
    }

    /**
     * Detect anomalies for a single attendance record.
     *
     * @param  AttendanceRecord  $record  The attendance record to analyze
     * @return int Number of new anomalies detected
     */
    public function detectForRecord(AttendanceRecord $record): int
    {
        $employee = $record->employee;

        // Defensive: an attendance record can outlive its employee (orphan FK
        // or hard-deleted). Skip rather than crash the whole detection batch.
        if (! $employee || ! $employee->schedule) {
            return 0;
        }

        // Get per-day schedule with employee overrides applied
        $dayName = strtolower(Carbon::parse($record->work_date)->format('l'));
        $daySchedule = $employee->getEffectiveScheduleForDay($dayName);

        $anomalies = [];

        // Run all detection methods
        $anomalies = array_merge($anomalies, $this->detectMissingCheckout($record, $daySchedule));
        $anomalies = array_merge($anomalies, $this->detectMissingCheckin($record, $daySchedule));
        $anomalies = array_merge($anomalies, $this->detectUnauthorizedOvertime($record, $employee, $daySchedule));
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

            if (! $exists) {
                AttendanceAnomaly::create(array_merge($anomalyData, [
                    'attendance_record_id' => $record->id,
                    'employee_id' => $record->employee_id,
                    'work_date' => $record->work_date,
                    'auto_detected' => true,
                ]));
                $count++;
            }
        }

        // Self-heal: close open per-hour anomalies that no longer meet the
        // authorization standard (e.g. a recalculation left less than 30 min,
        // which rounds to 0h and can never be authorized).
        $this->closeRedundantAnomalies($record, $daySchedule);

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
     * Skips detection if the employee has an approved night shift authorization
     * for that date or the record already has velada_hours, since velada workers
     * may check out past midnight (handled by a different record).
     *
     * @param  AttendanceRecord  $record  The attendance record
     * @param  mixed  $schedule  The employee's schedule
     * @return array List of anomaly data arrays
     */
    private function detectMissingCheckout(AttendanceRecord $record, $schedule): array
    {
        if (! $record->check_in || $record->check_out || $record->status === 'absent') {
            return [];
        }

        // Skip for velada workers - they may check out past midnight
        if (($record->velada_hours ?? 0) > 0) {
            return [];
        }

        $hasNightShiftAuth = Authorization::where('employee_id', $record->employee_id)
            ->where('date', $record->work_date)
            ->where('type', Authorization::TYPE_NIGHT_SHIFT)
            ->whereIn('status', [Authorization::STATUS_APPROVED, Authorization::STATUS_PAID])
            ->exists();

        if ($hasNightShiftAuth) {
            return [];
        }

        return [[
            'anomaly_type' => 'missing_checkout',
            'severity' => 'warning',
            'description' => 'El empleado registro entrada pero no registro salida.',
            'expected_value' => $schedule->exit_time,
            'actual_value' => null,
        ]];
    }

    /**
     * Detect missing checkin.
     *
     * @param  AttendanceRecord  $record  The attendance record
     * @param  mixed  $schedule  The employee's schedule
     * @return array List of anomaly data arrays
     */
    private function detectMissingCheckin(AttendanceRecord $record, $schedule): array
    {
        if (! $record->check_in && $record->check_out) {
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
     * Uses the SAME standard as the authorization flow ("Cargar desde checadas"
     * and the weekly report): early/late segments measured against the schedule
     * and rounded with the official company ladder (<30 min → 0h). Time that
     * rounds to 0h can never be authorized (the create form blocks 0-hour
     * authorizations), so flagging it would only generate unactionable noise.
     *
     * @param  AttendanceRecord  $record  The attendance record
     * @param  Employee  $employee  The employee
     * @param  mixed  $schedule  The employee's effective schedule for the day
     * @return array List of anomaly data arrays
     */
    private function detectUnauthorizedOvertime(AttendanceRecord $record, Employee $employee, $schedule): array
    {
        if ($record->overtime_hours <= 0) {
            return [];
        }

        $authorizableHours = $this->rounder->detectOvertimeHours(
            $record,
            $schedule,
            Carbon::parse($record->work_date)->toDateString()
        );

        if ($authorizableHours <= 0) {
            return [];
        }

        $hasAuthorization = Authorization::where('employee_id', $employee->id)
            ->where('date', $record->work_date)
            ->where('type', Authorization::TYPE_OVERTIME)
            ->whereIn('status', [Authorization::STATUS_APPROVED, Authorization::STATUS_PAID])
            ->exists();

        if (! $hasAuthorization) {
            return [[
                'anomaly_type' => 'unauthorized_overtime',
                'severity' => 'warning',
                'description' => "Se detectaron {$authorizableHours} horas extra autorizables sin autorizacion aprobada.",
                'expected_value' => '0',
                'actual_value' => (string) $authorizableHours,
                'deviation_minutes' => (int) round($authorizableHours * 60),
            ]];
        }

        return [];
    }

    /**
     * Detect unauthorized velada and missing post-midnight confirmation.
     *
     * Two checks:
     * 1. Velada without authorization -> unauthorized_velada (existing)
     * 2. Velada with authorization but no punch in confirmation window -> velada_missing_confirmation (new)
     *
     * @param  AttendanceRecord  $record  The attendance record
     * @param  Employee  $employee  The employee
     * @return array List of anomaly data arrays
     */
    private function detectUnauthorizedVelada(AttendanceRecord $record, Employee $employee): array
    {
        if (($record->velada_hours ?? 0) <= 0) {
            return [];
        }

        // Same standard as authorizations: <30 min rounds to 0h and cannot be
        // authorized, so there is no actionable velada to flag (nor a
        // confirmation punch to demand).
        $authorizableHours = $this->rounder->roundMinutes((int) round($record->velada_hours * 60));
        if ($authorizableHours <= 0) {
            return [];
        }

        $hasAuthorization = Authorization::where('employee_id', $employee->id)
            ->where('date', $record->work_date)
            ->where('type', Authorization::TYPE_NIGHT_SHIFT)
            ->whereIn('status', [Authorization::STATUS_APPROVED, Authorization::STATUS_PAID])
            ->exists();

        if (! $hasAuthorization) {
            return [[
                'anomaly_type' => 'unauthorized_velada',
                'severity' => 'critical',
                'description' => "Se detectaron {$authorizableHours} horas de velada autorizables sin autorizacion aprobada.",
                'expected_value' => '0',
                'actual_value' => (string) $authorizableHours,
                'deviation_minutes' => (int) round($authorizableHours * 60),
            ]];
        }

        // Has authorization - check for post-midnight confirmation punch
        if (! $this->hasPostMidnightPunch($record)) {
            $confirmStart = (int) SystemSetting::get('velada_confirmation_start_hour', 0);
            $confirmEnd = (int) SystemSetting::get('velada_confirmation_end_hour', 1);

            return [[
                'anomaly_type' => AttendanceAnomaly::TYPE_VELADA_MISSING_CONFIRMATION,
                'severity' => 'warning',
                'description' => "Velada autorizada pero sin checada de confirmacion entre {$confirmStart}:00 y {$confirmEnd}:00.",
                'expected_value' => "{$confirmStart}:00-{$confirmEnd}:00",
                'actual_value' => null,
            ]];
        }

        return [];
    }

    /**
     * Check if a record has a raw punch in the post-midnight confirmation window.
     *
     * @param  AttendanceRecord  $record  The attendance record
     * @return bool True if a punch exists in the confirmation window
     */
    private function hasPostMidnightPunch(AttendanceRecord $record): bool
    {
        $rawPunches = $record->raw_punches ?? [];
        if (empty($rawPunches)) {
            return false;
        }

        $confirmStart = (int) SystemSetting::get('velada_confirmation_start_hour', 0);
        $confirmEnd = (int) SystemSetting::get('velada_confirmation_end_hour', 1);

        foreach ($rawPunches as $punch) {
            $punchTime = $punch['time'] ?? null;
            if (! $punchTime) {
                continue;
            }

            $hour = (int) Carbon::parse($punchTime)->format('H');
            if ($hour >= $confirmStart && $hour < $confirmEnd) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect excessive break (lunch longer than allowed).
     *
     * @param  AttendanceRecord  $record  The attendance record
     * @param  mixed  $schedule  The employee's schedule
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
     * @param  AttendanceRecord  $record  The attendance record
     * @param  mixed  $schedule  The employee's schedule
     * @return array List of anomaly data arrays
     */
    private function detectMissingLunch(AttendanceRecord $record, $schedule): array
    {
        $lunchRequired = SystemSetting::get('lunch_required', true);
        if (! $lunchRequired) {
            return [];
        }

        // Only flag if worked > 5 hours and schedule has break defined
        if ($record->worked_hours >= 5 && ($schedule->break_minutes ?? 0) > 0) {
            if (! $record->lunch_out && ! $record->lunch_in) {
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
     * @param  AttendanceRecord  $record  The attendance record
     * @param  mixed  $schedule  The employee's schedule
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
     * @param  AttendanceRecord  $record  The attendance record
     * @param  mixed  $schedule  The employee's schedule
     * @return array List of anomaly data arrays
     */
    private function detectEarlyDeparture(AttendanceRecord $record, $schedule): array
    {
        if (($record->early_departure_minutes ?? 0) > 15) {
            $hasPermission = Incident::where('employee_id', $record->employee_id)
                ->whereDate('start_date', '<=', $record->work_date)
                ->whereDate('end_date', '>=', $record->work_date)
                ->where('status', 'approved')
                ->whereHas('incidentType', fn ($q) => $q->where('code', 'PSA'))
                ->exists();

            if (! $hasPermission) {
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
     * @param  AttendanceRecord  $record  The attendance record
     * @param  mixed  $schedule  The employee's schedule
     * @return array List of anomaly data arrays
     */
    private function detectScheduleDeviation(AttendanceRecord $record, $schedule): array
    {
        if (! $record->check_in || ! $schedule->entry_time) {
            return [];
        }

        $expected = Carbon::parse($record->work_date->toDateString().' '.Carbon::parse($schedule->entry_time)->format('H:i:s'));
        $actual = Carbon::parse($record->work_date->toDateString().' '.Carbon::parse($record->check_in)->format('H:i:s'));
        $diffMinutes = $actual->diffInMinutes($expected, false); // negative = early

        // Flag if arrived more than 60 minutes early (possible wrong schedule)
        if ($diffMinutes < -60) {
            return [[
                'anomaly_type' => 'schedule_deviation',
                'severity' => 'info',
                'description' => 'Entrada significativamente antes del horario programado ('.abs($diffMinutes).' min antes).',
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
     * @param  AttendanceRecord  $record  The attendance record
     * @return array List of anomaly data arrays
     */
    private function detectDuplicatePunches(AttendanceRecord $record): array
    {
        $rawPunches = $record->raw_punches ?? [];
        if (count($rawPunches) > 8) {
            return [[
                'anomaly_type' => 'duplicate_punches',
                'severity' => 'info',
                'description' => 'Se detectaron '.count($rawPunches).' checadas en el dia (mas de lo normal).',
                'expected_value' => '4-6',
                'actual_value' => (string) count($rawPunches),
            ]];
        }

        return [];
    }

    /**
     * Auto-resolve open per-hour anomalies that no longer meet the
     * authorization standard.
     *
     * An unauthorized_overtime / unauthorized_velada anomaly is redundant when
     * the detected time rounds to 0 hours under the official company ladder
     * (<30 min → 0h): nothing can ever be authorized for it, so it would sit
     * open forever. Anomalies covered by an approved authorization are NOT
     * touched here — those get linked (status linked_to_authorization) by the
     * approval flow, which preserves the audit trail to the authorization.
     *
     * @param  AttendanceRecord  $record  The attendance record
     * @param  mixed  $schedule  The employee's effective schedule for the day
     */
    private function closeRedundantAnomalies(AttendanceRecord $record, $schedule): void
    {
        // Cheap guard: only records currently flagged can have stale anomalies.
        if (! $record->has_anomalies) {
            return;
        }

        $staleTypes = [];

        $authorizableOvertime = $record->overtime_hours > 0
            ? $this->rounder->detectOvertimeHours($record, $schedule, Carbon::parse($record->work_date)->toDateString())
            : 0.0;
        if ($authorizableOvertime <= 0) {
            $staleTypes[] = AttendanceAnomaly::TYPE_UNAUTHORIZED_OVERTIME;
        }

        $authorizableVelada = ($record->velada_hours ?? 0) > 0
            ? $this->rounder->roundMinutes((int) round($record->velada_hours * 60))
            : 0.0;
        if ($authorizableVelada <= 0) {
            $staleTypes[] = AttendanceAnomaly::TYPE_UNAUTHORIZED_VELADA;
        }

        if (empty($staleTypes)) {
            return;
        }

        AttendanceAnomaly::where('attendance_record_id', $record->id)
            ->whereIn('anomaly_type', $staleTypes)
            ->where('status', AttendanceAnomaly::STATUS_OPEN)
            ->update([
                'status' => AttendanceAnomaly::STATUS_RESOLVED,
                'resolved_at' => now(),
                'resolution_notes' => 'Auto-resuelto: tiempo menor a 30 minutos se redondea a 0 horas (estandar de autorizaciones).',
            ]);
    }

    /**
     * Detect anomalies for all records in a date range.
     *
     * @param  Carbon  $startDate  Start of the date range
     * @param  Carbon  $endDate  End of the date range
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
