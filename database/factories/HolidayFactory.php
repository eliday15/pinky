<?php

namespace Database\Factories;

use App\Models\Holiday;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for Holiday model.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Holiday>
 */
class HolidayFactory extends Factory
{
    protected $model = Holiday::class;

    /**
     * Offset SECUENCIAL por proceso para la fecha (columna UNIQUE): un
     * contador monotónico jamás se repite en el proceso (cada proceso
     * paralelo tiene su propia base). El fake()->unique() aleatorio del fix
     * anterior seguía chocando: la RAÍZ del flake era que la migración
     * seed_dof_and_jewish_holidays siembra festivos oficiales HASTA 2030 y
     * la base "2030" caía encima de ellos (offset aleatorio 0-730 ≈ 1-2% de
     * choque; cazado por fin 2026-08-12). Base 2032: después de TODO lo
     * sembrado — si algún día se siembran más años, mover la base más lejos.
     */
    protected static int $dateOffset = 0;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $names = [
            'Año Nuevo',
            'Día de la Constitución',
            'Natalicio de Benito Juárez',
            'Día del Trabajo',
            'Día de la Independencia',
            'Día de la Revolución',
            'Navidad',
        ];

        return [
            'date' => \Carbon\Carbon::create(2032, 1, 1)
                ->addDays(self::$dateOffset++)
                ->format('Y-m-d'),
            'name' => fake()->randomElement($names),
            'is_mandatory' => true,
            'pay_multiplier' => 2.00,
        ];
    }

    /**
     * Create a non-mandatory (optional) holiday.
     */
    public function optional(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_mandatory' => false,
        ]);
    }

    /**
     * Create a mandatory holiday.
     */
    public function mandatory(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_mandatory' => true,
        ]);
    }

    /**
     * Set a specific date for the holiday.
     */
    public function onDate(string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => $date,
        ]);
    }

    /**
     * Set a custom pay multiplier (e.g. triple pay).
     */
    public function withMultiplier(float $multiplier): static
    {
        return $this->state(fn (array $attributes) => [
            'pay_multiplier' => $multiplier,
        ]);
    }
}
