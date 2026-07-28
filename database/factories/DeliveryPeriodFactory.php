<?php

namespace Database\Factories;

use App\Models\DeliveryPeriod;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DeliveryPeriod>
 */
class DeliveryPeriodFactory extends Factory
{
    protected $model = DeliveryPeriod::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
            'created_by' => null,
        ];
    }
}
