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
     * Resultados del candado de aprobadores por concepto.
     *
     * GATE_OPEN  = el concepto no restringe (lista vacía o sin concepto):
     *              se aplican las reglas de siempre (permiso + equipo).
     * GATE_NAMED = el usuario fue nombrado aprobador del concepto (o es
     *              superadmin): queda facultado aunque no tenga el permiso
     *              general ni sea el supervisor del equipo.
     * GATE_DENIED = el concepto restringe y este usuario no está en la lista.
     */
    private const GATE_OPEN = 'open';

    private const GATE_NAMED = 'named';

    private const GATE_DENIED = 'denied';

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
        $gate = $this->conceptApproverGate($user, $authorization);

        if ($gate === self::GATE_DENIED) {
            return false;
        }

        // Un aprobador NOMBRADO en el concepto no necesita el permiso general:
        // el superadmin lo facultó explícitamente para este concepto.
        if ($gate === self::GATE_OPEN && ! $user->hasPermissionTo('authorizations.approve')) {
            return false;
        }

        // Can't approve own authorizations (unless admin)
        if ($this->isOwnAuthorization($user, $authorization) && ! $user->hasAnyRole(['superadmin', 'admin'])) {
            return false;
        }

        // Supervisors can only approve team authorizations — salvo que hayan
        // sido nombrados aprobadores del concepto, en cuyo caso aprueban el
        // concepto completo, no sólo el de su equipo.
        if ($gate === self::GATE_OPEN && $user->hasRole('supervisor') && ! $this->isInUserTeam($user, $authorization)) {
            return false;
        }

        // Full-access admins (RRHH/admin) can also modify an existing approval or
        // re-approve a rejected one — partial approval and post-hoc adjustments —
        // for anything that isn't already paid out.
        if ($user->hasPermissionTo('authorizations.view_all')) {
            return ! $authorization->isPaid();
        }

        return $authorization->isPending();
    }

    /**
     * Determine whether the user can reject the authorization.
     */
    public function reject(User $user, Authorization $authorization): bool
    {
        // El candado de aprobadores aplica igual al rechazo: quien no puede
        // aprobar un concepto restringido tampoco decide rechazarlo.
        $gate = $this->conceptApproverGate($user, $authorization);

        if ($gate === self::GATE_DENIED) {
            return false;
        }

        if ($gate === self::GATE_OPEN && ! $user->hasPermissionTo('authorizations.reject')) {
            return false;
        }

        // Can't reject own authorizations (unless admin)
        if ($this->isOwnAuthorization($user, $authorization) && ! $user->hasAnyRole(['superadmin', 'admin'])) {
            return false;
        }

        // Supervisors can only reject team authorizations — salvo aprobador
        // nombrado del concepto.
        if ($gate === self::GATE_OPEN && $user->hasRole('supervisor') && ! $this->isInUserTeam($user, $authorization)) {
            return false;
        }

        // Full-access admins (RRHH/admin) can also reject an already-approved
        // authorization — reverting the approval — for anything not yet paid
        // out (and not already rejected). Mirrors approve()'s post-hoc power.
        if ($user->hasPermissionTo('authorizations.view_all')) {
            return ! $authorization->isPaid() && ! $authorization->isRejected();
        }

        return $authorization->isPending();
    }

    /**
     * Resolve the concept-level approver gate for this user/authorization.
     *
     * El superadmin nunca se queda fuera: si el aprobador nombrado se va de
     * vacaciones o lo dan de baja, el concepto no queda bloqueado.
     *
     * Args:
     *     user: The user attempting to approve or reject
     *     authorization: The authorization whose concept carries the list
     *
     * Returns:
     *     One of GATE_OPEN, GATE_NAMED or GATE_DENIED
     */
    private function conceptApproverGate(User $user, Authorization $authorization): string
    {
        $concept = $authorization->compensationType;

        if (! $concept || ! $concept->hasRestrictedApprovers()) {
            return self::GATE_OPEN;
        }

        if ($user->hasRole('superadmin') || $concept->allowsApprover($user)) {
            return self::GATE_NAMED;
        }

        return self::GATE_DENIED;
    }

    /**
     * Check if the authorization is in the user's team (direct reports only).
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

        return in_array($authEmployee->id, $userEmployee->allSubordinateIds(), true);
    }

    /**
     * Check if the authorization belongs to the user's employee.
     */
    private function isOwnAuthorization(User $user, Authorization $authorization): bool
    {
        return $user->employee?->id === $authorization->employee_id;
    }
}
