<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Position;
use App\Models\Schedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for Position model.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Position>
 */
class PositionFactory extends Factory
{
    protected $model = Position::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->jobTitle(),
            'code' => fake()->unique()->lexify('POS-????'),
            'position_type' => 'operativo',
            'base_hourly_rate' => fake()->randomFloat(2, 50, 200),
            'department_id' => Department::factory(),
            'default_schedule_id' => Schedule::factory(),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the position is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
