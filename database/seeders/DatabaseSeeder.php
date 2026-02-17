<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesPermissionsSeeder::class,
            DepartmentsSeeder::class,
            PositionsSeeder::class,
            SchedulesSeeder::class,
            CompensationTypesSeeder::class,
            VacationTableSeeder::class,
            IncidentTypesSeeder::class,
            HolidaysSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
