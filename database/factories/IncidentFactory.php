<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for Incident model.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Incident>
 */
class IncidentFactory extends Factory
{
    protected $model = Incident::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-1 month', '+1 month');
        $end = (clone $start)->modify('+2 days');

        return [
            'employee_id' => Employee::factory(),
            'incident_type_id' => IncidentType::factory(),
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'days_count' => 3,
            'reason' => fake()->sentence(),
            'document_path' => null,
            'start_time' => null,
            'end_time' => null,
            'hours' => null,
            'status' => 'pending',
            'approved_by' => null,
            'approved_at' => null,
            'rejection_reason' => null,
            'pay_worked_days' => false,
            'migrated_from_authorization_id' => null,
        ];
    }

    /**
     * Approved incident (sets approver + timestamp).
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'approved_by' => User::factory(),
            'approved_at' => now(),
        ]);
    }

    /**
     * Rejected incident (sets approver, timestamp and reason).
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'rejected',
            'approved_by' => User::factory(),
            'approved_at' => now(),
            'rejection_reason' => fake()->sentence(),
        ]);
    }

    /**
     * Single-day incident with a time range (e.g. permission incident).
     */
    public function withTimeRange(): static
    {
        return $this->state(fn (array $attributes) => [
            'days_count' => 1,
            'end_date' => $attributes['start_date'] ?? now()->toDateString(),
            'start_time' => '09:00',
            'end_time' => '13:00',
            'hours' => 4.00,
        ]);
    }

    /**
     * Incident covering a specific number of whole days.
     */
    public function days(int $count): static
    {
        return $this->state(function (array $attributes) use ($count) {
            $start = fake()->dateTimeBetween('-1 month', '+1 month');
            $end = (clone $start)->modify('+' . ($count - 1) . ' days');

            return [
                'start_date' => $start->format('Y-m-d'),
                'end_date' => $end->format('Y-m-d'),
                'days_count' => $count,
            ];
        });
    }
}
