<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Precisión de Elías (2026-11-25): el semiadmin VE TODO el sistema, solo que sin
 * montos. Se le da lectura de nómina, catálogos, configuración, bitácora,
 * desayunos y usuarios; los importes los quita el servidor (money.view) y las
 * acciones siguen cerradas (no recibe *.manage, settings.edit ni payroll.*
 * de escritura). catalogs.view es nuevo: listas y detalle de catálogos sin
 * poder editarlos. Idempotente.
 */
return new class extends Migration
{
    public const READ_PERMISSIONS = [
        'catalogs.view', 'payroll.view_basic', 'settings.view', 'logs.view', 'breakfasts.view', 'users.view',
    ];

    public function up(): void
    {
        $catalogs = Permission::firstOrCreate(['name' => 'catalogs.view', 'guard_name' => 'web']);
        foreach (['superadmin', 'admin'] as $name) {
            Role::where('name', $name)->where('guard_name', 'web')->first()?->givePermissionTo($catalogs);
        }

        $semiadmin = Role::firstOrCreate(['name' => 'semiadmin', 'guard_name' => 'web']);
        $semiadmin->givePermissionTo(
            Permission::whereIn('name', self::READ_PERMISSIONS)->where('guard_name', 'web')->get()
        );

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::where('name', 'semiadmin')->where('guard_name', 'web')->first()
            ?->revokePermissionTo(array_diff(self::READ_PERMISSIONS, ['catalogs.view']));
        Permission::where('name', 'catalogs.view')->where('guard_name', 'web')->delete();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
