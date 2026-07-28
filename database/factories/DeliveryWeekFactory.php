<?php

namespace Database\Factories;

use App\Models\DeliveryWeek;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DeliveryWeek>
 */
class DeliveryWeekFactory extends Factory
{
    protected $model = DeliveryWeek::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'week_start' => '2026-06-01', // lunes
            'created_by' => null,
        ];
    }
}
