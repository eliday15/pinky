<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('two_factor_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('app_users')->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('secret');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });

        // Migrate existing 2FA data from app_users to two_factor_devices
        $users = DB::table('app_users')
            ->whereNotNull('two_factor_secret')
            ->whereNotNull('two_factor_confirmed_at')
            ->get(['id', 'two_factor_secret', 'two_factor_confirmed_at']);

        foreach ($users as $user) {
            DB::table('two_factor_devices')->insert([
                'user_id' => $user->id,
                'name' => 'Autenticador principal',
                'secret' => $user->two_factor_secret,
                'confirmed_at' => $user->two_factor_confirmed_at,
                'created_at' => $user->two_factor_confirmed_at,
                'updated_at' => now(),
            ]);
        }

        // Also migrate unconfirmed secrets (setup in progress)
        $pending = DB::table('app_users')
            ->whereNotNull('two_factor_secret')
            ->whereNull('two_factor_confirmed_at')
            ->get(['id', 'two_factor_secret']);

        foreach ($pending as $user) {
            DB::table('two_factor_devices')->insert([
                'user_id' => $user->id,
                'name' => 'Autenticador principal',
                'secret' => $user->two_factor_secret,
                'confirmed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Drop old columns from app_users
        Schema::table('app_users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_confirmed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore columns on app_users
        Schema::table('app_users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('must_change_password');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_secret');
        });

        // Migrate data back (take the first confirmed device per user)
        $devices = DB::table('two_factor_devices')
            ->whereNotNull('confirmed_at')
            ->orderBy('confirmed_at')
            ->get();

        $migrated = [];
        foreach ($devices as $device) {
            if (!in_array($device->user_id, $migrated)) {
                DB::table('app_users')
                    ->where('id', $device->user_id)
                    ->update([
                        'two_factor_secret' => $device->secret,
                        'two_factor_confirmed_at' => $device->confirmed_at,
                    ]);
                $migrated[] = $device->user_id;
            }
        }

        Schema::dropIfExists('two_factor_devices');
    }
};
