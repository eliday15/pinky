<?php

namespace App\Policies;

use App\Models\Authorization;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Authorization policy for the Authorization model.
 *
 * Controls access based on user permissions and team membership:
 * - Admin/RRHH: Full access to all authorizations
 * - Supervisor: Access to team authorizations with approval rights
 * - Employee: Access only to their own authorizations
 */
class AuthorizationPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any authorizations.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission([
            'authorizations.view_all',
            'authorizations.view_team',
            'authorizations.view_own',
        ]);
    }

    /**
     * Determine whether the user can view the authorization.
     */
    public function view(User $user, Authorization $authorization): bool
    {
        if ($user->hasPermissionTo('authorizations.view_all')) {
            return true;
        }

        if ($user->hasPermissionTo('authorizations.view_team')) {
            return $this->isInUserTeam($user, $authorization);
        }

        if ($user->hasPermissionTo('authorizations.view_own')) {
            return $this->isOwnAuthorization($user, $authorization);
        }

        return false;
    }

    /**
     * Determine whether the user can create authorizations.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('authorizations.create');
    }

    /**
     * Determine whether the user can update the authorization.
     *
     * Only pending authorizations can be updated, and only by creator or admin.
     */
    public function update(User $user, Authorization $authorization): bool
    {
        if ($authorization->status !== Authorization::STATUS_PENDING) {
            return false;
        }

        if ($user->hasPermissionTo('authorizations.view_all')) {
            return true;
        }

        // Creator can update if still pending
        return $authorization->requested_by === $user->id;
    }

    /**
     * Determine whether the user can delete the authorization.
     *
     * Only pending authorizations can be deleted.
     */
    public function delete(User $user, Authorization $authorization): bool
    {
        if ($authorization->status !== Authorization::STATUS_PENDING) {
            return false;
        }

        if ($user->hasPermissionTo('authorizations.view_all')) {
            return true;
        }

        return $authorization->requested_by === $user->id;
    }

    /**
     * Determine whether the user can approve the authorization.
     */
    public function approve(User $user, Authorization $authorization): bool
    {
        if (! $user->hasPermissionTo('authorizations.approve')) {
            return false;
        }

        // Can't approve own authorizations (unless admin)
        if ($this->isOwnAuthorization($user, $authorization) && ! $user->hasRole('admin')) {
            return false;
        }

        // Supervisors can only approve team authorizations
        if ($user->hasRole('supervisor') && ! $this->isInUserTeam($user, $authorization)) {
            return false;
        }

        return $authorization->isPending();
    }

    /**
     * Determine whether the user can reject the authorization.
     */
    public function reject(User $user, Authorization $authorization): bool
    {
        if (! $user->hasPermissionTo('authorizations.reject')) {
            return false;
        }

        // Can't reject own authorizations (unless admin)
        if ($this->isOwnAuthorization($user, $authorization) && ! $user->hasRole('admin')) {
            return false;
        }

        // Supervisors can only reject team authorizations
        if ($user->hasRole('supervisor') && ! $this->isInUserTeam($user, $authorization)) {
            return false;
        }

        return $authorization->isPending();
    }

    /**
     * Check if the authorization is in the user's team.
     */
    private function isInUserTeam(User $user, Authorization $authorization): bool
    {
        $userEmployee = $user->employee;

        if (! $userEmployee) {
            return false;
        }

        $authEmployee = $authorization->employee;

        if (! $authEmployee) {
            return false;
        }

        // Same department
        if ($authEmployee->department_id === $userEmployee->department_id) {
            return true;
        }

        // Check supervisor relationship
        if ($authEmployee->supervisor_id === $userEmployee->id) {
            return true;
        }

        return false;
    }

    /**
     * Check if the authorization belongs to the user's employee.
     */
    private function isOwnAuthorization(User $user, Authorization $authorization): bool
    {
        return $user->employee?->id === $authorization->employee_id;
    }
}
