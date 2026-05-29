<?php

namespace Database\Factories;

use App\Models\EmergencyContact;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for EmergencyContact model.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EmergencyContact>
 */
class EmergencyContactFactory extends Factory
{
    protected $model = EmergencyContact::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'name' => fake()->name(),
            'phone' => fake()->numerify('55########'),
            'email' => fake()->safeEmail(),
            'relationship' => fake()->randomElement(['Padre', 'Madre', 'Cónyuge', 'Hermano', 'Hijo', 'Amigo']),
            'address' => fake()->streetAddress(),
        ];
    }

    /**
     * Minimal contact with only the NOT NULL columns populated.
     */
    public function minimal(): static
    {
        return $this->state(fn (array $attributes) => [
            'email' => null,
            'address' => null,
        ]);
    }

    /**
     * Spouse relationship.
     */
    public function spouse(): static
    {
        return $this->state(fn (array $attributes) => [
            'relationship' => 'Cónyuge',
        ]);
    }
}
