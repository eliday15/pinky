<?php

namespace Database\Factories;

use App\Models\SystemSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for SystemSetting model.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SystemSetting>
 */
class SystemSettingFactory extends Factory
{
    protected $model = SystemSetting::class;

    /**
     * Define the model's default state.
     *
     * Defaults to a string-typed setting in the general group. The DB
     * stores `value` as text; type drives runtime casting via getCastedValue().
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'value' => fake()->word(),
            'type' => 'string',
            'group' => SystemSetting::GROUP_GENERAL,
            'label' => fake()->words(2, true),
            'description' => fake()->sentence(),
        ];
    }

    /**
     * Create an integer-typed setting.
     */
    public function integer(int $value = 12): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'integer',
            'value' => (string) $value,
        ]);
    }

    /**
     * Create a float-typed setting.
     */
    public function float(float $value = 1.5): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'float',
            'value' => (string) $value,
        ]);
    }

    /**
     * Create a boolean-typed setting (stored as 'true'/'false').
     */
    public function boolean(bool $value = true): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'boolean',
            'value' => $value ? 'true' : 'false',
        ]);
    }

    /**
     * Create a JSON-typed setting (value is json_encoded).
     */
    public function json(array $value = ['enabled' => true]): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'json',
            'value' => json_encode($value),
        ]);
    }

    /**
     * Place the setting in the attendance group.
     */
    public function attendance(): static
    {
        return $this->state(fn (array $attributes) => [
            'group' => SystemSetting::GROUP_ATTENDANCE,
        ]);
    }

    /**
     * Place the setting in the payroll group.
     */
    public function payroll(): static
    {
        return $this->state(fn (array $attributes) => [
            'group' => SystemSetting::GROUP_PAYROLL,
        ]);
    }
}
