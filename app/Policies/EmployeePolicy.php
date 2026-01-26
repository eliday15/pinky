<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Authorization policy for Employee model.
 *
 * Controls access based on user permissions and team membership:
 * - Admin/RRHH: Full access to all employees
 * - Supervisor: Access to employees in their department
 * - Employee: Access only to their own record
 */
class EmployeePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any employees.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission([
            'employees.view_all',
            'employees.view_team',
            'employees.view_own',
        ]);
    }

    /**
     * Determine whether the user can view the employee.
     */
    public function view(User $user, Employee $employee): bool
    {
        if ($user->hasPermissionTo('employees.view_all')) {
            return true;
        }

        if ($user->hasPermissionTo('employees.view_team')) {
            return $this->isInUserTeam($user, $employee);
        }

        if ($user->hasPermissionTo('employees.view_own')) {
            return $this->isOwnEmployee($user, $employee);
        }

        return false;
    }

    /**
     * Determine whether the user can create employees.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('employees.create');
    }

    /**
     * Determine whether the user can update the employee.
     */
    public function update(User $user, Employee $employee): bool
    {
        return $user->hasPermissionTo('employees.edit');
    }

    /**
     * Determine whether the user can delete the employee.
     */
    public function delete(User $user, Employee $employee): bool
    {
        return $user->hasPermissionTo('employees.delete');
    }

    /**
     * Determine whether the user can view employee salary information.
     */
    public function viewSalary(User $user, Employee $employee): bool
    {
        if ($user->hasPermissionTo('employees.view_salary')) {
            return true;
        }

        // Employees can see their own salary
        if ($this->isOwnEmployee($user, $employee)) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can perform bulk edits on employees.
     */
    public function bulkEdit(User $user): bool
    {
        return $user->hasPermissionTo('employees.bulk_edit');
    }

    /**
     * Check if the employee is in the user's team (same department).
     */
    private function isInUserTeam(User $user, Employee $employee): bool
    {
        $userEmployee = $user->employee;

        if (! $userEmployee) {
            return false;
        }

        // Same department check
        if ($employee->department_id === $userEmployee->department_id) {
            return true;
        }

        // Check if user's employee is the supervisor of the target employee
        if ($employee->supervisor_id === $userEmployee->id) {
            return true;
        }

        return false;
    }

    /**
     * Check if the employee belongs to the user.
     */
    private function isOwnEmployee(User $user, Employee $employee): bool
    {
        return $user->employee?->id === $employee->id;
    }
}
