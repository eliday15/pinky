<?php

namespace Database\Factories;

use App\Models\Holiday;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for Holiday model.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Holiday>
 */
class HolidayFactory extends Factory
{
    protected $model = Holiday::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $names = [
            'Año Nuevo',
            'Día de la Constitución',
            'Natalicio de Benito Juárez',
            'Día del Trabajo',
            'Día de la Independencia',
            'Día de la Revolución',
            'Navidad',
        ];

        return [
            // 'date' is UNIQUE; use a fresh date per record to avoid collisions.
            'date' => fake()->unique()->dateTimeBetween('-1 year', '+1 year')->format('Y-m-d'),
            'name' => fake()->randomElement($names),
            'is_mandatory' => true,
            'pay_multiplier' => 2.00,
        ];
    }

    /**
     * Create a non-mandatory (optional) holiday.
     */
    public function optional(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_mandatory' => false,
        ]);
    }

    /**
     * Create a mandatory holiday.
     */
    public function mandatory(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_mandatory' => true,
        ]);
    }

    /**
     * Set a specific date for the holiday.
     */
    public function onDate(string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => $date,
        ]);
    }

    /**
     * Set a custom pay multiplier (e.g. triple pay).
     */
    public function withMultiplier(float $multiplier): static
    {
        return $this->state(fn (array $attributes) => [
            'pay_multiplier' => $multiplier,
        ]);
    }
}
