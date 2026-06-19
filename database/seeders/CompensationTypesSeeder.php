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
            // Horas extra: monto FIJO por hora (no porcentaje de la hora). El
            // monto real lo configura RRHH por concepto/puesto/empleado; estos
            // son valores por defecto para dev/test.
            [
                'name' => 'Hora Extra',
                'code' => 'HE',
                'payment_period' => 'monthly',
                'description' => 'Hora extra trabajada (monto fijo por hora)',
                'calculation_type' => 'fixed',
                'fixed_amount' => 50.00,
                'application_mode' => 'per_hour',
                'authorization_type' => 'overtime',
                'priority' => 10,
            ],
            [
                'name' => 'Hora Extra Doble',
                'code' => 'HED',
                'payment_period' => 'monthly',
                'description' => 'Hora extra doble (monto fijo por hora)',
                'calculation_type' => 'fixed',
                'fixed_amount' => 100.00,
                'application_mode' => 'per_hour',
                'authorization_type' => 'overtime',
                'priority' => 20,
            ],
            [
                'name' => 'Hora Extra Triple',
                'code' => 'HET',
                'payment_period' => 'monthly',
                'description' => 'Hora extra triple (monto fijo por hora)',
                'calculation_type' => 'fixed',
                'fixed_amount' => 150.00,
                'application_mode' => 'per_hour',
                'authorization_type' => 'overtime',
                'priority' => 30,
            ],
            [
                'name' => 'Velada',
                'code' => 'VEL',
                'payment_period' => 'monthly',
                'description' => 'Trabajo nocturno: monto fijo por noche trabajada',
                'calculation_type' => 'fixed',
                'fixed_amount' => 0.00,
                'application_mode' => 'per_day',
                'authorization_type' => 'night_shift',
                'attendance_pull_rule' => 'velada',
                'priority' => 10,
            ],
            [
                'name' => 'Dominical',
                'code' => 'DOM',
                'payment_period' => 'monthly',
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
                'payment_period' => 'monthly',
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
