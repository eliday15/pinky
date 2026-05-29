<?php

namespace Database\Factories;

use App\Models\VacationTable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for VacationTable model.
 *
 * Maps years of service to entitled vacation days (Mexican LFT).
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\VacationTable>
 */
class VacationTableFactory extends Factory
{
    protected $model = VacationTable::class;

    /**
     * Define the model's default state.
     *
     * Note: `years_of_service` is UNIQUE. Using a sequence in tests, or the
     * forYears() state, avoids collisions when creating multiple rows.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'years_of_service' => fake()->unique()->numberBetween(1, 40),
            'vacation_days' => fake()->numberBetween(12, 32),
        ];
    }

    /**
     * Create a row for a specific years-of-service / days pairing.
     */
    public function forYears(int $years, int $days): static
    {
        return $this->state(fn (array $attributes) => [
            'years_of_service' => $years,
            'vacation_days' => $days,
        ]);
    }
}
