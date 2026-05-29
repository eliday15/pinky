<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for AuditLog model.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /**
     * Define the model's default state.
     *
     * `auditable_type` is NOT NULL in the DB; `auditable_id` is nullable.
     * `old_values`/`new_values` are cast to array (stored as JSON).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'module' => AuditLog::MODULE_EMPLOYEES,
            'action' => AuditLog::ACTION_CREATE,
            'auditable_type' => Employee::class,
            'auditable_id' => fake()->numberBetween(1, 500),
            'old_values' => null,
            'new_values' => ['status' => 'active'],
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'description' => fake()->sentence(),
        ];
    }

    /**
     * Attach the log to an existing auditable model.
     */
    public function forModel(\Illuminate\Database\Eloquent\Model $model): static
    {
        return $this->state(fn (array $attributes) => [
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
        ]);
    }

    /**
     * Log performed by a system process (no user).
     */
    public function bySystem(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => null,
        ]);
    }

    /**
     * Create-action log.
     */
    public function created(): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => AuditLog::ACTION_CREATE,
            'old_values' => null,
            'new_values' => ['status' => 'active'],
        ]);
    }

    /**
     * Update-action log with before/after values.
     */
    public function updated(): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => AuditLog::ACTION_UPDATE,
            'old_values' => ['status' => 'inactive'],
            'new_values' => ['status' => 'active'],
        ]);
    }

    /**
     * Delete-action log.
     */
    public function deleted(): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => AuditLog::ACTION_DELETE,
            'old_values' => ['status' => 'active'],
            'new_values' => null,
        ]);
    }

    /**
     * Login-action log (auth module, no auditable record).
     */
    public function login(): static
    {
        return $this->state(fn (array $attributes) => [
            'module' => AuditLog::MODULE_AUTH,
            'action' => AuditLog::ACTION_LOGIN,
            'auditable_type' => User::class,
            'auditable_id' => null,
            'old_values' => null,
            'new_values' => null,
        ]);
    }

    /**
     * Place the log in a specific module.
     */
    public function module(string $module): static
    {
        return $this->state(fn (array $attributes) => [
            'module' => $module,
        ]);
    }
}
