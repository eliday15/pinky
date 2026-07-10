<?php

namespace Database\Seeders;

use App\Models\CompensationType;
use Illuminate\Database\Seeder;

/**
 * Crea el concepto "Aguinaldo" marcado para pagarse por TRANSFERENCIA (banco)
 * junto con el sueldo de la semana, como Contpaq. El monto es MANUAL por
 * empleado (finiquitos/gratificaciones): se captura por empleado cuando aplica.
 *
 * Idempotente: firstOrCreate por código, no pisa el concepto si ya existe.
 */
class AguinaldoConceptSeeder extends Seeder
{
    public function run(): void
    {
        CompensationType::firstOrCreate(
            ['code' => 'AGUIN'],
            [
                'name' => 'Aguinaldo',
                'description' => 'Aguinaldo / gratificación (monto manual por empleado, se paga por transferencia como Contpaq)',
                'calculation_type' => 'fixed',
                'fixed_amount' => 0.00,
                'application_mode' => CompensationType::APPLICATION_ONE_TIME,
                'payment_period' => CompensationType::PAYMENT_PERIOD_WEEKLY,
                'pays_via_transfer' => true,
                'is_active' => true,
                'priority' => 90,
            ],
        );
    }
}
