<?php

namespace Database\Seeders;

use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Position;
use App\Models\Schedule;
use App\Models\ZktecoGroupMapping;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;

class ZktecoImportSeeder extends Seeder
{
    private ?PDO $zktecoDb = null;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Connecting to ZKTeco database...');

        try {
            $this->zktecoDb = $this->getConnection();
            $this->command->info('Connected successfully!');
        } catch (\Exception $e) {
            $this->command->error('Failed to connect: ' . $e->getMessage());
            return;
        }

        // Step 1: Import employees
        $this->command->info('Importing employees from ZKTeco...');
        $this->importEmployees();

        // Step 2: Import attendance
        $this->command->info('Importing attendance records...');
        $this->importAttendance();

        $this->command->info('Import completed!');
    }

    /**
     * Get ZKTeco database connection.
     */
    private function getConnection(): PDO
    {
        $config = config('zkteco.database');

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'],
            $config['database']
        );

        return new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /**
     * Import employees from ZKTeco.
     */
    private function importEmployees(): void
    {
        // Get unique users from ZKTeco (group by user_id to avoid duplicates across devices)
        $stmt = $this->zktecoDb->query("
            SELECT
                u.user_id,
                MAX(u.name) as name,
                MAX(u.privilege) as privilege,
                MAX(u.group_id) as group_id
            FROM users u
            WHERE u.user_id IS NOT NULL
            GROUP BY u.user_id
            ORDER BY u.user_id
        ");

        $zktecoUsers = $stmt->fetchAll();
        $this->command->info("Found " . count($zktecoUsers) . " unique users in ZKTeco");

        // Get users with recent attendance (last 30 days) to determine active status
        $usersWithRecentAttendance = $this->getUsersWithRecentAttendance(30);

        // Get default department, position, schedule
        $defaultDepartment = Department::firstOrCreate(
            ['name' => 'General'],
            ['code' => 'GEN', 'is_active' => true]
        );

        $defaultPosition = Position::first();
        $defaultSchedule = Schedule::first();

        if (!$defaultPosition || !$defaultSchedule) {
            $this->command->error('Please run base seeders first (positions, schedules)');
            return;
        }

        $imported = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($zktecoUsers as $zkUser) {
            $userId = $zkUser['user_id'];
            $name = trim($zkUser['name'] ?? '');
            $groupId = (int) ($zkUser['group_id'] ?? 0);

            // Skip if no user_id
            if (!$userId) {
                $skipped++;
                continue;
            }

            // Determine department based on group_id mapping
            $departmentId = $this->getDepartmentForGroup($groupId, $defaultDepartment->id);

            // Determine status based on recent attendance
            $hasRecentAttendance = in_array($userId, $usersWithRecentAttendance);
            $status = $hasRecentAttendance ? 'active' : 'inactive';

            // Check if employee already exists
            $existingEmployee = Employee::where('zkteco_user_id', $userId)->first();

            if ($existingEmployee) {
                // Update existing employee
                $changes = [];

                // Update department if still default and mapping exists
                if ($existingEmployee->department_id === $defaultDepartment->id && $departmentId !== $defaultDepartment->id) {
                    $changes['department_id'] = $departmentId;
                }

                // Update status based on activity
                if ($existingEmployee->status !== $status) {
                    $changes['status'] = $status;
                }

                if (!empty($changes)) {
                    $existingEmployee->update($changes);
                    $updated++;
                } else {
                    $skipped++;
                }
                continue;
            }

            // Parse name
            $nameParts = $this->parseName($name, $userId);

            // Create employee with proper department and status
            Employee::create([
                'employee_number' => 'EMP-' . str_pad($userId, 4, '0', STR_PAD_LEFT),
                'zkteco_user_id' => $userId,
                'first_name' => $nameParts['first_name'],
                'last_name' => $nameParts['last_name'],
                'full_name' => $nameParts['full_name'],
                'hire_date' => Carbon::now()->subYears(1),
                'department_id' => $departmentId,
                'position_id' => $defaultPosition->id,
                'schedule_id' => $defaultSchedule->id,
                'hourly_rate' => 125.00,
                'status' => $status,
            ]);

            $imported++;
        }

        $this->command->info("Imported: {$imported}, Updated: {$updated}, Skipped: {$skipped}");
    }

    /**
     * Get department ID for a ZKTeco group_id.
     */
    private function getDepartmentForGroup(int $groupId, int $defaultDepartmentId): int
    {
        if ($groupId <= 0) {
            return $defaultDepartmentId;
        }

        $mappedDepartmentId = ZktecoGroupMapping::getDepartmentId($groupId);

        if ($mappedDepartmentId) {
            return $mappedDepartmentId;
        }

        // Auto-create department and mapping
        $department = Department::firstOrCreate(
            ['code' => 'ZK-' . $groupId],
            [
                'name' => 'Grupo ZKTeco ' . $groupId,
                'is_active' => true,
            ]
        );

        ZktecoGroupMapping::setMapping($groupId, $department->id, 'Auto-created from ZKTeco group ' . $groupId);

        $this->command->info("Auto-created department: {$department->name} for ZKTeco group {$groupId}");

        return $department->id;
    }

    /**
     * Get user_ids with recent attendance.
     */
    private function getUsersWithRecentAttendance(int $days): array
    {
        $stmt = $this->zktecoDb->prepare("
            SELECT DISTINCT user_id
            FROM attendance
            WHERE timestamp >= :from_date
        ");

        $fromDate = Carbon::now()->subDays($days)->toDateTimeString();
        $stmt->execute(['from_date' => $fromDate]);

        return array_column($stmt->fetchAll(), 'user_id');
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
            // Assume: First Last1 Last2 or First1 First2 Last1 Last2
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
     * Import attendance records from ZKTeco.
     */
    private function importAttendance(): void
    {
        // Get date range - last 30 days
        $fromDate = Carbon::now()->subDays(30)->startOfDay();
        $toDate = Carbon::now();

        $this->command->info("Importing attendance from {$fromDate->toDateString()} to {$toDate->toDateString()}");

        // Fetch attendance records
        $stmt = $this->zktecoDb->prepare("
            SELECT
                a.user_id,
                a.timestamp,
                a.punch,
                a.status as auth_method,
                a.device_id
            FROM attendance a
            WHERE a.timestamp >= :from_date
            ORDER BY a.user_id, a.timestamp ASC
        ");

        $stmt->execute(['from_date' => $fromDate->toDateTimeString()]);
        $records = $stmt->fetchAll();

        $this->command->info("Found " . count($records) . " attendance records");

        // Group by user and date
        $grouped = $this->groupRecordsByUserAndDate($records);

        $processed = 0;
        $created = 0;

        $bar = $this->command->getOutput()->createProgressBar(count($grouped));
        $bar->start();

        foreach ($grouped as $userId => $dateRecords) {
            $employee = Employee::where('zkteco_user_id', $userId)->first();

            if (!$employee) {
                $bar->advance();
                continue;
            }

            foreach ($dateRecords as $date => $punches) {
                $wasCreated = $this->processEmployeeDayRecords($employee, $date, $punches);
                $processed++;
                if ($wasCreated) {
                    $created++;
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info("Processed: {$processed}, Created: {$created}");
    }

    /**
     * Group records by user and date.
     */
    private function groupRecordsByUserAndDate(array $records): array
    {
        $grouped = [];

        foreach ($records as $record) {
            $userId = $record['user_id'];
            $date = Carbon::parse($record['timestamp'])->toDateString();

            if (!isset($grouped[$userId])) {
                $grouped[$userId] = [];
            }

            if (!isset($grouped[$userId][$date])) {
                $grouped[$userId][$date] = [];
            }

            $grouped[$userId][$date][] = $record;
        }

        return $grouped;
    }

    /**
     * Process all punches for an employee on a specific date.
     *
     * IMPORTANT: The 'punch' field from ZKTeco is NOT reliable for determining
     * check-in vs check-out. Instead, we use time-based logic:
     * - First punch of the day = check_in (entrada)
     * - Last punch of the day = check_out (salida)
     * - But only if there's at least 1 hour difference between them
     */
    private function processEmployeeDayRecords(Employee $employee, string $date, array $punches): bool
    {
        // Collect ALL punch times (ignore the punch field - it's unreliable)
        $allTimes = [];
        foreach ($punches as $punch) {
            $allTimes[] = Carbon::parse($punch['timestamp'])->format('H:i:s');
        }

        // Sort times chronologically
        sort($allTimes);

        // Get first and last times
        $firstTime = !empty($allTimes) ? $allTimes[0] : null;
        $lastTime = count($allTimes) > 1 ? end($allTimes) : null;

        // Calculate difference between first and last punch
        $firstCheckIn = null;
        $lastCheckOut = null;

        if ($firstTime && $lastTime) {
            $first = Carbon::parse($date . ' ' . $firstTime);
            $last = Carbon::parse($date . ' ' . $lastTime);
            $diffMinutes = $first->diffInMinutes($last);

            // If difference is less than 60 minutes, consider it a single punch event
            if ($diffMinutes < 60) {
                // Determine if it's check-in or check-out based on time of day
                $avgHour = Carbon::parse($firstTime)->hour;
                if ($avgHour < 14) {
                    // Morning punch = check-in only
                    $firstCheckIn = $firstTime;
                    $lastCheckOut = null;
                } else {
                    // Afternoon/evening punch = check-out only
                    $firstCheckIn = null;
                    $lastCheckOut = $lastTime;
                }
            } else {
                // Punches are far apart - treat as check-in and check-out
                $firstCheckIn = $firstTime;
                $lastCheckOut = $lastTime;
            }
        } elseif ($firstTime) {
            // Only one punch
            $hour = Carbon::parse($firstTime)->hour;
            if ($hour < 14) {
                $firstCheckIn = $firstTime;
            } else {
                $lastCheckOut = $firstTime;
            }
        }

        // Get or create attendance record
        $attendance = AttendanceRecord::firstOrNew([
            'employee_id' => $employee->id,
            'work_date' => $date,
        ]);

        $wasCreated = !$attendance->exists;

        // Store raw punches with corrected type based on position
        $rawPunches = [];
        foreach ($punches as $punch) {
            $time = Carbon::parse($punch['timestamp'])->format('H:i:s');
            // Determine type based on whether it matches first or last time
            $type = 'punch'; // default
            if ($time === $firstCheckIn) {
                $type = 'in';
            } elseif ($time === $lastCheckOut) {
                $type = 'out';
            }

            $rawPunches[] = [
                'time' => $time,
                'type' => $type,
                'device' => $punch['device_id'],
                'method' => $this->getAuthMethod($punch['auth_method']),
            ];
        }

        $attendance->check_in = $firstCheckIn;
        $attendance->check_out = $lastCheckOut;
        $attendance->raw_punches = $rawPunches;

        // Check if it's a holiday or weekend
        $attendance->is_holiday = Holiday::isHoliday($date);
        $attendance->is_weekend_work = Carbon::parse($date)->isWeekend();

        $attendance->save();

        // Calculate metrics
        $this->calculateAttendanceMetrics($attendance);

        return $wasCreated;
    }

    /**
     * Calculate attendance metrics for a record.
     */
    private function calculateAttendanceMetrics(AttendanceRecord $attendance): void
    {
        $employee = $attendance->employee;
        $schedule = $employee->schedule;

        if (!$schedule) {
            $attendance->update(['requires_review' => true]);
            return;
        }

        // Check if it's a working day
        $dayName = strtolower(Carbon::parse($attendance->work_date)->format('l'));
        $workingDays = $schedule->working_days ?? ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
        $isWorkingDay = in_array($dayName, $workingDays);

        // If no check-in and it's a working day, mark as absent
        if (!$attendance->check_in && $isWorkingDay && !$attendance->is_holiday) {
            $attendance->update(['status' => 'absent']);
            return;
        }

        // If no check-in (non-working day or holiday), skip calculations
        if (!$attendance->check_in) {
            return;
        }

        // Get the date string safely
        $dateStr = Carbon::parse($attendance->work_date)->toDateString();

        // Helper to extract time from various formats
        $extractTime = function($value) {
            if (empty($value)) return null;
            $str = (string) $value;
            // If it contains a space, it's datetime format - get the time part
            if (strpos($str, ' ') !== false) {
                $parts = explode(' ', $str);
                return trim(end($parts)); // Get last part (the time)
            }
            // Otherwise just return as-is (should be HH:MM:SS)
            return substr($str, 0, 8);
        };

        $checkInTime = $extractTime($attendance->check_in);
        $checkOutTime = $extractTime($attendance->check_out);
        $entryTime = $extractTime($schedule->entry_time);
        $exitTime = $extractTime($schedule->exit_time);

        if (!$checkInTime || !$entryTime) {
            return;
        }

        // Calculate late minutes
        $expectedEntry = Carbon::parse($dateStr . ' ' . $entryTime);
        $actualEntry = Carbon::parse($dateStr . ' ' . $checkInTime);
        $tolerance = $schedule->late_tolerance_minutes ?? 10;

        $lateMinutes = 0;
        if ($actualEntry->gt($expectedEntry->copy()->addMinutes($tolerance))) {
            $lateMinutes = max(0, $expectedEntry->diffInMinutes($actualEntry) - $tolerance);
        }

        // Calculate early departure
        $earlyDeparture = 0;
        if ($checkOutTime && $exitTime) {
            $expectedExit = Carbon::parse($dateStr . ' ' . $exitTime);
            $actualExit = Carbon::parse($dateStr . ' ' . $checkOutTime);

            if ($actualExit->lt($expectedExit)) {
                $earlyDeparture = $expectedExit->diffInMinutes($actualExit);
            }
        }

        // Calculate worked hours
        $workedMinutes = 0;
        if ($checkInTime && $checkOutTime) {
            $checkIn = Carbon::parse($dateStr . ' ' . $checkInTime);
            $checkOut = Carbon::parse($dateStr . ' ' . $checkOutTime);

            // Handle overnight shifts (checkout is next day)
            if ($checkOut->lt($checkIn)) {
                $checkOut->addDay();
            }

            $workedMinutes = $checkIn->diffInMinutes($checkOut);

            // Subtract break time
            $breakMinutes = $schedule->break_minutes ?? 60;
            $workedMinutes -= $breakMinutes;
        }

        $workedHours = max(0, $workedMinutes / 60);
        $dailyHours = $schedule->daily_work_hours ?? 8;
        $regularHours = min($workedHours, $dailyHours);
        $overtimeHours = max(0, $workedHours - $dailyHours);

        // Determine status
        $status = 'present';
        if ($lateMinutes > 0) {
            $status = 'late';
        }
        if ($workedHours < 4 && $workedHours > 0) {
            $status = 'partial';
        }
        if ($attendance->is_holiday) {
            $status = 'holiday';
        }

        // Check for review needs
        $requiresReview = false;
        if (!$checkOutTime && $checkInTime) {
            $requiresReview = true;
        }

        $attendance->update([
            'worked_hours' => round($regularHours, 2),
            'overtime_hours' => round($overtimeHours, 2),
            'late_minutes' => $lateMinutes,
            'early_departure_minutes' => $earlyDeparture,
            'status' => $status,
            'requires_review' => $requiresReview,
        ]);
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
}
