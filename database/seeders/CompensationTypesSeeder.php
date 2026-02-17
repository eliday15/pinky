<?php

namespace Database\Seeders;

use App\Models\CompensationType;
use Illuminate\Database\Seeder;

/**
 * Seeds default compensation type concepts for the HR system.
 *
 * Uses percentage-based calculation:
 * - percentage_value represents % of the employee's daily salary
 *   e.g., 50 = 50% of daily salary (equivalent to old multiplier 1.5)
 */
class CompensationTypesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $types = [
            [
                'name' => 'Hora Extra',
                'code' => 'HE',
                'description' => 'Hora extra trabajada (50% adicional)',
                'calculation_type' => 'percentage',
                'percentage_value' => 50.00,
            ],
            [
                'name' => 'Hora Extra Doble',
                'code' => 'HED',
                'description' => 'Hora extra doble (100% adicional)',
                'calculation_type' => 'percentage',
                'percentage_value' => 100.00,
            ],
            [
                'name' => 'Hora Extra Triple',
                'code' => 'HET',
                'description' => 'Hora extra triple (200% adicional)',
                'calculation_type' => 'percentage',
                'percentage_value' => 200.00,
            ],
            [
                'name' => 'Velada',
                'code' => 'VEL',
                'description' => 'Trabajo nocturno despues de medianoche (100% adicional)',
                'calculation_type' => 'percentage',
                'percentage_value' => 100.00,
            ],
            [
                'name' => 'Dominical',
                'code' => 'DOM',
                'description' => 'Trabajo en domingo con prima dominical (25% adicional)',
                'calculation_type' => 'percentage',
                'percentage_value' => 25.00,
            ],
            [
                'name' => 'Dia Festivo',
                'code' => 'FEST',
                'description' => 'Trabajo en dia festivo oficial (100% adicional)',
                'calculation_type' => 'percentage',
                'percentage_value' => 100.00,
            ],
        ];

        foreach ($types as $type) {
            CompensationType::firstOrCreate(
                ['code' => $type['code']],
                $type
            );
        }
    }
}
