<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add profile, credential, trial period, bonus, and vacation fields to employees.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Address fields
            $table->string('address_street')->nullable()->after('phone');
            $table->string('address_city')->nullable()->after('address_street');
            $table->string('address_state')->nullable()->after('address_city');
            $table->string('address_zip', 10)->nullable()->after('address_state');

            // Photo
            $table->string('photo_path')->nullable()->after('address_zip');

            // Emergency contact
            $table->string('emergency_phone', 20)->nullable()->after('photo_path');

            // Credentials (INE, Pasaporte, etc.)
            $table->string('credential_type', 50)->nullable()->after('emergency_phone');
            $table->string('credential_number', 100)->nullable()->after('credential_type');

            // Trial period
            $table->boolean('is_trial_period')->default(false)->after('credential_number');
            $table->date('trial_period_end_date')->nullable()->after('is_trial_period');

            // IMSS
            $table->string('imss_number', 50)->nullable()->after('trial_period_end_date');

            // Daily salary (salario diario integrado)
            $table->decimal('daily_salary', 10, 2)->nullable()->after('imss_number');

            // Monthly bonus
            $table->string('monthly_bonus_type', 20)->default('none')->after('daily_salary');
            $table->decimal('monthly_bonus_amount', 10, 2)->default(0)->after('monthly_bonus_type');

            // Vacation enhancements
            $table->integer('vacation_days_reserved')->default(0)->after('vacation_days_used');
            $table->decimal('vacation_premium_percentage', 5, 2)->default(25.00)->after('vacation_days_reserved');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'address_street',
                'address_city',
                'address_state',
                'address_zip',
                'photo_path',
                'emergency_phone',
                'credential_type',
                'credential_number',
                'is_trial_period',
                'trial_period_end_date',
                'imss_number',
                'daily_salary',
                'monthly_bonus_type',
                'monthly_bonus_amount',
                'vacation_days_reserved',
                'vacation_premium_percentage',
            ]);
        });
    }
};
