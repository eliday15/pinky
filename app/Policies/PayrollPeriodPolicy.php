<?php

namespace App\Policies;

use App\Models\PayrollPeriod;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Authorization policy for PayrollPeriod model.
 *
 * Controls access based on user permissions:
 * - Admin/RRHH: Full access including complete payroll view
 * - Supervisor: Basic payroll view (team only)
 * - Employee: Basic payroll view (own only)
 */
class PayrollPeriodPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any payroll periods.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission([
            'payroll.view_basic',
            'payroll.view_complete',
        ]);
    }

    /**
     * Determine whether the user can view the payroll period.
     */
    public function view(User $user, PayrollPeriod $period): bool
    {
        return $user->hasAnyPermission([
            'payroll.view_basic',
            'payroll.view_complete',
        ]);
    }

    /**
     * Determine whether the user can view complete payroll details.
     *
     * Complete payroll includes:
     * - Overtime hours (authorized)
     * - Night shift hours (authorized)
     * - Dinner allowances
     * - Extra day pay
     * - Holiday pay
     * - Weekend pay
     * - Weekly/monthly bonuses
     */
    public function viewComplete(User $user): bool
    {
        return $user->hasPermissionTo('payroll.view_complete');
    }

    /**
     * Determine whether the user can view basic payroll details.
     *
     * Basic payroll includes:
     * - Punctuality bonus (breakfast)
     * - Attendance count
     * - Late arrivals (monthly)
     * - Absences (monthly)
     * - Absences from lates (monthly)
     * - Vacation days
     * - Sick leave days
     */
    public function viewBasic(User $user): bool
    {
        return $user->hasPermissionTo('payroll.view_basic');
    }

    /**
     * Determine whether the user can create payroll periods.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('payroll.create');
    }

    /**
     * Determine whether the user can calculate payroll.
     */
    public function calculate(User $user, PayrollPeriod $period): bool
    {
        return $user->hasPermissionTo('payroll.calculate');
    }

    /**
     * Determine whether the user can approve payroll.
     */
    public function approve(User $user, PayrollPeriod $period): bool
    {
        return $user->hasPermissionTo('payroll.approve');
    }

    /**
     * Determine whether the user can export payroll.
     */
    public function export(User $user, PayrollPeriod $period): bool
    {
        return $user->hasPermissionTo('payroll.export');
    }

    /**
     * Determine whether the user can delete the payroll period.
     *
     * Only draft payroll periods can be deleted.
     */
    public function delete(User $user, PayrollPeriod $period): bool
    {
        if ($period->status !== 'draft') {
            return false;
        }

        return $user->hasPermissionTo('payroll.create');
    }
}
