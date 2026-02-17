<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Seeds roles and permissions for the PINKY HR system.
 *
 * Roles:
 * - admin: Full system access
 * - rrhh: Human Resources - manages employees, attendance, payroll, incidents
 * - supervisor: Department-level access to team data and approvals
 * - employee: Self-service access only
 */
class RolesPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Define all permissions by module
        $permissions = [
            // Empleados
            'employees.view_all',
            'employees.view_team',
            'employees.view_own',
            'employees.create',
            'employees.edit',
            'employees.delete',
            'employees.view_salary',
            'employees.bulk_edit',

            // Asistencia
            'attendance.view_all',
            'attendance.view_team',
            'attendance.view_own',
            'attendance.edit',
            'attendance.sync',
            'attendance.approve_corrections',

            // Incidencias/Vacaciones
            'incidents.view_all',
            'incidents.view_team',
            'incidents.view_own',
            'incidents.create',
            'incidents.approve',
            'incidents.reject',

            // Autorizaciones (Horas Extra, Veladas, Permisos)
            'authorizations.view_all',
            'authorizations.view_team',
            'authorizations.view_own',
            'authorizations.create',
            'authorizations.approve',
            'authorizations.reject',

            // Nómina
            'payroll.view_basic',
            'payroll.view_complete',
            'payroll.create',
            'payroll.calculate',
            'payroll.approve',
            'payroll.export',

            // Reportes
            'reports.view_all',
            'reports.view_team',
            'reports.view_own',

            // Configuración
            'settings.view',
            'settings.edit',
            'schedules.manage',
            'departments.manage',
            'positions.manage',
            'compensation_types.manage',
            'holidays.manage',

            // Logs/Auditoría
            'logs.view',
        ];

        // Create permissions
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Create Admin role with all permissions
        $admin = Role::firstOrCreate(['name' => 'admin']);
        $admin->syncPermissions(Permission::all());

        // Create RRHH role - Full HR management except system configuration
        $rrhh = Role::firstOrCreate(['name' => 'rrhh']);
        $rrhh->syncPermissions([
            // Empleados - full access
            'employees.view_all',
            'employees.create',
            'employees.edit',
            'employees.delete',
            'employees.view_salary',
            'employees.bulk_edit',
            // Asistencia - full access
            'attendance.view_all',
            'attendance.edit',
            'attendance.sync',
            'attendance.approve_corrections',
            // Incidencias - full access
            'incidents.view_all',
            'incidents.create',
            'incidents.approve',
            'incidents.reject',
            // Autorizaciones - full access
            'authorizations.view_all',
            'authorizations.create',
            'authorizations.approve',
            'authorizations.reject',
            // Nómina - full access
            'payroll.view_basic',
            'payroll.view_complete',
            'payroll.create',
            'payroll.calculate',
            'payroll.approve',
            'payroll.export',
            // Reportes - full access
            'reports.view_all',
            // Config - limited
            'schedules.manage',
            'departments.manage',
            'positions.manage',
            'compensation_types.manage',
            'holidays.manage',
            // Logs
            'logs.view',
        ]);

        // Create Supervisor role - Limited to Incidents and Authorizations only
        $supervisor = Role::firstOrCreate(['name' => 'supervisor']);
        $supervisor->syncPermissions([
            // Incidencias - team + approval (supervisors can manage their team's incidents)
            'incidents.view_team',
            'incidents.create',
            'incidents.approve',
            'incidents.reject',
            // Autorizaciones - team + approval (supervisors can manage their team's authorizations)
            'authorizations.view_team',
            'authorizations.create',
            'authorizations.approve',
            'authorizations.reject',
        ]);

        // Create Employee role - Self-service only
        $employee = Role::firstOrCreate(['name' => 'employee']);
        $employee->syncPermissions([
            // Empleados - own only
            'employees.view_own',
            // Asistencia - own only
            'attendance.view_own',
            // Incidencias - own + create
            'incidents.view_own',
            'incidents.create',
            // Autorizaciones - own + create
            'authorizations.view_own',
            'authorizations.create',
            // Nómina - basic only
            'payroll.view_basic',
            // Reportes - own only
            'reports.view_own',
        ]);
    }
}
