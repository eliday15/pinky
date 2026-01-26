<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Incident;
use App\Models\LateAccumulation;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\SystemSetting;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PayrollCalculatorService
{
    /**
     * Calculate payroll for all active employees in a period.
     */
    public function calculatePeriod(PayrollPeriod $period): void
    {
        $period->update(['status' => 'calculating']);

        $employees = Employee::active()->get();

        foreach ($employees as $employee) {
            $this->calculateEmployeePayroll($period, $employee);
        }

        $period->update(['status' => 'review']);
    }

    /**
     * Calculate payroll for a single employee in a period.
     */
    public function calculateEmployeePayroll(PayrollPeriod $period, Employee $employee): PayrollEntry
    {
        $startDate = Carbon::parse($period->start_date);
        $endDate = Carbon::parse($period->end_date);

        // Get attendance records for the period
        $attendance = AttendanceRecord::where('employee_id', $employee->id)
            ->whereBetween('work_date', [$startDate, $endDate])
            ->get();

        // Get approved incidents for the period
        $incidents = Incident::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('start_date', [$startDate, $endDate])
                    ->orWhereBetween('end_date', [$startDate, $endDate])
                    ->orWhere(function ($q2) use ($startDate, $endDate) {
                        $q2->where('start_date', '<=', $startDate)
                            ->where('end_date', '>=', $endDate);
                    });
            })
            ->with('incidentType')
            ->get();

        // Get holidays in the period
        $holidays = Holiday::whereBetween('date', [$startDate, $endDate])->get();
        $holidayDates = $holidays->pluck('date')->map(fn($d) => Carbon::parse($d)->toDateString())->toArray();

        // Calculate attendance metrics
        $metrics = $this->calculateAttendanceMetrics($attendance, $employee, $holidayDates);

        // Calculate incident days
        $incidentMetrics = $this->calculateIncidentMetrics($incidents, $startDate, $endDate);

        // FASE 3.1: Check late accumulation and add generated absences
        $lateAbsencesGenerated = $this->calculateLateAbsences($employee, $period);

        // Get rates
        $hourlyRate = $employee->hourly_rate;
        $overtimeMultiplier = $employee->overtime_rate ?? 1.5;
        $holidayMultiplier = $employee->holiday_rate ?? 2.0;

        // FASE 3.2: Calculate punctuality bonus
        $punctualityBonusAmount = (float) SystemSetting::get('punctuality_bonus_amount', 50);
        $punctualityBonus = $metrics['punctual_days'] * $punctualityBonusAmount;

        // FASE 3.2: Calculate weekly and monthly bonuses
        $weeklyBonus = $this->calculateWeeklyBonus($employee, $period, $attendance);
        $monthlyBonus = $this->calculateMonthlyBonus($employee, $period, $attendance);

        // FASE 3.3: Calculate night shifts and dinner allowances
        $nightShiftMetrics = $this->calculateNightShiftMetrics($employee, $startDate, $endDate);

        // Calculate pay
        $regularPay = $metrics['regular_hours'] * $hourlyRate;
        $overtimePay = $metrics['overtime_hours'] * $hourlyRate * $overtimeMultiplier;
        $holidayPay = $metrics['holiday_hours'] * $hourlyRate * $holidayMultiplier;
        $weekendPay = $metrics['weekend_hours'] * $hourlyRate * $overtimeMultiplier;
        $vacationPay = $incidentMetrics['vacation_days'] * ($hourlyRate * 8);

        // Calculate total bonuses
        $totalBonuses = $punctualityBonus + $weeklyBonus + $monthlyBonus
            + $nightShiftMetrics['night_shift_bonus']
            + $nightShiftMetrics['dinner_allowance'];

        // Calculate deductions (unpaid absences + late-generated absences)
        $totalAbsences = $incidentMetrics['unpaid_days'] + $lateAbsencesGenerated;
        $deductions = $totalAbsences * ($hourlyRate * 8);

        $grossPay = $regularPay + $overtimePay + $holidayPay + $weekendPay + $vacationPay + $totalBonuses;
        $netPay = $grossPay - $deductions;

        // Build calculation breakdown for transparency
        $breakdown = [
            'attendance' => [
                'records' => $attendance->count(),
                'regular_hours' => $metrics['regular_hours'],
                'overtime_hours' => $metrics['overtime_hours'],
                'holiday_hours' => $metrics['holiday_hours'],
                'weekend_hours' => $metrics['weekend_hours'],
                'punctual_days' => $metrics['punctual_days'],
            ],
            'incidents' => [
                'vacation_days' => $incidentMetrics['vacation_days'],
                'sick_leave_days' => $incidentMetrics['sick_leave_days'],
                'permission_days' => $incidentMetrics['permission_days'],
                'absence_days' => $incidentMetrics['absence_days'],
                'unpaid_days' => $incidentMetrics['unpaid_days'],
            ],
            'late_accumulation' => [
                'late_absences_generated' => $lateAbsencesGenerated,
            ],
            'night_shifts' => [
                'hours' => $nightShiftMetrics['night_shift_hours'],
                'days' => $nightShiftMetrics['night_shift_days'],
                'bonus' => $nightShiftMetrics['night_shift_bonus'],
                'dinner_allowance' => $nightShiftMetrics['dinner_allowance'],
            ],
            'bonuses' => [
                'punctuality' => $punctualityBonus,
                'weekly' => $weeklyBonus,
                'monthly' => $monthlyBonus,
                'total' => $totalBonuses,
            ],
            'rates' => [
                'hourly' => $hourlyRate,
                'overtime_multiplier' => $overtimeMultiplier,
                'holiday_multiplier' => $holidayMultiplier,
            ],
            'calculations' => [
                'regular_pay' => $regularPay,
                'overtime_pay' => $overtimePay,
                'holiday_pay' => $holidayPay,
                'weekend_pay' => $weekendPay,
                'vacation_pay' => $vacationPay,
                'gross_pay' => $grossPay,
                'deductions' => $deductions,
                'net_pay' => $netPay,
            ],
        ];

        // Create or update payroll entry
        return PayrollEntry::updateOrCreate(
            [
                'payroll_period_id' => $period->id,
                'employee_id' => $employee->id,
            ],
            [
                'hourly_rate' => $hourlyRate,
                'overtime_multiplier' => $overtimeMultiplier,
                'holiday_multiplier' => $holidayMultiplier,
                'regular_hours' => $metrics['regular_hours'],
                'overtime_hours' => $metrics['overtime_hours'],
                'holiday_hours' => $metrics['holiday_hours'],
                'weekend_hours' => $metrics['weekend_hours'],
                'night_shift_hours' => $nightShiftMetrics['night_shift_hours'],
                'days_worked' => $metrics['days_worked'],
                'days_absent' => $metrics['days_absent'] + $lateAbsencesGenerated,
                'days_late' => $metrics['days_late'],
                'punctuality_days' => $metrics['punctual_days'],
                'night_shift_days' => $nightShiftMetrics['night_shift_days'],
                'late_absences_generated' => $lateAbsencesGenerated,
                'vacation_days_paid' => $incidentMetrics['vacation_days'],
                'regular_pay' => $regularPay,
                'overtime_pay' => $overtimePay,
                'holiday_pay' => $holidayPay,
                'weekend_pay' => $weekendPay,
                'vacation_pay' => $vacationPay,
                'punctuality_bonus' => $punctualityBonus,
                'dinner_allowance' => $nightShiftMetrics['dinner_allowance'],
                'night_shift_bonus' => $nightShiftMetrics['night_shift_bonus'],
                'weekly_bonus' => $weeklyBonus,
                'monthly_bonus' => $monthlyBonus,
                'bonuses' => $totalBonuses,
                'deductions' => $deductions,
                'gross_pay' => $grossPay,
                'net_pay' => $netPay,
                'calculation_breakdown' => $breakdown,
            ]
        );
    }

    /**
     * FASE 3.1: Calculate absences generated from late accumulation.
     *
     * @param Employee $employee Employee to check
     * @param PayrollPeriod $period Payroll period
     * @return int Number of absences generated from late accumulation
     */
    private function calculateLateAbsences(Employee $employee, PayrollPeriod $period): int
    {
        $startDate = Carbon::parse($period->start_date);

        // Get late accumulation for the period's month
        $lateAccumulation = LateAccumulation::where('employee_id', $employee->id)
            ->where('year', $startDate->year)
            ->where('week', $startDate->weekOfYear)
            ->first();

        if (!$lateAccumulation) {
            return 0;
        }

        // Get configurable threshold
        $lateToAbsenceCount = (int) SystemSetting::get('late_to_absence_count', 6);

        if ($lateAccumulation->late_count >= $lateToAbsenceCount && !$lateAccumulation->absence_generated) {
            $absencesGenerated = floor($lateAccumulation->late_count / $lateToAbsenceCount);

            // Mark the accumulation as processed
            $lateAccumulation->update(['absence_generated' => true]);

            return (int) $absencesGenerated;
        }

        return 0;
    }

    /**
     * FASE 3.2: Calculate weekly bonus based on perfect attendance.
     *
     * @param Employee $employee Employee
     * @param PayrollPeriod $period Payroll period
     * @param Collection $attendance Attendance records
     * @return float Weekly bonus amount
     */
    private function calculateWeeklyBonus(Employee $employee, PayrollPeriod $period, Collection $attendance): float
    {
        $weeklyBonusAmount = (float) SystemSetting::get('weekly_bonus_amount', 0);
        if ($weeklyBonusAmount <= 0) {
            return 0;
        }

        // Group attendance by week and check for perfect attendance
        $weeklyPerfect = 0;
        $attendanceByWeek = $attendance->groupBy(fn ($record) => Carbon::parse($record->work_date)->weekOfYear);

        foreach ($attendanceByWeek as $weekRecords) {
            $hasAbsence = $weekRecords->contains(fn ($r) => $r->status === 'absent');
            $hasLate = $weekRecords->contains(fn ($r) => $r->status === 'late');

            if (!$hasAbsence && !$hasLate) {
                $weeklyPerfect++;
            }
        }

        return $weeklyPerfect * $weeklyBonusAmount;
    }

    /**
     * FASE 3.2: Calculate monthly bonus based on perfect attendance.
     *
     * @param Employee $employee Employee
     * @param PayrollPeriod $period Payroll period
     * @param Collection $attendance Attendance records
     * @return float Monthly bonus amount
     */
    private function calculateMonthlyBonus(Employee $employee, PayrollPeriod $period, Collection $attendance): float
    {
        $monthlyBonusAmount = (float) SystemSetting::get('monthly_bonus_amount', 0);
        if ($monthlyBonusAmount <= 0) {
            return 0;
        }

        // Check for perfect attendance in the period
        $hasAbsence = $attendance->contains(fn ($r) => $r->status === 'absent');
        $hasLate = $attendance->contains(fn ($r) => $r->status === 'late');

        if (!$hasAbsence && !$hasLate) {
            return $monthlyBonusAmount;
        }

        return 0;
    }

    /**
     * FASE 3.3: Calculate night shift metrics including hours, bonus, and dinner allowance.
     *
     * @param Employee $employee Employee
     * @param Carbon $startDate Start date
     * @param Carbon $endDate End date
     * @return array Night shift metrics
     */
    private function calculateNightShiftMetrics(Employee $employee, Carbon $startDate, Carbon $endDate): array
    {
        $nightShiftBonus = (float) SystemSetting::get('night_shift_bonus', 100);
        $dinnerAllowanceAmount = (float) SystemSetting::get('dinner_allowance_amount', 75);

        // Get approved night shift authorizations for the period
        $approvedNightShifts = Authorization::where('employee_id', $employee->id)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->where('type', Authorization::TYPE_NIGHT_SHIFT)
            ->where('status', Authorization::STATUS_APPROVED)
            ->get();

        $nightShiftHours = $approvedNightShifts->sum('hours');
        $nightShiftDays = $approvedNightShifts->count();

        return [
            'night_shift_hours' => round($nightShiftHours, 2),
            'night_shift_days' => $nightShiftDays,
            'night_shift_bonus' => $nightShiftDays * $nightShiftBonus,
            'dinner_allowance' => $nightShiftDays * $dinnerAllowanceAmount,
        ];
    }

    /**
     * Calculate attendance metrics for the period.
     */
    private function calculateAttendanceMetrics(Collection $attendance, Employee $employee, array $holidayDates): array
    {
        $regularHours = 0;
        $overtimeHours = 0;
        $holidayHours = 0;
        $weekendHours = 0;
        $daysWorked = 0;
        $daysAbsent = 0;
        $daysLate = 0;
        $punctualDays = 0;

        foreach ($attendance as $record) {
            $workDate = Carbon::parse($record->work_date);
            $isHoliday = in_array($record->work_date, $holidayDates);
            $isWeekend = $workDate->isWeekend();

            if ($record->status === 'absent') {
                $daysAbsent++;
                continue;
            }

            if ($record->status === 'late') {
                $daysLate++;
            }

            // Count punctual days based on the qualifies_for_punctuality_bonus flag
            if ($record->qualifies_for_punctuality_bonus) {
                $punctualDays++;
            }

            if (in_array($record->status, ['present', 'late', 'partial'])) {
                $daysWorked++;

                $workedHours = (float) $record->worked_hours;
                $overtime = (float) $record->overtime_hours;

                if ($isHoliday) {
                    $holidayHours += $workedHours + $overtime;
                } elseif ($isWeekend) {
                    $weekendHours += $workedHours + $overtime;
                } else {
                    $regularHours += $workedHours;
                    $overtimeHours += $overtime;
                }
            }
        }

        return [
            'regular_hours' => round($regularHours, 2),
            'overtime_hours' => round($overtimeHours, 2),
            'holiday_hours' => round($holidayHours, 2),
            'weekend_hours' => round($weekendHours, 2),
            'days_worked' => $daysWorked,
            'days_absent' => $daysAbsent,
            'days_late' => $daysLate,
            'punctual_days' => $punctualDays,
        ];
    }

    /**
     * Calculate incident-related days for the period.
     */
    private function calculateIncidentMetrics(Collection $incidents, Carbon $startDate, Carbon $endDate): array
    {
        $vacationDays = 0;
        $sickLeaveDays = 0;
        $permissionDays = 0;
        $absenceDays = 0;
        $unpaidDays = 0;

        foreach ($incidents as $incident) {
            $incidentStart = Carbon::parse($incident->start_date);
            $incidentEnd = Carbon::parse($incident->end_date);

            // Calculate overlapping days with the period
            $overlapStart = $incidentStart->max($startDate);
            $overlapEnd = $incidentEnd->min($endDate);
            $days = $overlapStart->diffInDays($overlapEnd) + 1;

            if ($days <= 0) continue;

            $category = $incident->incidentType->category;
            $isPaid = $incident->incidentType->is_paid;

            switch ($category) {
                case 'vacation':
                    $vacationDays += $days;
                    break;
                case 'sick_leave':
                    $sickLeaveDays += $days;
                    break;
                case 'permission':
                    $permissionDays += $days;
                    if (!$isPaid) {
                        $unpaidDays += $days;
                    }
                    break;
                case 'absence':
                case 'late_accumulation':
                    $absenceDays += $days;
                    $unpaidDays += $days;
                    break;
            }
        }

        return [
            'vacation_days' => $vacationDays,
            'sick_leave_days' => $sickLeaveDays,
            'permission_days' => $permissionDays,
            'absence_days' => $absenceDays,
            'unpaid_days' => $unpaidDays,
        ];
    }

    /**
     * Get period summary statistics.
     */
    public function getPeriodSummary(PayrollPeriod $period): array
    {
        $entries = $period->entries()->with('employee.department')->get();

        $totalGross = $entries->sum('gross_pay');
        $totalNet = $entries->sum('net_pay');
        $totalDeductions = $entries->sum('deductions');
        $totalOvertime = $entries->sum('overtime_pay');
        $employeeCount = $entries->count();

        $byDepartment = $entries->groupBy('employee.department.name')->map(function ($group) {
            return [
                'count' => $group->count(),
                'total_gross' => $group->sum('gross_pay'),
                'total_net' => $group->sum('net_pay'),
            ];
        });

        return [
            'employee_count' => $employeeCount,
            'total_gross' => $totalGross,
            'total_net' => $totalNet,
            'total_deductions' => $totalDeductions,
            'total_overtime' => $totalOvertime,
            'average_pay' => $employeeCount > 0 ? $totalNet / $employeeCount : 0,
            'by_department' => $byDepartment,
        ];
    }
}
