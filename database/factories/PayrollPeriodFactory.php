<?php

namespace Database\Factories;

use App\Models\PayrollPeriod;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for PayrollPeriod model.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PayrollPeriod>
 */
class PayrollPeriodFactory extends Factory
{
    protected $model = PayrollPeriod::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-2 months', 'now');
        $end = (clone $start)->modify('+6 days');
        $payment = (clone $end)->modify('+3 days');

        return [
            'name' => 'Semana del ' . $start->format('d/m/Y'),
            'type' => 'weekly',
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'payment_date' => $payment->format('Y-m-d'),
            'status' => 'draft',
            'created_by' => User::factory(),
            'approved_by' => null,
        ];
    }

    /**
     * Weekly period type.
     */
    public function weekly(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'weekly',
        ]);
    }

    /**
     * Biweekly period type.
     */
    public function biweekly(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'biweekly',
        ]);
    }

    /**
     * Monthly period type.
     */
    public function monthly(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'monthly',
        ]);
    }

    /**
     * Draft status (default).
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
        ]);
    }

    /**
     * Period currently being calculated.
     */
    public function calculating(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'calculating',
        ]);
    }

    /**
     * Period under review.
     */
    public function review(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'review',
        ]);
    }

    /**
     * Approved period (sets approver).
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'approved_by' => User::factory(),
        ]);
    }

    /**
     * Paid period (sets approver).
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paid',
            'approved_by' => User::factory(),
        ]);
    }
}
