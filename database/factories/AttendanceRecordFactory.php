<?php

namespace Database\Factories;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for AttendanceRecord model.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AttendanceRecord>
 */
class AttendanceRecordFactory extends Factory
{
    protected $model = AttendanceRecord::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $workDate = fake()->dateTimeBetween('-2 months', 'now');
        $checkIn = (clone $workDate)->setTime(9, 0);
        $lunchOut = (clone $workDate)->setTime(14, 0);
        $lunchIn = (clone $workDate)->setTime(15, 0);
        $checkOut = (clone $workDate)->setTime(18, 0);

        return [
            'employee_id' => Employee::factory(),
            'work_date' => $workDate->format('Y-m-d'),
            'check_in' => $checkIn->format('H:i:s'),
            'check_out' => $checkOut->format('H:i:s'),
            'lunch_out' => $lunchOut->format('H:i:s'),
            'lunch_in' => $lunchIn->format('H:i:s'),
            'actual_break_minutes' => 60,
            'worked_hours' => 8.00,
            'overtime_hours' => 0.00,
            'velada_hours' => 0.00,
            'permission_hours' => 0.00,
            'total_payroll_hours' => 8.00,
            'overtime_authorized_hours' => 0.00,
            'velada_authorized_hours' => 0.00,
            'late_minutes' => 0,
            'early_departure_minutes' => 0,
            'status' => 'present',
            'is_holiday' => false,
            'is_weekend_work' => false,
            'is_night_shift' => false,
            'qualifies_for_punctuality_bonus' => false,
            'qualifies_for_night_shift_bonus' => false,
            'had_breakfast' => false,
            'requires_review' => false,
            'has_anomalies' => false,
            'anomaly_count' => 0,
            'lunch_deviation_minutes' => 0,
            'notes' => null,
            'raw_punches' => null,
        ];
    }

    /**
     * Mark the employee as late by the given minutes.
     */
    public function late(int $minutes = 15): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'late',
            'late_minutes' => $minutes,
        ]);
    }

    /**
     * Mark the record as an absence.
     */
    public function absent(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'absent',
            'check_in' => null,
            'check_out' => null,
            'lunch_out' => null,
            'lunch_in' => null,
            'worked_hours' => 0.00,
            'total_payroll_hours' => 0.00,
        ]);
    }

    /**
     * Record with overtime hours.
     */
    public function withOvertime(float $hours = 2.00): static
    {
        return $this->state(fn (array $attributes) => [
            'overtime_hours' => $hours,
            'total_payroll_hours' => 8.00 + $hours,
        ]);
    }

    /**
     * Record flagged with anomalies and pending review.
     */
    public function withAnomalies(int $count = 1): static
    {
        return $this->state(fn (array $attributes) => [
            'has_anomalies' => true,
            'anomaly_count' => $count,
            'requires_review' => true,
        ]);
    }

    /**
     * Weekend work record.
     */
    public function weekendWork(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_weekend_work' => true,
        ]);
    }

    /**
     * Record manually edited by a user.
     */
    public function manuallyEdited(?int $userId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'manually_edited_by' => $userId ?? User::factory(),
            'manually_edited_at' => now(),
            'manual_edit_reason' => 'Correccion de checada por RRHH.',
        ]);
    }
}
