<?php

namespace Database\Seeders;

use App\Models\CompensationType;
use Illuminate\Database\Seeder;

/**
 * Seeds default compensation type concepts for the HR system.
 *
 * Uses percentage-based calculation with application_mode and authorization_type
 * to integrate with the authorization and payroll systems.
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
                'application_mode' => 'per_hour',
                'authorization_type' => 'overtime',
                'priority' => 10,
            ],
            [
                'name' => 'Hora Extra Doble',
                'code' => 'HED',
                'description' => 'Hora extra doble (100% adicional)',
                'calculation_type' => 'percentage',
                'percentage_value' => 100.00,
                'application_mode' => 'per_hour',
                'authorization_type' => 'overtime',
                'priority' => 20,
            ],
            [
                'name' => 'Hora Extra Triple',
                'code' => 'HET',
                'description' => 'Hora extra triple (200% adicional)',
                'calculation_type' => 'percentage',
                'percentage_value' => 200.00,
                'application_mode' => 'per_hour',
                'authorization_type' => 'overtime',
                'priority' => 30,
            ],
            [
                'name' => 'Velada',
                'code' => 'VEL',
                'description' => 'Trabajo nocturno despues de medianoche (100% adicional)',
                'calculation_type' => 'percentage',
                'percentage_value' => 100.00,
                'application_mode' => 'per_hour',
                'authorization_type' => 'night_shift',
                'priority' => 10,
            ],
            [
                'name' => 'Dominical',
                'code' => 'DOM',
                'description' => 'Trabajo en domingo con prima dominical (25% adicional)',
                'calculation_type' => 'percentage',
                'percentage_value' => 25.00,
                'application_mode' => 'per_day',
                'authorization_type' => 'special',
                'priority' => 10,
            ],
            [
                'name' => 'Dia Festivo',
                'code' => 'FEST',
                'description' => 'Trabajo en dia festivo oficial (100% adicional)',
                'calculation_type' => 'percentage',
                'percentage_value' => 100.00,
                'application_mode' => 'per_day',
                'authorization_type' => 'holiday_worked',
                'priority' => 10,
            ],
        ];

        foreach ($types as $type) {
            CompensationType::updateOrCreate(
                ['code' => $type['code']],
                $type
            );
        }
    }
}
