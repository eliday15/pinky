<?php

namespace Database\Seeders;

use App\Models\CompensationType;
use App\Services\MaquilaBonusMetricsService;
use Illuminate\Database\Seeder;

/**
 * Crea los 5 conceptos de bono de maquila (mensuales) cuya CANTIDAD se llena
 * sola desde basemaquila y cuyo COSTO POR UNIDAD lo fija el superadmin.
 *
 * Cada uno es one_time + fixed (fixed_amount = costo por unidad, arranca en 0),
 * payment_period=monthly, is_recurring=false (requiere autorización, aprobada
 * solo por superadmin vía el pivote compensation_type_approver).
 *
 * Idempotente: firstOrCreate por código, NO pisa fixed_amount ni la lista de
 * aprobadores si el concepto ya existe.
 */
class MaquilaBonusConceptsSeeder extends Seeder
{
    public function run(): void
    {
        $priority = 40;

        foreach (MaquilaBonusMetricsService::catalog() as $code => $meta) {
            CompensationType::firstOrCreate(
                ['code' => $code],
                [
                    'name' => $meta['name'],
                    'description' => $meta['description'],
                    'calculation_type' => 'fixed',
                    'fixed_amount' => 0.00,
                    'application_mode' => CompensationType::APPLICATION_ONE_TIME,
                    'authorization_type' => 'special',
                    'payment_period' => CompensationType::PAYMENT_PERIOD_MONTHLY,
                    'is_recurring' => false,
                    'is_active' => true,
                    'priority' => $priority,
                ],
            );

            $priority++;
        }
    }
}
