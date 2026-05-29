<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Add missing anomaly permissions and assign them to roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Create anomaly permissions
        $permissions = [
            'anomalies.view_all',
            'anomalies.view_team',
            'anomalies.resolve',
            'anomalies.dismiss',
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        // Clear permission cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Assign permissions to roles only if the roles already exist. On a
        // fresh migration (e.g. testing / CI) the roles are created later by
        // RolesPermissionsSeeder, which already grants these permissions.
        try {
            // Admin gets all permissions automatically via syncPermissions(Permission::all()) in seeder
            // But for existing DBs, we need to assign explicitly
            $admin = Role::findByName('admin');
            $admin->givePermissionTo($permissions);

            // RRHH gets view_all, resolve, dismiss
            $rrhh = Role::findByName('rrhh');
            $rrhh->givePermissionTo(['anomalies.view_all', 'anomalies.resolve', 'anomalies.dismiss']);

            // Supervisor gets view_team, resolve
            $supervisor = Role::findByName('supervisor');
            $supervisor->givePermissionTo(['anomalies.view_team', 'anomalies.resolve']);
        } catch (\Spatie\Permission\Exceptions\RoleDoesNotExist $e) {
            // Roles will be assigned when RolesPermissionsSeeder runs.
        }
    }

    public function down(): void
    {
        $permissions = [
            'anomalies.view_all',
            'anomalies.view_team',
            'anomalies.resolve',
            'anomalies.dismiss',
        ];

        foreach ($permissions as $perm) {
            $permission = Permission::findByName($perm, 'web');
            if ($permission) {
                $permission->delete();
            }
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
