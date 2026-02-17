<?php

namespace Database\Seeders;

use App\Models\CompensationType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Database\Seeder;
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
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Roles & permissions (required for Spatie)
        $this->call(RolesPermissionsSeeder::class);

        // 2. Admin user
        $admin = User::factory()->create([
            'name' => 'Admin E2E',
            'email' => 'admin@test.com',
            'password' => Hash::make('password'),
        ]);
        $admin->assignRole('admin');

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

        // Admin employee with variable bonus
        Employee::factory()->withVariableBonus(300.00)->withFullProfile()->create([
            'employee_number' => 'EMP-0004',
            'zkteco_user_id' => 1004,
            'first_name' => 'Ana',
            'last_name' => 'Martinez Diaz',
            'full_name' => 'Ana Martinez Diaz',
            'department_id' => $deptAdmin->id,
            'position_id' => $posAdminAsst->id,
            'schedule_id' => $schedDay->id,
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
    }
}
