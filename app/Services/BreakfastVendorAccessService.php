<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Keeps kiosk access aligned with the employee configured as breakfast vendor.
 */
class BreakfastVendorAccessService
{
    public const ROLE = 'breakfast_vendor';

    /**
     * Validate that a selected vendor can actually operate the authenticated kiosk.
     */
    public function validateVendor(mixed $employeeId, string $attribute): ?Employee
    {
        $employeeId = (int) $employeeId;

        if ($employeeId === 0) {
            return null;
        }

        $employee = Employee::active()->find($employeeId);

        if (! $employee) {
            throw ValidationException::withMessages([
                $attribute => 'El vendedor de desayunos debe ser un empleado activo.',
            ]);
        }

        if (! $employee->user_id || ! $employee->user()->exists()) {
            throw ValidationException::withMessages([
                $attribute => 'El vendedor de desayunos necesita una cuenta de usuario vinculada para usar el kiosco.',
            ]);
        }

        return $employee;
    }

    /**
     * Revoke the managed role from the former vendor and grant it to the new one.
     */
    public function sync(?Employee $newVendor): void
    {
        $role = Role::findOrCreate(self::ROLE, 'web');
        $role->syncPermissions(['breakfasts.register']);

        $newUserId = $newVendor?->user?->id;

        User::role(self::ROLE)
            ->when($newUserId, fn ($query) => $query->whereKeyNot($newUserId))
            ->get()
            ->each(fn (User $user) => $user->removeRole($role));

        if ($newVendor?->user) {
            $newVendor->user->assignRole($role);
        }
    }
}
