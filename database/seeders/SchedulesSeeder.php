<?php

namespace Database\Seeders;

use App\Models\Schedule;
use Illuminate\Database\Seeder;

class SchedulesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $schedules = [
            [
                'name' => 'Turno Matutino L-V',
                'code' => 'MAT_LV',
                'entry_time' => '07:00:00',
                'exit_time' => '16:00:00',
                'break_start' => '12:00:00',
                'break_end' => '13:00:00',
                'break_minutes' => 60,
                'late_tolerance_minutes' => 10,
                'daily_work_hours' => 8,
                'is_flexible' => false,
                'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            ],
            [
                'name' => 'Turno Matutino L-S',
                'code' => 'MAT_LS',
                'entry_time' => '07:00:00',
                'exit_time' => '15:30:00',
                'break_start' => '12:00:00',
                'break_end' => '12:30:00',
                'break_minutes' => 30,
                'late_tolerance_minutes' => 10,
                'daily_work_hours' => 8,
                'is_flexible' => false,
                'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'],
            ],
            [
                'name' => 'Turno Vespertino',
                'code' => 'VESP',
                'entry_time' => '14:00:00',
                'exit_time' => '22:00:00',
                'break_start' => '18:00:00',
                'break_end' => '18:30:00',
                'break_minutes' => 30,
                'late_tolerance_minutes' => 10,
                'daily_work_hours' => 8,
                'is_flexible' => false,
                'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            ],
            [
                'name' => 'Turno Administrativo',
                'code' => 'ADMIN',
                'entry_time' => '08:00:00',
                'exit_time' => '17:00:00',
                'break_start' => '13:00:00',
                'break_end' => '14:00:00',
                'break_minutes' => 60,
                'late_tolerance_minutes' => 10,
                'daily_work_hours' => 8,
                'is_flexible' => true,
                'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            ],
            [
                'name' => 'Medio Turno Mañana',
                'code' => 'MEDIO_AM',
                'entry_time' => '07:00:00',
                'exit_time' => '12:00:00',
                'break_start' => null,
                'break_end' => null,
                'break_minutes' => 0,
                'late_tolerance_minutes' => 10,
                'daily_work_hours' => 5,
                'is_flexible' => false,
                'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            ],
        ];

        foreach ($schedules as $schedule) {
            Schedule::create($schedule);
        }
    }
}
