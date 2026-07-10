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
            'employees.edit_personal',
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
            // Bolsa de horas a cuenta de vacaciones (RRHH convierte días → horas)
            'vacation_hours.manage',

            // Autorizaciones (Horas Extra, Veladas, Permisos)
            'authorizations.view_all',
            'authorizations.view_team',
            'authorizations.view_own',
            'authorizations.create',
            'authorizations.approve',
            'authorizations.reject',

            // Anomalías
            'anomalies.view_all',
            'anomalies.view_team',
            'anomalies.resolve',
            'anomalies.dismiss',

            // Omisión de checada (falta por checada incompleta): el jefe/supervisor
            // AUTORIZA (create), el administrador APRUEBA (approve).
            'check_omissions.view_all',
            'check_omissions.view_team',
            'check_omissions.create',
            'check_omissions.approve',

            // Nómina
            'payroll.view_basic',
            'payroll.view_complete',
            'payroll.create',
            'payroll.calculate',
            'payroll.approve',
            'payroll.pay_cash',
            'payroll.export',
            // Efectivo con segregación de funciones: el super admin ENTREGA el
            // efectivo (paso 1) y RECIBE la devolución del sobrante; el cobrador
            // solo COBRA (paso 2) y CIERRA su nómina. deliver/receive son
            // exclusivos de superadmin (ni admin los tiene).
            'payroll.cash.deliver',
            'payroll.cash.collect',
            'payroll.cash.close',
            'payroll.cash.receive',

            // Reportes
            'reports.view_all',
            'reports.view_team',
            'reports.view_own',

            // Desayunos
            'breakfasts.register',
            'breakfasts.view',

            // Configuración
            'settings.view',
            'settings.edit',
            'schedules.manage',
            'departments.manage',
            'positions.manage',
            'compensation_types.manage',
            'incident_types.manage',
            'holidays.manage',

            // Usuarios
            'users.view',
            'users.create',
            'users.edit',
            'users.delete',
            'users.reset_password',

            // Logs/Auditoría
            'logs.view',
        ];

        // Create permissions
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Create Super Admin role: TODO, incluida la custodia física del
        // efectivo (entregar al cobrador y recibir la devolución del sobrante).
        $superadmin = Role::firstOrCreate(['name' => 'superadmin']);
        $superadmin->syncPermissions(Permission::all());

        // Create Admin role with all permissions EXCEPT cash custody: entregar
        // y recibir el efectivo es exclusivo del superadmin (segregación de
        // funciones pedida por el negocio).
        $admin = Role::firstOrCreate(['name' => 'admin']);
        $admin->syncPermissions(
            Permission::whereNotIn('name', ['payroll.cash.deliver', 'payroll.cash.receive'])->get()
        );

        // Cobradores de nómina: SOLO cobran (paso 2) y cierran el cobro de SU
        // nómina — general (department_id NULL) o taller (departamento con
        // nómina propia). El scope se resuelve por nombre de rol en
        // User::allowsCashPeriod().
        foreach (['cobrador_general', 'cobrador_taller'] as $cobradorRole) {
            $cobrador = Role::firstOrCreate(['name' => $cobradorRole]);
            $cobrador->syncPermissions([
                'payroll.cash.collect',
                'payroll.cash.close',
            ]);
        }

        // Create RRHH role - HR data entry: create + edit personal-only fields, read attendance
        $rrhh = Role::firstOrCreate(['name' => 'rrhh']);
        $rrhh->syncPermissions([
            // Empleados - alta + edicion sólo de datos personales / contacto / domicilio / credenciales
            'employees.view_all',
            'employees.create',
            'employees.edit_personal',
            // Asistencia - solo lectura
            'attendance.view_all',
            // Omisión de checada - captura/autoriza y consulta (no aprueba)
            'check_omissions.view_all',
            'check_omissions.create',
            // Bolsa de horas a cuenta de vacaciones - RRHH la administra
            'vacation_hours.manage',
            // Desayunos - kiosco y consulta
            'breakfasts.register',
            'breakfasts.view',
        ]);

        // Create Supervisor role - Read-only employee access, attendance view, can create incidents/authorizations
        $supervisor = Role::firstOrCreate(['name' => 'supervisor']);
        $supervisor->syncPermissions([
            // Empleados - team view only (no create/edit/delete)
            'employees.view_team',
            // Asistencia - team (direct reports only)
            'attendance.view_team',
            // Incidencias - team view + create only (no approve/reject)
            'incidents.view_team',
            'incidents.create',
            // Autorizaciones - team view + create only (no approve/reject)
            'authorizations.view_team',
            'authorizations.create',
            // Anomalías - team view only (no resolve)
            'anomalies.view_team',
            // Omisión de checada - el jefe autoriza (create) su equipo; no aprueba
            'check_omissions.view_team',
            'check_omissions.create',
            // Reportes - team-scoped (own direct reports): attendance/discipline/
            // overtime/payroll reports limited to subordinates via ScopesReportEmployees.
            'reports.view_team',
        ]);

        // Create Employee role - Read-only: own attendance and own reports (faltas, asistencias, retardos, horas extra)
        $employee = Role::firstOrCreate(['name' => 'employee']);
        $employee->syncPermissions([
            // Asistencia - own only (read-only)
            'attendance.view_own',
            // Reportes - own only (faltas, asistencias, retardos, horas extra)
            'reports.view_own',
        ]);
    }
}
