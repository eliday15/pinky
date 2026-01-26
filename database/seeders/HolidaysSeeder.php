<?php

namespace Database\Seeders;

use App\Models\Holiday;
use Illuminate\Database\Seeder;

class HolidaysSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Días festivos obligatorios en México 2025
        $holidays = [
            ['date' => '2025-01-01', 'name' => 'Año Nuevo', 'is_mandatory' => true, 'pay_multiplier' => 2.00],
            ['date' => '2025-02-03', 'name' => 'Día de la Constitución', 'is_mandatory' => true, 'pay_multiplier' => 2.00],
            ['date' => '2025-03-17', 'name' => 'Natalicio de Benito Juárez', 'is_mandatory' => true, 'pay_multiplier' => 2.00],
            ['date' => '2025-04-17', 'name' => 'Jueves Santo', 'is_mandatory' => false, 'pay_multiplier' => 1.50],
            ['date' => '2025-04-18', 'name' => 'Viernes Santo', 'is_mandatory' => false, 'pay_multiplier' => 1.50],
            ['date' => '2025-05-01', 'name' => 'Día del Trabajo', 'is_mandatory' => true, 'pay_multiplier' => 2.00],
            ['date' => '2025-09-16', 'name' => 'Día de la Independencia', 'is_mandatory' => true, 'pay_multiplier' => 2.00],
            ['date' => '2025-11-17', 'name' => 'Día de la Revolución', 'is_mandatory' => true, 'pay_multiplier' => 2.00],
            ['date' => '2025-12-25', 'name' => 'Navidad', 'is_mandatory' => true, 'pay_multiplier' => 2.00],
        ];

        foreach ($holidays as $holiday) {
            Holiday::create($holiday);
        }
    }
}
