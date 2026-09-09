<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSION = 'reports.view_overtime_amounts';

    private const LUIS_EMAIL = 'contacto@vestidospinky.com';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::firstOrCreate([
            'name' => self::PERMISSION,
            'guard_name' => 'web',
        ]);

        foreach (['superadmin', 'admin'] as $roleName) {
            Role::where('name', $roleName)
                ->where('guard_name', 'web')
                ->first()
                ?->givePermissionTo($permission);
        }

        User::whereRaw('LOWER(email) = ?', [self::LUIS_EMAIL])
            ->first()
            ?->givePermissionTo($permission);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::where('name', self::PERMISSION)
            ->where('guard_name', 'web')
            ->first();

        if (! $permission) {
            return;
        }

        User::whereRaw('LOWER(email) = ?', [self::LUIS_EMAIL])
            ->first()
            ?->revokePermissionTo($permission);

        foreach (['superadmin', 'admin'] as $roleName) {
            Role::where('name', $roleName)
                ->where('guard_name', 'web')
                ->first()
                ?->revokePermissionTo($permission);
        }

        $permission->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
