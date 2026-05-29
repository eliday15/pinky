<?php

namespace Database\Factories;

use App\Models\AttendanceAnomaly;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for AttendanceAnomaly model.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AttendanceAnomaly>
 */
class AttendanceAnomalyFactory extends Factory
{
    protected $model = AttendanceAnomaly::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'attendance_record_id' => AttendanceRecord::factory(),
            'employee_id' => Employee::factory(),
            'work_date' => fake()->dateTimeBetween('-2 months', 'now')->format('Y-m-d'),
            'anomaly_type' => AttendanceAnomaly::TYPE_LATE_ARRIVAL,
            'severity' => AttendanceAnomaly::SEVERITY_WARNING,
            'description' => fake()->sentence(),
            'expected_value' => '09:00',
            'actual_value' => '09:20',
            'deviation_minutes' => 20,
            'status' => AttendanceAnomaly::STATUS_OPEN,
            'auto_detected' => true,
        ];
    }

    /**
     * Set the anomaly type.
     */
    public function ofType(string $type): static
    {
        return $this->state(fn (array $attributes) => [
            'anomaly_type' => $type,
        ]);
    }

    /**
     * Critical severity anomaly.
     */
    public function critical(): static
    {
        return $this->state(fn (array $attributes) => [
            'severity' => AttendanceAnomaly::SEVERITY_CRITICAL,
        ]);
    }

    /**
     * Informational severity anomaly.
     */
    public function info(): static
    {
        return $this->state(fn (array $attributes) => [
            'severity' => AttendanceAnomaly::SEVERITY_INFO,
        ]);
    }

    /**
     * Open (unresolved) anomaly.
     */
    public function open(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AttendanceAnomaly::STATUS_OPEN,
            'resolved_by' => null,
            'resolved_at' => null,
        ]);
    }

    /**
     * Resolved anomaly.
     */
    public function resolved(?int $userId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AttendanceAnomaly::STATUS_RESOLVED,
            'resolved_by' => $userId ?? User::factory(),
            'resolved_at' => now(),
            'resolution_notes' => 'Resuelta manualmente.',
        ]);
    }

    /**
     * Dismissed anomaly.
     */
    public function dismissed(?int $userId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AttendanceAnomaly::STATUS_DISMISSED,
            'resolved_by' => $userId ?? User::factory(),
            'resolved_at' => now(),
            'resolution_notes' => 'Descartada.',
        ]);
    }

    /**
     * Anomaly linked to an authorization (auto-resolved).
     * Caller must pass an existing authorization id.
     */
    public function linkedToAuthorization(int $authorizationId): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AttendanceAnomaly::STATUS_LINKED,
            'linked_authorization_id' => $authorizationId,
            'resolved_at' => now(),
        ]);
    }
}
