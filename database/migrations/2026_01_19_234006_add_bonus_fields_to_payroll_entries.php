<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payroll_entries', function (Blueprint $table) {
            // Bonos específicos según propuesta
            $table->decimal('punctuality_bonus', 10, 2)->default(0)->after('vacation_pay');
            $table->decimal('dinner_allowance', 10, 2)->default(0)->after('punctuality_bonus');
            $table->decimal('night_shift_bonus', 10, 2)->default(0)->after('dinner_allowance');
            $table->decimal('weekly_bonus', 10, 2)->default(0)->after('night_shift_bonus');
            $table->decimal('monthly_bonus', 10, 2)->default(0)->after('weekly_bonus');

            // Contadores adicionales
            $table->integer('punctuality_days')->default(0)->after('days_late');
            $table->integer('night_shift_days')->default(0)->after('punctuality_days');
            $table->integer('late_absences_generated')->default(0)->after('night_shift_days');

            // Horas de velada (turno nocturno)
            $table->decimal('night_shift_hours', 5, 2)->default(0)->after('weekend_hours');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payroll_entries', function (Blueprint $table) {
            $table->dropColumn([
                'punctuality_bonus',
                'dinner_allowance',
                'night_shift_bonus',
                'weekly_bonus',
                'monthly_bonus',
                'punctuality_days',
                'night_shift_days',
                'late_absences_generated',
                'night_shift_hours',
            ]);
        });
    }
};
