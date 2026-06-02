<?php

namespace Database\Seeders;

use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\Position;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;

/**
 * Seeder for E2E test data.
 *
 * Creates a minimal but representative dataset for Puppeteer tests,
 * covering Blocks 1 (Compensation Types) and 2 (Employee Profile).
 */
class E2ETestSeeder extends Seeder
{
    /**
     * Fixed base32 TOTP secret for the E2E admin's confirmed 2FA device.
     *
     * Known to the Puppeteer helpers so they can compute a valid TOTP code
     * for sensitive actions (approve/reject) that go through VerifiesTwoFactor.
     * This is TEST-ONLY data; production devices use randomly generated secrets.
     */
    public const ADMIN_TOTP_SECRET = 'JBSWY3DPEHPK3PXP';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Roles & permissions (required for Spatie)
        $this->call(RolesPermissionsSeeder::class);

        // Incident types (needed by the Incidents module / e2e journey)
        $this->call(IncidentTypesSeeder::class);

        // 2. Admin user
        $admin = User::factory()->create([
            'name' => 'Admin E2E',
            'email' => 'admin@test.com',
            'password' => Hash::make('password'),
        ]);
        $admin->assignRole('admin');

        // Confirmed 2FA device for the admin.
        //
        // The admin role requires 2FA (EnsureTwoFactorSetup middleware). Without a
        // confirmed device the browser is bounced to /two-factor/setup and can never
        // reach the app. Seeding a confirmed device with a KNOWN secret lets the e2e
        // login proceed (the per-login TOTP challenge is currently disabled in
        // AuthenticatedSessionController) AND lets the helpers generate a valid TOTP
        // code for the approve/reject flows that call VerifiesTwoFactor::verifyTwoFactorCode.
        $admin->twoFactorDevices()->create([
            'name' => 'E2E Authenticator',
            'secret' => Crypt::encryptString(self::ADMIN_TOTP_SECRET),
            'confirmed_at' => now(),
        ]);

        // 3. Departments
        $deptProd = Department::factory()->create([
            'name' => 'Produccion',
            'code' => 'PROD',
            'description' => 'Departamento de produccion',
        ]);
        $deptAdmin = Department::factory()->create([
            'name' => 'Administracion',
            'code' => 'ADMIN',
            'description' => 'Departamento administrativo',
        ]);

        // 4. Schedules
        $schedDay = Schedule::factory()->create([
            'name' => 'Diurno',
            'code' => 'DIA',
            'entry_time' => '08:00',
            'exit_time' => '17:00',
            'break_minutes' => 60,
            'daily_work_hours' => 8,
            'late_tolerance_minutes' => 10,
        ]);
        $schedNight = Schedule::factory()->create([
            'name' => 'Nocturno',
            'code' => 'NOC',
            'entry_time' => '22:00',
            'exit_time' => '06:00',
            'break_minutes' => 30,
            'daily_work_hours' => 7,
            'late_tolerance_minutes' => 5,
        ]);

        // 5. Compensation types (1 fixed, 2 percentage)
        $ctFixed = CompensationType::factory()->fixed(150.00)->create([
            'name' => 'Bono Transporte',
            'code' => 'TRANS',
            'description' => 'Bono fijo de transporte',
        ]);
        $ctPctOvertime = CompensationType::factory()->percentage(50.00)->create([
            'name' => 'Hora Extra Doble',
            'code' => 'HE-DBL',
            'description' => 'Hora extra al 50% adicional',
        ]);
        $ctPctHoliday = CompensationType::factory()->percentage(100.00)->create([
            'name' => 'Dia Festivo',
            'code' => 'FEST',
            'description' => 'Pago de dia festivo al 100%',
        ]);

        // Extra types to exercise every authorization application mode in the UI
        // (per_hour velada, per_day, one_time, and weekend attendance-pull).
        $ctVelada = CompensationType::factory()->percentage(100.00)->create([
            'name' => 'Velada',
            'code' => 'VEL',
            'description' => 'Turno nocturno que cruza medianoche',
            'application_mode' => CompensationType::APPLICATION_PER_HOUR,
            'authorization_type' => Authorization::TYPE_NIGHT_SHIFT,
        ]);
        $ctPermisoDia = CompensationType::factory()->fixed(200.00)->create([
            'name' => 'Permiso por Dia',
            'code' => 'PDIA',
            'description' => 'Compensacion por dia (per_day)',
            'application_mode' => CompensationType::APPLICATION_PER_DAY,
            'authorization_type' => Authorization::TYPE_SPECIAL,
        ]);
        $ctBonoUnico = CompensationType::factory()->fixed(500.00)->create([
            'name' => 'Bono Unico',
            'code' => 'BUNI',
            'description' => 'Bono de cantidad fija (one_time)',
            'application_mode' => CompensationType::APPLICATION_ONE_TIME,
            'authorization_type' => Authorization::TYPE_SPECIAL,
        ]);
        $ctFinde = CompensationType::factory()->percentage(75.00)->create([
            'name' => 'Fin de Semana',
            'code' => 'FINDE',
            'description' => 'Trabajo en fin de semana (jala desde checadas)',
            'application_mode' => CompensationType::APPLICATION_PER_DAY,
            'authorization_type' => Authorization::TYPE_SPECIAL,
            'attendance_pull_rule' => CompensationType::PULL_RULE_WEEKEND,
        ]);

        // 6. Positions (with compensation types)
        $posOperator = Position::factory()->create([
            'name' => 'Operador',
            'code' => 'OPER',
            'position_type' => 'operativo',
            'base_hourly_rate' => 75.00,
            'department_id' => $deptProd->id,
            'default_schedule_id' => $schedDay->id,
        ]);
        $posOperator->compensationTypes()->attach([
            $ctFixed->id => ['default_percentage' => null, 'default_fixed_amount' => 150.00],
            $ctPctOvertime->id => ['default_percentage' => 50.00, 'default_fixed_amount' => null],
        ]);

        $posSupervisor = Position::factory()->create([
            'name' => 'Supervisor Produccion',
            'code' => 'SUP-PROD',
            'position_type' => 'administrativo',
            'base_hourly_rate' => 120.00,
            'department_id' => $deptProd->id,
            'default_schedule_id' => $schedDay->id,
        ]);
        $posSupervisor->compensationTypes()->attach([
            $ctPctOvertime->id => ['default_percentage' => 50.00, 'default_fixed_amount' => null],
            $ctPctHoliday->id => ['default_percentage' => 100.00, 'default_fixed_amount' => null],
        ]);

        $posAdminAsst = Position::factory()->create([
            'name' => 'Asistente Administrativo',
            'code' => 'ADMIN-AST',
            'position_type' => 'administrativo',
            'base_hourly_rate' => 90.00,
            'department_id' => $deptAdmin->id,
            'default_schedule_id' => $schedDay->id,
        ]);

        // 7. Employees (5 with variations)
        // Supervisor employee
        $empSupervisor = Employee::factory()->withFullProfile()->create([
            'employee_number' => 'EMP-0001',
            'zkteco_user_id' => 1001,
            'first_name' => 'Carlos',
            'last_name' => 'Ramirez Torres',
            'full_name' => 'Carlos Ramirez Torres',
            'department_id' => $deptProd->id,
            'position_id' => $posSupervisor->id,
            'schedule_id' => $schedDay->id,
            'hourly_rate' => 120.00,
        ]);
        $empSupervisor->compensationTypes()->attach([
            $ctPctOvertime->id => ['custom_percentage' => 50.00, 'custom_fixed_amount' => null, 'is_active' => true],
        ]);

        // Trial period employee
        $empTrial = Employee::factory()->trial()->create([
            'employee_number' => 'EMP-0002',
            'zkteco_user_id' => 1002,
            'first_name' => 'Maria',
            'last_name' => 'Lopez Garcia',
            'full_name' => 'Maria Lopez Garcia',
            'department_id' => $deptProd->id,
            'position_id' => $posOperator->id,
            'schedule_id' => $schedDay->id,
            'hourly_rate' => 75.00,
            'supervisor_id' => $empSupervisor->id,
        ]);

        // Minimum wage employee with fixed bonus
        $empMinWage = Employee::factory()->minimumWage()->withFixedBonus(500.00)->withFullProfile()->create([
            'employee_number' => 'EMP-0003',
            'zkteco_user_id' => 1003,
            'first_name' => 'Juan',
            'last_name' => 'Hernandez Ruiz',
            'full_name' => 'Juan Hernandez Ruiz',
            'department_id' => $deptProd->id,
            'position_id' => $posOperator->id,
            'schedule_id' => $schedNight->id,
            'supervisor_id' => $empSupervisor->id,
            'vacation_days_reserved' => 3,
        ]);
        $empMinWage->compensationTypes()->attach([
            $ctFixed->id => ['custom_percentage' => null, 'custom_fixed_amount' => 150.00, 'is_active' => true],
        ]);

        // Admin employee with variable bonus. Enabled for every application mode
        // so the authorization-create e2e can drive per_hour/per_day/one_time and
        // the weekend attendance-pull flows against a single known employee.
        $empAdmin = Employee::factory()->withVariableBonus(300.00)->withFullProfile()->create([
            'employee_number' => 'EMP-0004',
            'zkteco_user_id' => 1004,
            'first_name' => 'Ana',
            'last_name' => 'Martinez Diaz',
            'full_name' => 'Ana Martinez Diaz',
            'department_id' => $deptAdmin->id,
            'position_id' => $posAdminAsst->id,
            'schedule_id' => $schedDay->id,
        ]);
        $empAdmin->compensationTypes()->attach([
            $ctVelada->id => ['custom_percentage' => 100.00, 'custom_fixed_amount' => null, 'is_active' => true],
            $ctPermisoDia->id => ['custom_percentage' => null, 'custom_fixed_amount' => 200.00, 'is_active' => true],
            $ctBonoUnico->id => ['custom_percentage' => null, 'custom_fixed_amount' => 500.00, 'is_active' => true],
            $ctFinde->id => ['custom_percentage' => 75.00, 'custom_fixed_amount' => null, 'is_active' => true],
        ]);

        // Incomplete profile employee (no supervisor — triggers Incompleto badge)
        Employee::factory()->create([
            'employee_number' => 'EMP-0005',
            'zkteco_user_id' => 1005,
            'first_name' => 'Pedro',
            'last_name' => 'Sanchez Flores',
            'full_name' => 'Pedro Sanchez Flores',
            'department_id' => $deptProd->id,
            'position_id' => $posOperator->id,
            'schedule_id' => $schedDay->id,
            'supervisor_id' => null,
            'hourly_rate' => 75.00,
        ]);

        // 8. Pending workflow records for the approval e2e journeys.
        //
        // Pending incident: "Permiso con goce" requires approval and does NOT
        // deduct vacation, so the admin approve flow has no balance precondition.
        $permisoType = IncidentType::where('code', 'PCG')->first();
        Incident::factory()->create([
            'employee_id' => $empTrial->id,
            'incident_type_id' => $permisoType->id,
            'start_date' => now()->addDays(3)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'days_count' => 1,
            'reason' => 'Permiso e2e pendiente de aprobacion',
            'status' => 'pending',
        ]);

        // Pending authorization: a "special" type never auto-approves (only
        // overtime/night_shift do, and only when they match attendance — there's
        // none seeded), so it stays pending for the admin approve journey.
        Authorization::factory()->create([
            'employee_id' => $empSupervisor->id,
            'requested_by' => $admin->id,
            'approved_by' => null,
            'type' => Authorization::TYPE_SPECIAL,
            'compensation_type_id' => $ctFixed->id,
            'date' => now()->subDays(2)->toDateString(),
            'start_time' => null,
            'end_time' => null,
            'hours' => 1,
            'reason' => 'Autorizacion e2e pendiente de aprobacion',
            'status' => Authorization::STATUS_PENDING,
            'is_pre_authorization' => false,
        ]);
    }
}
