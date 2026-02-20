<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Hash;

/**
 * One-time migration to create a super admin user for testing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'elias@pinky.mx'],
            [
                'name' => 'Elias Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        if (!$user->hasRole('admin')) {
            $user->assignRole('admin');
        }
    }

    public function down(): void
    {
        User::where('email', 'elias@pinky.mx')->delete();
    }
};
