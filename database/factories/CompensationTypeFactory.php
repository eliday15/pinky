<?php

namespace Database\Factories;

use App\Models\CompensationType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for CompensationType model.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CompensationType>
 */
class CompensationTypeFactory extends Factory
{
    protected $model = CompensationType::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'code' => fake()->unique()->lexify('CT-????'),
            'description' => fake()->sentence(),
            'calculation_type' => 'percentage',
            'percentage_value' => 50.00,
            'fixed_amount' => null,
            'is_active' => true,
        ];
    }

    /**
     * Create a fixed-amount compensation type.
     */
    public function fixed(float $amount = 100.00): static
    {
        return $this->state(fn (array $attributes) => [
            'calculation_type' => 'fixed',
            'percentage_value' => null,
            'fixed_amount' => $amount,
        ]);
    }

    /**
     * Create a percentage compensation type.
     */
    public function percentage(float $value = 50.00): static
    {
        return $this->state(fn (array $attributes) => [
            'calculation_type' => 'percentage',
            'percentage_value' => $value,
            'fixed_amount' => null,
        ]);
    }

    /**
     * Indicate that the compensation type is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
