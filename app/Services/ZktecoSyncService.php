<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\LateAccumulation;
use App\Models\Position;
use App\Models\Schedule;
use App\Models\SyncLog;
use App\Models\SystemSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for syncing ZKTeco data from the shared database.
 *
 * The Python script (pyzk) fills the `users` and `attendance` tables
 * directly in the same MySQL database. This service reads from those
 * tables and syncs to the `employees` and `attendance_records` tables.
 */
class ZktecoSyncService
{
    /**
     * Sync employees from the users table.
     *
     * Imports new employees and updates existing ones based on attendance activity.
     *
     * @param int $inactivityDays Number of days without attendance to mark as inactive
     * @return array{imported: int, updated: int, inactive: int}
     */
    public function syncEmployees(int $inactivityDays = 60): array
    {
        // Get unique users from ZKTeco users table (group by user_id to avoid duplicates across devices)
        $zktecoUsers = DB::table('users')
            ->select('user_id', DB::raw('MAX(name) as name'), DB::raw('MAX(privilege) as privilege'), DB::raw('MAX(group_id) as group_id'))
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->orderBy('user_id')
            ->get();

        // Get or create default department, position, schedule
        $defaultDepartment = Department::firstOrCreate(
            ['code' => 'GEN'],
            ['name' => 'General', 'is_active' => true]
        );

        $defaultPosition = Position::firstOrCreate(
            ['code' => 'OPR'],
            ['name' => 'Operador', 'department_id' => $defaultDepartment->id, 'base_salary' => 0, 'is_active' => true]
        );

        $defaultSchedule = Schedule::firstOrCreate(
            ['code' => 'TG'],
            [
                'name' => 'Turno General',
                'entry_time' => '07:00:00',
                'exit_time' => '17:00:00',
                'break_minutes' => 60,
                'daily_work_hours' => 9,
                'late_tolerance_minutes' => 10,
                'is_flexible' => false,
                'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'],
                'is_active' => true,
            ]
        );

        // Get users with recent attendance to determine active status
        $cutoffDate = Carbon::now()->subDays($inactivityDays);
        $usersWithRecentAttendance = DB::table('attendance')
            ->where('timestamp', '>=', $cutoffDate)
            ->distinct()
            ->pluck('user_id')
            ->toArray();

        // Track ZKTeco user_ids that we've seen
        $seenUserIds = [];

        $imported = 0;
        $updated = 0;

        foreach ($zktecoUsers as $zkUser) {
            $userId = $zkUser->user_id;
            $name = trim($zkUser->name ?? '');

            if (!$userId) {
                continue;
            }

            $seenUserIds[] = $userId;

            // Determine status based on recent attendance
            $hasRecentAttendance = in_array($userId, $usersWithRecentAttendance);
            $status = $hasRecentAttendance ? 'active' : 'inactive';

            // Check if employee already exists
            $existingEmployee = Employee::where('zkteco_user_id', $userId)->first();

            if ($existingEmployee) {
                $updates = [];

                // Update status if changed (but not if terminated)
                if ($existingEmployee->status !== $status && $existingEmployee->status !== 'terminated') {
                    $updates['status'] = $status;
                }

                // Update name if ZKTeco has a better name (not placeholder)
                // and current name is a placeholder
                $isCurrentNamePlaceholder = preg_match('/^Empleado \d+$/', $existingEmployee->full_name);
                $isZktecoNameReal = !empty($name) && !preg_match('/^NN-\d+$/', $name) && !preg_match('/^\d+$/', $name);

                if ($isCurrentNamePlaceholder && $isZktecoNameReal) {
                    $nameParts = $this->parseName($name, $userId);
                    $updates['first_name'] = $nameParts['first_name'];
                    $updates['last_name'] = $nameParts['last_name'];
                    $updates['full_name'] = $nameParts['full_name'];
                    Log::info("Updated employee {$userId} name from '{$existingEmployee->full_name}' to '{$nameParts['full_name']}'");
                }

                if (!empty($updates)) {
                    $existingEmployee->update($updates);
                    $updated++;
                }
                continue;
            }

            // Parse name for new employee
            $nameParts = $this->parseName($name, $userId);

            // Create employee
            Employee::create([
                'employee_number' => 'EMP-' . str_pad($userId, 4, '0', STR_PAD_LEFT),
                'zkteco_user_id' => $userId,
                'first_name' => $nameParts['first_name'],
                'last_name' => $nameParts['last_name'],
                'full_name' => $nameParts['full_name'],
                'hire_date' => Carbon::now()->subYears(1),
                'department_id' => $defaultDepartment->id,
                'position_id' => $defaultPosition->id,
                'schedule_id' => $defaultSchedule->id,
                'hourly_rate' => 125.00,
                'status' => $status,
            ]);

            $imported++;
            Log::info("Imported employee {$userId} ({$nameParts['full_name']}) - status: {$status}");
        }

        // Mark employees that exist in Pinky but NOT in ZKTeco users table as inactive
        $inactiveCount = $this->markMissingEmployeesAsInactive($seenUserIds);

        return [
            'imported' => $imported,
            'updated' => $updated,
            'inactive' => $inactiveCount,
        ];
    }

    /**
     * Sync attendance records from ZKTeco attendance table.
     *
     * @param Carbon|null $fromDate Start date for syncing
     * @return array{fetched: int, processed: int, created: int}
     */
    public function syncAttendance(?Carbon $fromDate = null): array
    {
        $fromDate = $fromDate ?? $this->getLastSyncDate();

        // Fetch attendance records from ZKTeco
        $rawAttendance = DB::table('attendance')
            ->where('timestamp', '>=', $fromDate)
            ->orderBy('user_id')
            ->orderBy('timestamp')
            ->get()
            ->toArray();

        $fetched = count($rawAttendance);
        $processed = 0;
        $created = 0;

        // Group records by user_id and date
        $groupedRecords = $this->groupRecordsByUserAndDate($rawAttendance);

        foreach ($groupedRecords as $userId => $dateRecords) {
            $employee = Employee::where('zkteco_user_id', $userId)->first();

            if (!$employee) {
                continue;
            }

            foreach ($dateRecords as $date => $punches) {
                try {
                    $wasCreated = $this->processEmployeeDayRecords($employee, $date, $punches);
                    $processed++;
                    if ($wasCreated) {
                        $created++;
                    }
                } catch (\Exception $e) {
                    Log::error("Error processing attendance for employee {$employee->id} on {$date}: " . $e->getMessage());
                }
            }
        }

        // Detect absences
        $this->detectAbsences($fromDate, Carbon::yesterday());

        return [
            'fetched' => $fetched,
            'processed' => $processed,
            'created' => $created,
        ];
    }

    /**
     * Run full sync process.
     *
     * @param Carbon|null $fromDate Start date for attendance sync
     * @param int|null $triggeredBy User ID who triggered the sync
     * @return SyncLog
     */
    public function sync(?Carbon $fromDate = null, ?int $triggeredBy = null): SyncLog
    {
        $log = SyncLog::create([
            'type' => 'zkteco',
            'started_at' => now(),
            'status' => 'running',
            'triggered_by' => $triggeredBy,
        ]);

        try {
            // Step 1: Sync employees
            Log::info("ZKTeco Sync: Syncing employees...");
            $employeeStats = $this->syncEmployees();
            Log::info("ZKTeco Sync: Employees - Imported: {$employeeStats['imported']}, Updated: {$employeeStats['updated']}, Marked inactive: {$employeeStats['inactive']}");

            // Step 2: Sync attendance
            $fromDate = $fromDate ?? $this->getLastSyncDate();
            Log::info("ZKTeco Sync: Syncing attendance from {$fromDate}...");
            $attendanceStats = $this->syncAttendance($fromDate);
            Log::info("ZKTeco Sync: Attendance - Fetched: {$attendanceStats['fetched']}, Processed: {$attendanceStats['processed']}, Created: {$attendanceStats['created']}");

            $log->update([
                'completed_at' => now(),
                'status' => 'completed',
                'records_fetched' => $attendanceStats['fetched'],
                'records_processed' => $attendanceStats['processed'],
                'records_created' => $attendanceStats['created'],
                'employees_imported' => $employeeStats['imported'],
                'employees_updated' => $employeeStats['updated'],
                'employees_marked_inactive' => $employeeStats['inactive'],
            ]);

        } catch (\Exception $e) {
            Log::error("ZKTeco Sync failed: " . $e->getMessage());

            $log->update([
                'completed_at' => now(),
                'status' => 'failed',
                'errors' => ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()],
            ]);

            throw $e;
        }

        return $log;
    }

    /**
     * Get the last sync date.
     */
    private function getLastSyncDate(): Carbon
    {
        $lastSync = SyncLog::completed()
            ->where('type', 'zkteco')
            ->latest('completed_at')
            ->first();

        if ($lastSync) {
            return $lastSync->completed_at->subHours(1);
        }

        return Carbon::now()->subDays(7);
    }

    /**
     * Parse name into parts.
     */
    private function parseName(string $name, int $userId): array
    {
        // Handle empty or placeholder names
        if (empty($name) || preg_match('/^NN-\d+$/', $name) || preg_match('/^\d+$/', $name)) {
            return [
                'first_name' => 'Empleado',
                'last_name' => (string) $userId,
                'full_name' => 'Empleado ' . $userId,
            ];
        }

        // Clean and normalize the name
        $name = mb_convert_case(trim($name), MB_CASE_TITLE, 'UTF-8');

        // Split into parts
        $parts = preg_split('/\s+/', $name);

        if (count($parts) >= 4) {
            $firstName = $parts[0] . ' ' . $parts[1];
            $lastName = implode(' ', array_slice($parts, 2));
        } elseif (count($parts) >= 2) {
            $firstName = $parts[0];
            $lastName = implode(' ', array_slice($parts, 1));
        } else {
            $firstName = $name;
            $lastName = '';
        }

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => trim("{$firstName} {$lastName}"),
        ];
    }

    /**
     * Mark employees that don't exist in ZKTeco as inactive.
     */
    private function markMissingEmployeesAsInactive(array $seenUserIds): int
    {
        if (empty($seenUserIds)) {
            return 0;
        }

        $count = Employee::where('status', 'active')
            ->whereNotNull('zkteco_user_id')
            ->whereNotIn('zkteco_user_id', $seenUserIds)
            ->update(['status' => 'inactive']);

        if ($count > 0) {
            Log::info("Marked {$count} employees as inactive (not found in ZKTeco)");
        }

        return $count;
    }

    /**
     * Group records by user and date.
     *
     * For night shifts, punches between 00:00-06:00 are assigned to the previous day
     * if they appear to be continuation of a shift (i.e., there are punches from the
     * previous evening after 20:00).
     */
    private function groupRecordsByUserAndDate(array $records): array
    {
        $grouped = [];

        // First pass: group by actual date
        foreach ($records as $record) {
            $record = (array) $record;
            $userId = $record['user_id'];
            $timestamp = Carbon::parse($record['timestamp']);
            $date = $timestamp->toDateString();
            $hour = $timestamp->hour;

            if (!isset($grouped[$userId])) {
                $grouped[$userId] = [];
            }

            if (!isset($grouped[$userId][$date])) {
                $grouped[$userId][$date] = [];
            }

            $grouped[$userId][$date][] = $record;
        }

        // Second pass: merge early morning punches (00:00-06:00) with previous day's night punches
        foreach ($grouped as $userId => $dates) {
            $sortedDates = array_keys($dates);
            sort($sortedDates);

            foreach ($sortedDates as $date) {
                $punches = $dates[$date];

                // Check if all punches are in early morning (00:00-06:00)
                $allEarlyMorning = true;
                $hasLateNight = false;

                foreach ($punches as $punch) {
                    $hour = Carbon::parse($punch['timestamp'])->hour;
                    if ($hour >= 6) {
                        $allEarlyMorning = false;
                    }
                }

                // If all punches are early morning, check if previous day has late night punches
                if ($allEarlyMorning) {
                    $previousDate = Carbon::parse($date)->subDay()->toDateString();

                    if (isset($grouped[$userId][$previousDate])) {
                        $prevPunches = $grouped[$userId][$previousDate];
                        $hasLateNightPrev = false;

                        foreach ($prevPunches as $punch) {
                            $hour = Carbon::parse($punch['timestamp'])->hour;
                            if ($hour >= 20) {
                                $hasLateNightPrev = true;
                                break;
                            }
                        }

                        // If previous day has late night punches, merge current day's early morning punches
                        if ($hasLateNightPrev) {
                            $grouped[$userId][$previousDate] = array_merge($prevPunches, $punches);
                            unset($grouped[$userId][$date]);
                        }
                    }
                }
            }
        }

        return $grouped;
    }

    /**
     * Process all punches for an employee on a specific date.
     *
     * Simple and robust logic:
     * 1. Filter duplicate punches (fingerprint retries within 5 minutes)
     * 2. First punch = check_in
     * 3. Last punch = check_out (if different from first)
     * 4. Middle punches during lunch hours (11:30-14:00) = lunch out/in
     */
    private function processEmployeeDayRecords(Employee $employee, string $date, array $punches): bool
    {
        // Filter duplicate punches (retries within 5 minutes)
        $punches = $this->filterDuplicatePunches($punches, 5);

        if (empty($punches)) {
            return false;
        }

        $schedule = $employee->schedule;
        $isNightShift = $this->isNightShiftSchedule($schedule);

        // Sort punches by time
        usort($punches, fn($a, $b) => strcmp($a['timestamp'], $b['timestamp']));

        // Simple logic: first = in, last = out
        $firstPunch = $punches[0];
        $lastPunch = count($punches) > 1 ? end($punches) : null;

        $firstCheckIn = Carbon::parse($firstPunch['timestamp'])->format('H:i:s');
        $lastCheckOut = null;

        // Only set check_out if it's a different punch and at least 10 minutes after check_in
        // (to avoid treating quick duplicate punches as entry/exit)
        if ($lastPunch) {
            $lastTime = Carbon::parse($lastPunch['timestamp']);
            $firstTime = Carbon::parse($firstPunch['timestamp']);
            $diffMinutes = abs($lastTime->diffInMinutes($firstTime));

            if ($diffMinutes >= 10) {
                $lastCheckOut = $lastTime->format('H:i:s');
            }
        }

        // Detect lunch breaks (middle punches between 11:00 and 14:30)
        $lunchOut = null;
        $lunchIn = null;
        $actualBreakMinutes = 0;

        if (count($punches) >= 4) {
            // With 4+ punches, look for lunch pattern in the middle
            $middlePunches = array_slice($punches, 1, -1);
            $lunchPunches = [];

            foreach ($middlePunches as $punch) {
                $hour = Carbon::parse($punch['timestamp'])->hour;
                if ($hour >= 11 && $hour < 15) {
                    $lunchPunches[] = $punch;
                }
            }

            if (count($lunchPunches) >= 2) {
                $lunchOut = Carbon::parse($lunchPunches[0]['timestamp'])->format('H:i:s');
                $lunchIn = Carbon::parse(end($lunchPunches)['timestamp'])->format('H:i:s');
                $actualBreakMinutes = abs(Carbon::parse($lunchIn)->diffInMinutes(Carbon::parse($lunchOut)));
            }
        }

        // Get or create attendance record
        $attendance = AttendanceRecord::firstOrNew([
            'employee_id' => $employee->id,
            'work_date' => $date,
        ]);

        $wasCreated = !$attendance->exists;

        // Store raw punches with type annotations
        $rawPunches = [];
        foreach ($punches as $punch) {
            $time = Carbon::parse($punch['timestamp'])->format('H:i:s');
            $type = 'punch';
            if ($time === $firstCheckIn) {
                $type = 'in';
            } elseif ($time === $lastCheckOut) {
                $type = 'out';
            } elseif ($time === $lunchOut) {
                $type = 'lunch_out';
            } elseif ($time === $lunchIn) {
                $type = 'lunch_in';
            }

            $rawPunches[] = [
                'time' => $time,
                'type' => $type,
                'device' => $punch['device_id'] ?? null,
                'method' => $this->getAuthMethod($punch['status'] ?? 0),
            ];
        }

        $attendance->check_in = $firstCheckIn;
        $attendance->check_out = $lastCheckOut;
        $attendance->lunch_out = $lunchOut;
        $attendance->lunch_in = $lunchIn;
        $attendance->actual_break_minutes = $actualBreakMinutes;
        $attendance->is_night_shift = $isNightShift;
        $attendance->raw_punches = $rawPunches;
        $attendance->is_holiday = Holiday::isHoliday($date);
        $attendance->is_weekend_work = Carbon::parse($date)->isWeekend();

        $attendance->save();

        $this->calculateAttendanceMetrics($attendance);

        return $wasCreated;
    }

    /**
     * Calculate attendance metrics for a record.
     *
     * This method now:
     * 1. Uses actual break minutes when available instead of fixed deduction
     * 2. Handles night shift calculations correctly
     * 3. Qualifies night shift employees for bonus
     */
    private function calculateAttendanceMetrics(AttendanceRecord $attendance): void
    {
        $employee = $attendance->employee;
        $schedule = $employee->schedule;

        if (!$schedule) {
            $attendance->update(['requires_review' => true]);
            return;
        }

        $dayName = strtolower(Carbon::parse($attendance->work_date)->format('l'));
        $isWorkingDay = $schedule->isWorkingDay($dayName);

        if (!$attendance->check_in && $isWorkingDay && !$attendance->is_holiday) {
            $attendance->update(['status' => 'absent']);
            return;
        }

        if (!$attendance->check_in) {
            return;
        }

        $dateStr = Carbon::parse($attendance->work_date)->toDateString();
        $workDate = Carbon::parse($attendance->work_date);

        $checkInTime = $this->extractTime($attendance->check_in);
        $checkOutTime = $this->extractTime($attendance->check_out);
        $entryTime = $this->extractTime($schedule->entry_time);
        $exitTime = $this->extractTime($schedule->exit_time);
        $isNightShift = $attendance->is_night_shift ?? $this->isNightShiftSchedule($schedule);

        if (!$checkInTime || !$entryTime) {
            return;
        }

        // Calculate late minutes
        $expectedEntry = Carbon::parse($dateStr . ' ' . $entryTime);
        $actualEntry = Carbon::parse($dateStr . ' ' . $checkInTime);
        $tolerance = $schedule->late_tolerance_minutes ?? 10;

        $lateMinutes = 0;

        // For night shifts, adjust expected entry handling
        if ($isNightShift) {
            $checkInHour = Carbon::parse($checkInTime)->hour;
            // If check-in is at night (after 18:00) and entry time is also night, compare directly
            if ($checkInHour >= 18 || $checkInHour < 6) {
                if ($actualEntry->gt($expectedEntry->copy()->addMinutes($tolerance))) {
                    $lateMinutes = max(0, $actualEntry->diffInMinutes($expectedEntry) - $tolerance);
                }
            }
        } else {
            if ($actualEntry->gt($expectedEntry->copy()->addMinutes($tolerance))) {
                $lateMinutes = max(0, $actualEntry->diffInMinutes($expectedEntry) - $tolerance);
            }
        }

        // Punctuality bonus
        $punctualityBonusMinutes = (int) SystemSetting::get('punctuality_bonus_minutes', 5);
        $qualifiesForPunctualityBonus = $actualEntry->lte($expectedEntry->copy()->subMinutes($punctualityBonusMinutes));

        // Early departure
        $earlyDepartureMinutes = 0;
        if ($checkOutTime && $exitTime) {
            $expectedExit = Carbon::parse($dateStr . ' ' . $exitTime);
            $actualExit = Carbon::parse($dateStr . ' ' . $checkOutTime);

            // Handle night shift exit (next day)
            if ($isNightShift && $actualExit->lt($expectedExit)) {
                $expectedExit->addDay();
            }

            if ($actualExit->lt($expectedExit)) {
                $earlyDepartureMinutes = $expectedExit->diffInMinutes($actualExit);
            }
        }

        // Worked hours calculation with actual break
        $workedMinutes = 0;
        if ($checkInTime && $checkOutTime) {
            $checkIn = Carbon::parse($dateStr . ' ' . $checkInTime);
            $checkOut = Carbon::parse($dateStr . ' ' . $checkOutTime);

            // Handle midnight crossing for night shifts
            if ($checkOut->lt($checkIn)) {
                $checkOut->addDay();
            }

            $workedMinutes = $checkIn->diffInMinutes($checkOut);

            // Determine break to deduct:
            // 1. Use actual_break_minutes if lunch was tracked
            // 2. Fall back to lunch_out/lunch_in calculation
            // 3. Only deduct schedule break if worked > 5 hours and no lunch data
            if ($attendance->actual_break_minutes > 0) {
                $workedMinutes -= $attendance->actual_break_minutes;
            } elseif ($attendance->lunch_out && $attendance->lunch_in) {
                $lunchOutTime = $this->extractTime($attendance->lunch_out);
                $lunchInTime = $this->extractTime($attendance->lunch_in);
                if ($lunchOutTime && $lunchInTime) {
                    $breakMinutes = Carbon::parse($lunchInTime)->diffInMinutes(Carbon::parse($lunchOutTime));
                    $workedMinutes -= $breakMinutes;
                }
            } else {
                // No lunch data - only deduct break if worked full day (> 5 hours)
                $totalMinutes = $checkIn->diffInMinutes($checkOut);
                if ($totalMinutes > 300) {
                    $workedMinutes -= ($schedule->break_minutes ?? 60);
                }
            }
        }

        $workedHours = max(0, $workedMinutes / 60);
        $dailyHours = $schedule->daily_work_hours ?? 8;
        $regularHours = min($workedHours, $dailyHours);
        $overtimeHours = max(0, $workedHours - $dailyHours);

        // Check for approved authorizations
        $permissionHours = 0;
        $approvedAuth = Authorization::where('employee_id', $employee->id)
            ->where('date', $workDate->toDateString())
            ->where('status', Authorization::STATUS_APPROVED)
            ->whereIn('type', [
                Authorization::TYPE_EXIT_PERMISSION,
                Authorization::TYPE_ENTRY_PERMISSION,
            ])
            ->first();

        $hasApprovedExitPermission = false;
        $hasApprovedEntryPermission = false;

        if ($approvedAuth) {
            $permissionHours = (float) ($approvedAuth->hours ?? 0);
            $hasApprovedExitPermission = $approvedAuth->type === Authorization::TYPE_EXIT_PERMISSION;
            $hasApprovedEntryPermission = $approvedAuth->type === Authorization::TYPE_ENTRY_PERMISSION;
        }

        $totalPayrollHours = $workedHours + $permissionHours;

        // Determine status
        $status = 'present';
        if ($lateMinutes > 0 && !$hasApprovedEntryPermission) {
            $status = 'late';
        }
        if ($workedHours < 4 && $workedHours > 0) {
            $status = 'partial';
        }
        if ($attendance->is_holiday) {
            $status = 'holiday';
        }

        $earlyDepartureThreshold = (int) SystemSetting::get('early_departure_absence_threshold', 30);
        if ($earlyDepartureMinutes > $earlyDepartureThreshold && !$hasApprovedExitPermission) {
            $status = 'absent';
            $qualifiesForPunctualityBonus = false;
        }

        $requiresReview = !$checkOutTime && $checkInTime;

        // Night shift bonus qualification
        $qualifiesForNightShiftBonus = $isNightShift && $workedHours >= 6;

        $attendance->update([
            'worked_hours' => round($regularHours, 2),
            'overtime_hours' => round($overtimeHours, 2),
            'permission_hours' => round($permissionHours, 2),
            'total_payroll_hours' => round($totalPayrollHours, 2),
            'late_minutes' => $lateMinutes,
            'early_departure_minutes' => $earlyDepartureMinutes,
            'qualifies_for_punctuality_bonus' => $qualifiesForPunctualityBonus,
            'qualifies_for_night_shift_bonus' => $qualifiesForNightShiftBonus,
            'is_night_shift' => $isNightShift,
            'status' => $status,
            'requires_review' => $requiresReview,
        ]);

        // Process late accumulation (FASE 2.2: X retardos = 1 falta)
        if ($lateMinutes > 0 && !$hasApprovedEntryPermission) {
            $this->processLateAccumulation($employee, $workDate);
        }
    }

    /**
     * Process late accumulation for an employee.
     * When the configured threshold is reached, generates an absence incident.
     *
     * @param Employee $employee The employee
     * @param Carbon $workDate The date of the late arrival
     */
    private function processLateAccumulation(Employee $employee, Carbon $workDate): void
    {
        $year = $workDate->year;
        $week = $workDate->weekOfYear;

        // Get or create accumulation record for this week
        $accumulation = LateAccumulation::firstOrCreate(
            [
                'employee_id' => $employee->id,
                'year' => $year,
                'week' => $week,
            ],
            [
                'late_count' => 0,
                'absence_generated' => false,
            ]
        );

        // Increment late count
        $accumulation->incrementLate();

        // Check if we should generate an absence
        if ($accumulation->shouldGenerateAbsence()) {
            $this->generateAbsenceFromLateAccumulation($employee, $accumulation, $workDate);
        }
    }

    /**
     * Generate an absence incident from late accumulation.
     *
     * @param Employee $employee The employee
     * @param LateAccumulation $accumulation The accumulation record
     * @param Carbon $workDate The date to use for the incident
     */
    private function generateAbsenceFromLateAccumulation(
        Employee $employee,
        LateAccumulation $accumulation,
        Carbon $workDate
    ): void {
        // Find the incident type for late accumulation absence (code: FRT)
        $incidentType = IncidentType::where('code', 'FRT')->first();

        if (!$incidentType) {
            Log::warning("IncidentType with code 'FRT' not found. Cannot generate absence from late accumulation.");
            return;
        }

        $threshold = (int) SystemSetting::get('late_to_absence_count', 6);

        // Create the incident
        $incident = Incident::create([
            'employee_id' => $employee->id,
            'incident_type_id' => $incidentType->id,
            'start_date' => $workDate->toDateString(),
            'end_date' => $workDate->toDateString(),
            'days_count' => 1,
            'reason' => "Falta generada automáticamente por acumulación de {$accumulation->late_count} retardos (umbral: {$threshold}) en la semana {$accumulation->week} del año {$accumulation->year}.",
            'status' => $incidentType->requires_approval ? 'pending' : 'approved',
            'approved_by' => $incidentType->requires_approval ? null : 1, // System user
            'approved_at' => $incidentType->requires_approval ? null : now(),
        ]);

        // Mark the accumulation as having generated an absence
        $accumulation->update([
            'absence_generated' => true,
            'generated_incident_id' => $incident->id,
        ]);

        Log::info("Generated absence incident #{$incident->id} for employee {$employee->id} from {$accumulation->late_count} late arrivals in week {$accumulation->week}/{$accumulation->year}");
    }

    /**
     * Recalculate attendance metrics for a specific record.
     */
    public function recalculateAttendanceRecord(AttendanceRecord $attendance): void
    {
        $this->calculateAttendanceMetrics($attendance);
    }

    /**
     * Extract time portion from a value.
     */
    private function extractTime(mixed $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $str = (string) $value;

        if (str_contains($str, ' ')) {
            $parts = explode(' ', $str);
            foreach (array_reverse($parts) as $part) {
                if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', trim($part))) {
                    return trim($part);
                }
            }
        }

        if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $str)) {
            return $str;
        }

        try {
            return Carbon::parse($str)->format('H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Detect absences for dates without records.
     */
    private function detectAbsences(Carbon $fromDate, Carbon $toDate): void
    {
        $yesterday = Carbon::yesterday();

        if ($yesterday->lt($fromDate) || $yesterday->isWeekend()) {
            return;
        }

        $employeesWithRecords = AttendanceRecord::where('work_date', $yesterday->toDateString())
            ->pluck('employee_id')
            ->toArray();

        $employeesWithoutRecords = Employee::active()
            ->with('schedule')
            ->whereNotIn('id', $employeesWithRecords)
            ->get();

        foreach ($employeesWithoutRecords as $employee) {
            if (!$employee->schedule) {
                continue;
            }

            $dayName = strtolower($yesterday->format('l'));
            $isWorkingDay = $employee->schedule->isWorkingDay($dayName);
            $isHoliday = Holiday::isHoliday($yesterday);

            if ($isWorkingDay && !$isHoliday) {
                AttendanceRecord::create([
                    'employee_id' => $employee->id,
                    'work_date' => $yesterday->toDateString(),
                    'status' => 'absent',
                ]);
            }
        }
    }

    /**
     * Get authentication method name.
     */
    private function getAuthMethod(int $status): string
    {
        return match ($status) {
            0 => 'fingerprint',
            1 => 'password',
            default => 'other',
        };
    }

    /**
     * Group duplicate punches (fingerprint retries) within time window.
     *
     * When employees scan their fingerprint multiple times in quick succession
     * (retries due to failed reads), we need to group these as a single event.
     *
     * @param array $punches List of punches ordered by timestamp
     * @param int $windowMinutes Window in minutes to consider as duplicate (default 5)
     * @return array Array of groups, each containing punches within the window
     */
    private function groupDuplicatePunches(array $punches, int $windowMinutes = 5): array
    {
        if (empty($punches)) {
            return [];
        }

        $groups = [];
        $currentGroup = [$punches[0]];
        $groupStartTime = Carbon::parse($punches[0]['timestamp']);

        for ($i = 1; $i < count($punches); $i++) {
            $currentTime = Carbon::parse($punches[$i]['timestamp']);

            // Use absolute value for time difference
            $diffMinutes = abs($currentTime->diffInMinutes($groupStartTime));

            if ($diffMinutes < $windowMinutes) {
                $currentGroup[] = $punches[$i];
            } else {
                $groups[] = $currentGroup;
                $currentGroup = [$punches[$i]];
                $groupStartTime = $currentTime;
            }
        }

        $groups[] = $currentGroup;

        return $groups;
    }

    /**
     * Select the correct punch from a group of duplicates based on context.
     *
     * - Entry periods (morning): keep FIRST (arrived at that time)
     * - Exit periods (afternoon): keep LAST (left at that time)
     *
     * @param array $group Group of duplicate punches
     * @return array The selected punch
     */
    private function selectPunchFromGroup(array $group): array
    {
        if (count($group) === 1) {
            return $group[0];
        }

        $hour = Carbon::parse($group[0]['timestamp'])->hour;

        // Entry periods: morning (5-10), lunch return (13-14), night shift entry (22-24, 0-2)
        // Exit periods: lunch out (11-13), afternoon/evening (15-22)
        $isEntryPeriod = ($hour >= 5 && $hour < 11) ||
                         ($hour >= 13 && $hour < 15) ||
                         ($hour >= 22 || $hour < 3);

        if ($isEntryPeriod) {
            return $group[0];  // FIRST for entry
        }

        return end($group);  // LAST for exit
    }

    /**
     * Filter and select correct punches from groups of duplicates.
     *
     * @param array $punches Raw punches from ZKTeco
     * @param int $windowMinutes Window for duplicate detection
     * @return array Filtered punches
     */
    private function filterDuplicatePunches(array $punches, int $windowMinutes = 5): array
    {
        $groups = $this->groupDuplicatePunches($punches, $windowMinutes);

        return array_map(
            fn($group) => $this->selectPunchFromGroup($group),
            $groups
        );
    }

    /**
     * Check if an employee's schedule is a night shift.
     *
     * Night shifts are detected when:
     * - Entry time is after 18:00
     * - Exit time is before 06:00 (crosses midnight)
     * - Exit hour < entry hour (crosses midnight)
     *
     * @param Schedule|null $schedule Employee's schedule
     * @return bool True if night shift schedule
     */
    private function isNightShiftSchedule(?Schedule $schedule): bool
    {
        if (!$schedule) {
            return false;
        }

        $entryHour = Carbon::parse($schedule->entry_time)->hour;
        $exitHour = Carbon::parse($schedule->exit_time)->hour;

        return $entryHour >= 18 || $exitHour < 6 || $exitHour < $entryHour;
    }

    /**
     * Reprocess an attendance record from its raw punches.
     *
     * Used by the recalculation command to reprocess existing records
     * with the new algorithms.
     *
     * @param AttendanceRecord $record The record to reprocess
     * @param array $rawPunches Raw punches data
     */
    public function reprocessAttendanceRecord(AttendanceRecord $record, array $rawPunches): void
    {
        $employee = $record->employee;

        // Convert raw_punches format to the format expected by processEmployeeDayRecords
        $punches = [];
        foreach ($rawPunches as $punch) {
            $punches[] = [
                'timestamp' => $record->work_date->toDateString() . ' ' . ($punch['time'] ?? '00:00:00'),
                'device_id' => $punch['device'] ?? null,
                'status' => match ($punch['method'] ?? 'fingerprint') {
                    'fingerprint' => 0,
                    'password' => 1,
                    default => 2,
                },
            ];
        }

        if (!empty($punches)) {
            $this->processEmployeeDayRecords($employee, $record->work_date->toDateString(), $punches);
        }
    }

    /**
     * Fetch users from ZKTeco users table.
     */
    public function fetchZktecoUsers(): array
    {
        return DB::table('users')
            ->select('user_id', 'name', 'privilege', 'group_id')
            ->distinct()
            ->orderBy('user_id')
            ->get()
            ->toArray();
    }

    /**
     * Test the connection and get stats.
     */
    public function testConnection(): array
    {
        try {
            $attendanceCount = DB::table('attendance')->count();
            $usersCount = DB::table('users')->distinct('user_id')->count('user_id');
            $devicesCount = DB::table('devices')->count();
            $lastRecord = DB::table('attendance')->orderBy('timestamp', 'desc')->first();

            return [
                'success' => true,
                'attendance_records' => $attendanceCount,
                'unique_users' => $usersCount,
                'devices' => $devicesCount,
                'last_record' => $lastRecord->timestamp ?? null,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
