<?php

namespace Database\Factories;

use App\Models\Schedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for Schedule model.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Schedule>
 */
class ScheduleFactory extends Factory
{
    protected $model = Schedule::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true) . ' Schedule',
            'code' => fake()->unique()->lexify('SCH-????'),
            'description' => fake()->sentence(),
            'entry_time' => '08:00',
            'exit_time' => '17:00',
            'break_minutes' => 60,
            'daily_work_hours' => 8,
            'late_tolerance_minutes' => 10,
            'is_flexible' => false,
            'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'is_active' => true,
        ];
    }

    /**
     * Create a flexible schedule.
     */
    public function flexible(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_flexible' => true,
        ]);
    }

    /**
     * Indicate that the schedule is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
