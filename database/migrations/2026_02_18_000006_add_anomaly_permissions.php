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
