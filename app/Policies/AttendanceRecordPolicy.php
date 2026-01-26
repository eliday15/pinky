<?php

namespace App\Policies;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Authorization policy for AttendanceRecord model.
 *
 * Controls access based on user permissions and team membership:
 * - Admin/RRHH: Full access to all attendance records
 * - Supervisor: Access to team attendance (authorized records only)
 * - Employee: Access only to their own attendance (authorized records only)
 */
class AttendanceRecordPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any attendance records.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission([
            'attendance.view_all',
            'attendance.view_team',
            'attendance.view_own',
        ]);
    }

    /**
     * Determine whether the user can view the attendance record.
     */
    public function view(User $user, AttendanceRecord $record): bool
    {
        // Admin/RRHH can see everything including unauthorized overtime
        if ($user->hasPermissionTo('attendance.view_all')) {
            return true;
        }

        // Supervisor can see team attendance (only authorized records or normal hours)
        if ($user->hasPermissionTo('attendance.view_team')) {
            if (! $this->isInUserTeam($user, $record)) {
                return false;
            }

            return $this->canViewBasedOnAuthorization($record);
        }

        // Employee can see own attendance (only authorized records or normal hours)
        if ($user->hasPermissionTo('attendance.view_own')) {
            if (! $this->isOwnRecord($user, $record)) {
                return false;
            }

            return $this->canViewBasedOnAuthorization($record);
        }

        return false;
    }

    /**
     * Determine whether the user can edit attendance records.
     */
    public function update(User $user, AttendanceRecord $record): bool
    {
        return $user->hasPermissionTo('attendance.edit');
    }

    /**
     * Determine whether the user can sync attendance from ZKTeco.
     */
    public function sync(User $user): bool
    {
        return $user->hasPermissionTo('attendance.sync');
    }

    /**
     * Determine whether the user can approve attendance corrections.
     */
    public function approveCorrections(User $user): bool
    {
        return $user->hasPermissionTo('attendance.approve_corrections');
    }

    /**
     * Check if the record is in the user's team.
     */
    private function isInUserTeam(User $user, AttendanceRecord $record): bool
    {
        $userEmployee = $user->employee;

        if (! $userEmployee) {
            return false;
        }

        $recordEmployee = $record->employee;

        if (! $recordEmployee) {
            return false;
        }

        // Same department
        if ($recordEmployee->department_id === $userEmployee->department_id) {
            return true;
        }

        // Check supervisor relationship
        if ($recordEmployee->supervisor_id === $userEmployee->id) {
            return true;
        }

        return false;
    }

    /**
     * Check if the record belongs to the user's employee.
     */
    private function isOwnRecord(User $user, AttendanceRecord $record): bool
    {
        return $user->employee?->id === $record->employee_id;
    }

    /**
     * Check if record can be viewed based on overtime authorization status.
     *
     * Normal work hours are always visible. Overtime/night shift hours are only visible
     * if they have been authorized (approved or paid).
     */
    private function canViewBasedOnAuthorization(AttendanceRecord $record): bool
    {
        // Normal attendance (without overtime and not night shift) is always visible
        if ($record->overtime_hours <= 0 && ! $record->is_night_shift) {
            return true;
        }

        // If there are overtime hours or night shift, check if they're authorized
        return self::isOvertimeOrNightShiftAuthorized($record);
    }

    /**
     * Check if overtime/night shift hours are authorized for a specific record.
     * This is used by controllers to determine what data to show.
     *
     * According to spec: APPROVED or PAID status means authorized.
     */
    public static function isOvertimeAuthorized(AttendanceRecord $record): bool
    {
        if ($record->overtime_hours <= 0) {
            return false;
        }

        return Authorization::where('employee_id', $record->employee_id)
            ->where('date', $record->work_date)
            ->whereIn('status', [Authorization::STATUS_APPROVED, Authorization::STATUS_PAID])
            ->where('type', Authorization::TYPE_OVERTIME)
            ->exists();
    }

    /**
     * Check if night shift hours are authorized for a specific record.
     */
    public static function isNightShiftAuthorized(AttendanceRecord $record): bool
    {
        if (! $record->is_night_shift) {
            return false;
        }

        return Authorization::where('employee_id', $record->employee_id)
            ->where('date', $record->work_date)
            ->whereIn('status', [Authorization::STATUS_APPROVED, Authorization::STATUS_PAID])
            ->where('type', Authorization::TYPE_NIGHT_SHIFT)
            ->exists();
    }

    /**
     * Check if overtime OR night shift hours are authorized.
     */
    public static function isOvertimeOrNightShiftAuthorized(AttendanceRecord $record): bool
    {
        return Authorization::where('employee_id', $record->employee_id)
            ->where('date', $record->work_date)
            ->whereIn('status', [Authorization::STATUS_APPROVED, Authorization::STATUS_PAID])
            ->whereIn('type', [Authorization::TYPE_OVERTIME, Authorization::TYPE_NIGHT_SHIFT])
            ->exists();
    }
}
