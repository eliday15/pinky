<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Authorization policy for User model.
 *
 * Controls access to user management based on permissions.
 */
class UserPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any users.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('users.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, User $model): bool
    {
        return $user->hasPermissionTo('users.view');
    }

    /**
     * Determine whether the user can create users.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('users.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, User $model): bool
    {
        return $user->hasPermissionTo('users.edit');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, User $model): bool
    {
        if (!$user->hasPermissionTo('users.delete')) {
            return false;
        }

        // Cannot delete own user
        if ($user->id === $model->id) {
            return false;
        }

        // Cannot delete last admin
        if ($model->hasRole('admin')) {
            $adminCount = User::role('admin')->count();
            if ($adminCount <= 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine whether the user can reset another user's password.
     */
    public function resetPassword(User $user, User $model): bool
    {
        if (!$user->hasPermissionTo('users.reset_password')) {
            return false;
        }

        // Cannot reset own password from admin panel
        return $user->id !== $model->id;
    }
}
