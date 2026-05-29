<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for PayrollEntry model.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PayrollEntry>
 */
class PayrollEntryFactory extends Factory
{
    protected $model = PayrollEntry::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $hourlyRate = fake()->randomFloat(2, 50, 200);
        $regularHours = fake()->randomFloat(2, 30, 48);
        $overtimeHours = fake()->randomFloat(2, 0, 10);

        $regularPay = round($hourlyRate * $regularHours, 2);
        $overtimePay = round($hourlyRate * 2 * $overtimeHours, 2);
        $grossPay = round($regularPay + $overtimePay, 2);

        return [
            'payroll_period_id' => PayrollPeriod::factory(),
            'employee_id' => Employee::factory(),

            // Applied rates
            'hourly_rate' => $hourlyRate,
            'overtime_multiplier' => 2.00,
            'holiday_multiplier' => 2.00,

            // Hours
            'regular_hours' => $regularHours,
            'overtime_hours' => $overtimeHours,
            'holiday_hours' => 0,
            'weekend_hours' => 0,
            'night_shift_hours' => 0,

            // Day counters
            'days_worked' => fake()->numberBetween(4, 6),
            'days_absent' => 0,
            'days_late' => 0,
            'punctuality_days' => fake()->numberBetween(0, 6),
            'night_shift_days' => 0,
            'late_absences_generated' => 0,
            'vacation_days_paid' => 0,

            // Money components
            'regular_pay' => $regularPay,
            'overtime_pay' => $overtimePay,
            'holiday_pay' => 0,
            'weekend_pay' => 0,
            'vacation_pay' => 0,
            'punctuality_bonus' => 0,
            'dinner_allowance' => 0,
            'night_shift_bonus' => 0,
            'weekly_bonus' => 0,
            'monthly_bonus' => 0,
            'bonuses' => 0,

            // Velada (night shift) fields
            'velada_hours' => 0,
            'velada_authorized_hours' => 0,
            'velada_multiplier' => 2.00,
            'velada_pay' => 0,
            'overtime_authorized_hours' => 0,

            'deductions' => 0,
            'gross_pay' => $grossPay,
            'net_pay' => $grossPay,
            'calculation_breakdown' => null,
        ];
    }

    /**
     * Entry with overtime hours and pay.
     */
    public function withOvertime(float $hours = 8.0): static
    {
        return $this->state(function (array $attributes) use ($hours) {
            $rate = $attributes['hourly_rate'] ?? 100.00;
            $pay = round($rate * 2 * $hours, 2);

            return [
                'overtime_hours' => $hours,
                'overtime_authorized_hours' => $hours,
                'overtime_pay' => $pay,
            ];
        });
    }

    /**
     * Entry with velada (night shift) hours and pay.
     */
    public function withVelada(float $hours = 4.0): static
    {
        return $this->state(function (array $attributes) use ($hours) {
            $rate = $attributes['hourly_rate'] ?? 100.00;
            $pay = round($rate * 2 * $hours, 2);

            return [
                'velada_hours' => $hours,
                'velada_authorized_hours' => $hours,
                'velada_multiplier' => 2.00,
                'velada_pay' => $pay,
            ];
        });
    }

    /**
     * Entry with holiday hours and pay.
     */
    public function withHoliday(float $hours = 8.0): static
    {
        return $this->state(function (array $attributes) use ($hours) {
            $rate = $attributes['hourly_rate'] ?? 100.00;

            return [
                'holiday_hours' => $hours,
                'holiday_pay' => round($rate * 2 * $hours, 2),
            ];
        });
    }

    /**
     * Entry with combined monthly and weekly bonuses.
     */
    public function withBonuses(float $weekly = 200.00, float $monthly = 500.00): static
    {
        return $this->state(fn (array $attributes) => [
            'weekly_bonus' => $weekly,
            'monthly_bonus' => $monthly,
            'bonuses' => $weekly + $monthly,
        ]);
    }

    /**
     * Entry with deductions applied (reduces net pay).
     */
    public function withDeductions(float $amount = 150.00): static
    {
        return $this->state(fn (array $attributes) => [
            'deductions' => $amount,
            'net_pay' => round(($attributes['gross_pay'] ?? 0) - $amount, 2),
        ]);
    }

    /**
     * Entry recording absences.
     */
    public function withAbsences(int $days = 1): static
    {
        return $this->state(fn (array $attributes) => [
            'days_absent' => $days,
        ]);
    }
}
