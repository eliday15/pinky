<?php

namespace App\Policies;

use App\Models\Incident;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Authorization policy for Incident model.
 *
 * Controls access based on user permissions and team membership:
 * - Admin/RRHH: Full access to all incidents
 * - Supervisor: Access to team incidents with approval rights
 * - Employee: Access only to their own incidents
 */
class IncidentPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any incidents.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission([
            'incidents.view_all',
            'incidents.view_team',
            'incidents.view_own',
        ]);
    }

    /**
     * Determine whether the user can view the incident.
     */
    public function view(User $user, Incident $incident): bool
    {
        if ($user->hasPermissionTo('incidents.view_all')) {
            return true;
        }

        if ($user->hasPermissionTo('incidents.view_team')) {
            return $this->isInUserTeam($user, $incident);
        }

        if ($user->hasPermissionTo('incidents.view_own')) {
            return $this->isOwnIncident($user, $incident);
        }

        return false;
    }

    /**
     * Determine whether the user can create incidents.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('incidents.create');
    }

    /**
     * Determine whether the user can update the incident.
     *
     * Only the creator can update pending incidents.
     * Admin/RRHH can always update.
     */
    public function update(User $user, Incident $incident): bool
    {
        // Admin/RRHH can always update
        if ($user->hasPermissionTo('incidents.view_all')) {
            return true;
        }

        // Creator can update if still pending
        if ($incident->status === 'pending' && $this->isOwnIncident($user, $incident)) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can delete the incident.
     *
     * Only pending incidents can be deleted, and only by creator or admin.
     */
    public function delete(User $user, Incident $incident): bool
    {
        if ($incident->status !== 'pending') {
            return false;
        }

        if ($user->hasPermissionTo('incidents.view_all')) {
            return true;
        }

        return $this->isOwnIncident($user, $incident);
    }

    /**
     * Determine whether the user can approve the incident.
     */
    public function approve(User $user, Incident $incident): bool
    {
        if (! $user->hasPermissionTo('incidents.approve')) {
            return false;
        }

        // Can't approve own incidents (unless admin)
        if ($this->isOwnIncident($user, $incident) && ! $user->hasRole('admin')) {
            return false;
        }

        // Supervisors can only approve team incidents
        if ($user->hasRole('supervisor') && ! $this->isInUserTeam($user, $incident)) {
            return false;
        }

        return $incident->status === 'pending';
    }

    /**
     * Determine whether the user can reject the incident.
     */
    public function reject(User $user, Incident $incident): bool
    {
        if (! $user->hasPermissionTo('incidents.reject')) {
            return false;
        }

        // Can't reject own incidents (unless admin)
        if ($this->isOwnIncident($user, $incident) && ! $user->hasRole('admin')) {
            return false;
        }

        // Supervisors can only reject team incidents
        if ($user->hasRole('supervisor') && ! $this->isInUserTeam($user, $incident)) {
            return false;
        }

        return $incident->status === 'pending';
    }

    /**
     * Check if the incident is in the user's team.
     */
    private function isInUserTeam(User $user, Incident $incident): bool
    {
        $userEmployee = $user->employee;

        if (! $userEmployee) {
            return false;
        }

        $incidentEmployee = $incident->employee;

        if (! $incidentEmployee) {
            return false;
        }

        // Same department
        if ($incidentEmployee->department_id === $userEmployee->department_id) {
            return true;
        }

        // Check supervisor relationship
        if ($incidentEmployee->supervisor_id === $userEmployee->id) {
            return true;
        }

        return false;
    }

    /**
     * Check if the incident belongs to the user's employee.
     */
    private function isOwnIncident(User $user, Incident $incident): bool
    {
        return $user->employee?->id === $incident->employee_id;
    }
}
