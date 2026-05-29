<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\LateAccumulation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for LateAccumulation model.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LateAccumulation>
 */
class LateAccumulationFactory extends Factory
{
    protected $model = LateAccumulation::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'year' => (int) now()->year,
            'week' => fake()->numberBetween(1, 52),
            'late_count' => fake()->numberBetween(0, 5),
            'absence_generated' => false,
            'generated_incident_id' => null,
        ];
    }

    /**
     * Accumulation for a specific year and week.
     */
    public function forPeriod(int $year, int $week): static
    {
        return $this->state(fn (array $attributes) => [
            'year' => $year,
            'week' => $week,
        ]);
    }

    /**
     * Accumulation with a specific late count.
     */
    public function withLateCount(int $count): static
    {
        return $this->state(fn (array $attributes) => [
            'late_count' => $count,
        ]);
    }

    /**
     * Accumulation that has reached the absence threshold and generated one.
     * Pass an incident id when available, otherwise leave null.
     */
    public function absenceGenerated(int $lateCount = 6, ?int $incidentId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'late_count' => $lateCount,
            'absence_generated' => true,
            'generated_incident_id' => $incidentId,
        ]);
    }
}
