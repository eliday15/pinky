<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Schedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for Employee model.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $firstName = fake()->firstName();
        $lastName = fake()->lastName() . ' ' . fake()->lastName();

        return [
            'employee_number' => fake()->unique()->numerify('EMP-####'),
            'zkteco_user_id' => fake()->unique()->numberBetween(1000, 9999),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => "$firstName $lastName",
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'hire_date' => fake()->dateTimeBetween('-3 years', '-1 month'),
            'department_id' => Department::factory(),
            'position_id' => Position::factory(),
            'schedule_id' => Schedule::factory(),
            'hourly_rate' => fake()->randomFloat(2, 50, 200),
            'status' => 'active',
            'is_minimum_wage' => false,
            'is_trial_period' => false,
            'monthly_bonus_type' => 'none',
            'monthly_bonus_amount' => 0,
            'vacation_days_entitled' => 12,
            'vacation_days_used' => 0,
            'vacation_days_reserved' => 0,
            'vacation_premium_percentage' => 25.00,
        ];
    }

    /**
     * Create an employee in trial period.
     */
    public function trial(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_trial_period' => true,
            'trial_period_end_date' => now()->addDays(30)->toDateString(),
        ]);
    }

    /**
     * Create a minimum wage employee.
     */
    public function minimumWage(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_minimum_wage' => true,
            'hourly_rate' => 29.40,
        ]);
    }

    /**
     * Create an employee with a fixed bonus.
     */
    public function withFixedBonus(float $amount = 500.00): static
    {
        return $this->state(fn (array $attributes) => [
            'monthly_bonus_type' => 'fixed',
            'monthly_bonus_amount' => $amount,
        ]);
    }

    /**
     * Create an employee with a variable bonus.
     */
    public function withVariableBonus(float $amount = 300.00): static
    {
        return $this->state(fn (array $attributes) => [
            'monthly_bonus_type' => 'variable',
            'monthly_bonus_amount' => $amount,
        ]);
    }

    /**
     * Create an employee with full profile (Block 2 fields).
     */
    public function withFullProfile(): static
    {
        return $this->state(fn (array $attributes) => [
            'address_street' => fake()->streetAddress(),
            'address_city' => fake()->city(),
            'address_state' => fake()->state(),
            'address_zip' => fake()->postcode(),
            'emergency_phone' => fake()->phoneNumber(),
            'credential_type' => 'INE',
            'credential_number' => fake()->numerify('##########'),
            'imss_number' => fake()->numerify('###########'),
            'daily_salary' => fake()->randomFloat(2, 400, 1200),
        ]);
    }

    /**
     * Set an inactive status.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }

    /**
     * Set a terminated status.
     */
    public function terminated(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'terminated',
            'termination_date' => now()->subDays(10)->toDateString(),
        ]);
    }
}
