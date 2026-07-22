<?php

namespace Database\Seeders;

use App\Models\CompensationType;
use App\Models\SystemSetting;
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

        // Filtro de nombre en cortador2 (editable en la UI). Órdenes cortadas
        // arranca en CARLOS (el único con ese bono); fusión arranca vacío
        // (cualquier cortador2 con nombre). firstOrCreate no pisa si ya se cambió.
        $cortador2Defaults = [
            MaquilaBonusMetricsService::CODE_ORDENES_CORTADAS => 'CARLOS',
            MaquilaBonusMetricsService::CODE_ORDENES_FUSION => '',
        ];

        foreach ($cortador2Defaults as $code => $default) {
            SystemSetting::firstOrCreate(
                ['key' => MaquilaBonusMetricsService::cortador2SettingKey($code)],
                [
                    'value' => $default,
                    'type' => 'string',
                    'group' => SystemSetting::GROUP_PAYROLL,
                    'label' => "Nombre de cortador2 para {$code}",
                    'description' => 'Filtro de nombre exacto en cortador2 para el bono; vacío = cualquier cortador2 con nombre.',
                ],
            );
        }
    }
}
