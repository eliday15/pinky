<?php

namespace Database\Factories;

use App\Models\SyncLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for SyncLog model.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SyncLog>
 */
class SyncLogFactory extends Factory
{
    protected $model = SyncLog::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = fake()->dateTimeBetween('-1 week', 'now');
        $completedAt = (clone $startedAt)->modify('+5 minutes');

        return [
            'type' => 'zkteco',
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'status' => 'completed',
            'records_fetched' => fake()->numberBetween(50, 500),
            'records_processed' => fake()->numberBetween(50, 500),
            'records_created' => fake()->numberBetween(0, 100),
            'employees_imported' => 0,
            'employees_updated' => 0,
            'employees_marked_inactive' => 0,
            'errors' => null,
            'triggered_by' => null,
        ];
    }

    /**
     * Sync awaiting agent pickup (no completion yet).
     */
    public function requested(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'requested',
            'completed_at' => null,
            'records_fetched' => 0,
            'records_processed' => 0,
            'records_created' => 0,
        ]);
    }

    /**
     * Currently running sync.
     */
    public function running(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'running',
            'completed_at' => null,
        ]);
    }

    /**
     * Completed sync.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    /**
     * Failed sync with error payload.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'completed_at' => now(),
            'errors' => ['message' => 'Connection to device timed out.'],
        ]);
    }

    /**
     * Manual import sync type.
     */
    public function manualImport(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'manual_import',
        ]);
    }

    /**
     * Sync triggered by a specific user.
     */
    public function triggeredBy(?int $userId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'triggered_by' => $userId ?? User::factory(),
        ]);
    }
}
