<?php

namespace Database\Factories;

use App\Models\Authorization;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Factory for Authorization model.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Authorization>
 */
class AuthorizationFactory extends Factory
{
    protected $model = Authorization::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'requested_by' => User::factory(),
            'approved_by' => null,
            'type' => Authorization::TYPE_OVERTIME,
            'compensation_type_id' => null,
            'date' => fake()->dateTimeBetween('-1 month', '+1 month')->format('Y-m-d'),
            'start_time' => '18:00',
            'end_time' => '20:00',
            'hours' => 2.00,
            'reason' => fake()->sentence(),
            'evidence_path' => null,
            'status' => Authorization::STATUS_PENDING,
            'rejection_reason' => null,
            'approved_at' => null,
            'is_pre_authorization' => true,
            'attendance_record_id' => null,
            'department_head_id' => null,
            'department_head_signed_at' => null,
            'is_bulk_generated' => false,
            'bulk_group_id' => null,
        ];
    }

    /**
     * Overtime authorization (Horas Extra).
     */
    public function overtime(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Authorization::TYPE_OVERTIME,
        ]);
    }

    /**
     * Night shift authorization (Velada).
     */
    public function nightShift(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Authorization::TYPE_NIGHT_SHIFT,
        ]);
    }

    /**
     * Holiday worked authorization (Día Festivo Trabajado).
     */
    public function holidayWorked(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Authorization::TYPE_HOLIDAY_WORKED,
        ]);
    }

    /**
     * Special authorization.
     */
    public function special(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Authorization::TYPE_SPECIAL,
        ]);
    }

    /**
     * Approved authorization (sets approver + timestamp).
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Authorization::STATUS_APPROVED,
            'approved_by' => User::factory(),
            'approved_at' => now(),
        ]);
    }

    /**
     * Rejected authorization (sets approver, timestamp and reason).
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Authorization::STATUS_REJECTED,
            'approved_by' => User::factory(),
            'approved_at' => now(),
            'rejection_reason' => fake()->sentence(),
        ]);
    }

    /**
     * Paid authorization (must logically follow an approval).
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Authorization::STATUS_PAID,
            'approved_by' => User::factory(),
            'approved_at' => now(),
        ]);
    }

    /**
     * Post-authorization (detected after the event from attendance data).
     */
    public function postAuthorization(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_pre_authorization' => false,
        ]);
    }

    /**
     * Bulk-generated authorization grouped by a shared UUID.
     */
    public function bulk(?string $groupId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'is_bulk_generated' => true,
            'bulk_group_id' => $groupId ?? (string) Str::uuid(),
        ]);
    }
}
