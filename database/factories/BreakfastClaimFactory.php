<?php

namespace Database\Factories;

use App\Models\BreakfastClaim;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for BreakfastClaim model.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BreakfastClaim>
 */
class BreakfastClaimFactory extends Factory
{
    protected $model = BreakfastClaim::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = fake()->dateTimeBetween('-2 weeks', 'now');

        return [
            'employee_id' => Employee::factory(),
            'claim_date' => $date->format('Y-m-d'),
            'claimed_at' => $date->format('Y-m-d').' 08:30:00',
            'unit_cost' => 30.00,
            'face_match_distance' => fake()->randomFloat(4, 0.15, 0.45),
            'evidence_photo_path' => null,
            'registered_by' => null,
        ];
    }

    /**
     * Claim on a specific date (claimed at 08:30 that day).
     */
    public function onDate(string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'claim_date' => $date,
            'claimed_at' => $date.' 08:30:00',
        ]);
    }

    /**
     * Claim with a specific frozen unit cost.
     */
    public function withCost(float $cost): static
    {
        return $this->state(fn (array $attributes) => [
            'unit_cost' => $cost,
        ]);
    }
}
