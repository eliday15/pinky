<?php

namespace Database\Factories;

use App\Models\IncidentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for IncidentType model.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\IncidentType>
 */
class IncidentTypeFactory extends Factory
{
    protected $model = IncidentType::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'code' => fake()->unique()->lexify('IT-????'),
            'description' => fake()->sentence(),
            'category' => 'permission',
            'is_paid' => false,
            'deducts_vacation' => false,
            'requires_approval' => true,
            'requires_document' => false,
            'affects_attendance' => false,
            'has_time_range' => false,
            'color' => '#6B7280',
            'is_active' => true,
            'priority' => 0,
        ];
    }

    /**
     * Vacation incident type (paid, deducts vacation days).
     */
    public function vacation(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => 'vacation',
            'is_paid' => true,
            'deducts_vacation' => true,
            'affects_attendance' => true,
        ]);
    }

    /**
     * Sick leave incident type.
     */
    public function sickLeave(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => 'sick_leave',
            'is_paid' => true,
            'requires_document' => true,
            'affects_attendance' => true,
        ]);
    }

    /**
     * Permission incident type with a time range (e.g. exit/entry permission).
     */
    public function permission(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => 'permission',
            'has_time_range' => true,
        ]);
    }

    /**
     * Unpaid absence incident type.
     */
    public function absence(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => 'absence',
            'is_paid' => false,
            'affects_attendance' => true,
        ]);
    }

    /**
     * Special incident type.
     */
    public function special(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => 'special',
        ]);
    }

    /**
     * Indicate that the incident type is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
