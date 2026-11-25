<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Rol SEMIADMIN (Luis 2026-11-25, para Daniela): debajo del admin. Da de alta
 * y de baja empleados y autoriza lo humano (asistencia, incidencias, TE fuera
 * de checada…), pero NADA de dinero: no ve montos ni crea compensaciones.
 *
 * Ver dinero pasa a ser el permiso money.view. Se le da a TODOS los roles que
 * ya existían, así que para ellos nada cambia; el semiadmin no lo tiene.
 * Idempotente: el deploy puede correrla de nuevo sin daño.
 */
return new class extends Migration
{
    public const SEMIADMIN_PERMISSIONS = [
        'employees.view_all', 'employees.create', 'employees.edit', 'employees.edit_personal',
        'attendance.view_all', 'attendance.edit', 'attendance.sync', 'attendance.approve_corrections',
        'incidents.view_all', 'incidents.create', 'incidents.approve', 'incidents.reject',
        'vacation_hours.manage', 'deliveries.manage',
        'authorizations.view_all', 'authorizations.create', 'authorizations.approve', 'authorizations.reject',
        'authorizations.suggest_from_checadas',
        'anomalies.view_all', 'anomalies.resolve', 'anomalies.dismiss',
        'check_omissions.view_all', 'check_omissions.create', 'check_omissions.approve',
        'reports.view_all',
        'payroll.preview',
    ];

    public function up(): void
    {
        $money = Permission::firstOrCreate(['name' => 'money.view', 'guard_name' => 'web']);

        Role::where('guard_name', 'web')
            ->where('name', '!=', 'semiadmin')
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($money));

        $semiadmin = Role::firstOrCreate(['name' => 'semiadmin', 'guard_name' => 'web']);
        $existing = Permission::whereIn('name', self::SEMIADMIN_PERMISSIONS)->where('guard_name', 'web')->get();
        $semiadmin->syncPermissions($existing);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::where('name', 'semiadmin')->where('guard_name', 'web')->delete();
        Permission::where('name', 'money.view')->where('guard_name', 'web')->delete();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
