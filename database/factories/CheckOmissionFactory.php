<?php

namespace Database\Factories;

use App\Models\CheckOmission;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for CheckOmission model.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CheckOmission>
 */
class CheckOmissionFactory extends Factory
{
    protected $model = CheckOmission::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'attendance_record_id' => null,
            'work_date' => fake()->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'reason' => CheckOmission::REASON_DELIVERY,
            'comments' => null,
            'status' => CheckOmission::STATUS_AUTHORIZED,
            'authorized_by' => User::factory(),
            'authorized_at' => now(),
            'approved_by' => null,
            'approved_at' => null,
            'rejected_by' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
            'created_by' => User::factory(),
        ];
    }

    /** Motivo "Entrega de mercancía" (paga completo, sin falta). */
    public function delivery(): static
    {
        return $this->state(fn () => ['reason' => CheckOmission::REASON_DELIVERY]);
    }

    /** Motivo "Trabajo foráneo" (paga completo, sin falta). */
    public function foreignWork(): static
    {
        return $this->state(fn () => ['reason' => CheckOmission::REASON_FOREIGN_WORK]);
    }

    /** Motivo "Otro (especificar)" (se convierte en retardo). */
    public function other(): static
    {
        return $this->state(fn () => [
            'reason' => CheckOmission::REASON_OTHER,
            'comments' => fake()->sentence(),
        ]);
    }

    /** Aprobada por el administrador. */
    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => CheckOmission::STATUS_APPROVED,
            'approved_by' => User::factory(),
            'approved_at' => now(),
        ]);
    }

    /** Rechazada. */
    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => CheckOmission::STATUS_REJECTED,
            'rejected_by' => User::factory(),
            'rejected_at' => now(),
            'rejection_reason' => fake()->sentence(),
        ]);
    }
}
