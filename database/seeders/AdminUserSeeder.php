<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create admin user
        $admin = User::create([
            'name' => 'Administrador',
            'email' => 'admin@pinky.mx',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $admin->assignRole('admin');

        // Create RRHH user
        $rrhh = User::create([
            'name' => 'Recursos Humanos',
            'email' => 'rrhh@pinky.mx',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $rrhh->assignRole('rrhh');

        // Create supervisor user
        $supervisor = User::create([
            'name' => 'Supervisor',
            'email' => 'supervisor@pinky.mx',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $supervisor->assignRole('supervisor');
    }
}
