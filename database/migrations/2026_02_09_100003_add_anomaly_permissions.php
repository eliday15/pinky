<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Phase 2: Add anomaly management permissions.
 *
 * Creates permissions for viewing, resolving, and dismissing
 * attendance anomalies and assigns them to the appropriate roles.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $permissions = [
            'anomalies.view_all',
            'anomalies.view_team',
            'anomalies.resolve',
            'anomalies.dismiss',
        ];

        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p]);
        }

        // Assign permissions to roles (only if roles exist — they may be
        // seeded separately in testing environments)
        try {
            $admin = Role::findByName('admin');
            $admin->givePermissionTo($permissions);

            $rrhh = Role::findByName('rrhh');
            $rrhh->givePermissionTo($permissions);

            $supervisor = Role::findByName('supervisor');
            $supervisor->givePermissionTo('anomalies.view_team');
        } catch (\Spatie\Permission\Exceptions\RoleDoesNotExist $e) {
            // Roles will be assigned when RolesPermissionsSeeder runs
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $permissions = [
            'anomalies.view_all',
            'anomalies.view_team',
            'anomalies.resolve',
            'anomalies.dismiss',
        ];

        // Revoke permissions from roles (only if roles exist)
        try {
            $admin = Role::findByName('admin');
            $admin->revokePermissionTo($permissions);

            $rrhh = Role::findByName('rrhh');
            $rrhh->revokePermissionTo($permissions);

            $supervisor = Role::findByName('supervisor');
            $supervisor->revokePermissionTo('anomalies.view_team');
        } catch (\Spatie\Permission\Exceptions\RoleDoesNotExist $e) {
            // Roles may not exist during testing teardown
        }

        // Delete the permissions
        foreach ($permissions as $p) {
            $permission = Permission::findByName($p);
            $permission->delete();
        }
    }
};
