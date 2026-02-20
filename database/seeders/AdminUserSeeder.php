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
        $admin = User::firstOrCreate(
            ['email' => 'admin@pinky.mx'],
            [
                'name' => 'Administrador',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        if (!$admin->hasRole('admin')) {
            $admin->assignRole('admin');
        }

        // Create RRHH user
        $rrhh = User::firstOrCreate(
            ['email' => 'rrhh@pinky.mx'],
            [
                'name' => 'Recursos Humanos',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        if (!$rrhh->hasRole('rrhh')) {
            $rrhh->assignRole('rrhh');
        }

        // Create supervisor user
        $supervisor = User::firstOrCreate(
            ['email' => 'supervisor@pinky.mx'],
            [
                'name' => 'Supervisor',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        if (!$supervisor->hasRole('supervisor')) {
            $supervisor->assignRole('supervisor');
        }
    }
}
