<?php

namespace Database\Seeders;

use App\Models\FiscalIsrBracket;
use App\Models\FiscalSubsidyBracket;
use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

/**
 * Siembra la configuración fiscal 2026 del motor de retenciones (Nivel 1).
 * Tarifa ISR semanal DERIVADA y VERIFICADA contra el PDF de Contpaq Semana 28
 * (reproduce el ISR de los 155 empleados al ±$1). Idempotente.
 */
class FiscalSettingsSeeder extends Seeder
{
    public function run(): void
    {
        // ---- Tarifa ISR semanal (lower_limit, cuota_fija, % sobre excedente) ----
        // Los 6 primeros brackets reproducen Contpaq exacto; los superiores (30%+)
        // se agregan de la tarifa estándar para ingresos altos (sin empleados que
        // los alcancen en el dataset; ajustar si se valida contra Contpaq arriba).
        $isr = [
            [0.01, 0.00, 6.4000],       // 6.40% (extrapolado hacia abajo; SM va exento)
            [1650.73, 96.93, 10.8800],
            [2900.72, 232.93, 16.0000],
            [3372.46, 308.41, 17.9200],
            [4037.31, 427.55, 21.3600],
            [8142.71, 1304.47, 23.5200],
            [11336.01, 2056.50, 30.0000],  // estándar (alto ingreso, sin validar vs Contpaq)
            [21618.24, 5141.20, 32.0000],
            [28825.09, 7447.35, 34.0000],
            [86449.60, 27039.71, 35.0000],
        ];
        FiscalIsrBracket::where('period_type', 'weekly')->delete();
        foreach ($isr as [$lower, $fee, $pct]) {
            FiscalIsrBracket::create([
                'period_type' => 'weekly',
                'lower_limit' => $lower,
                'fixed_fee' => $fee,
                'percent_over_excess' => $pct,
            ]);
        }

        // ---- Subsidio para el empleo (semanal): $123.34 hasta el umbral 2024 ----
        FiscalSubsidyBracket::where('period_type', 'weekly')->delete();
        FiscalSubsidyBracket::create([
            'period_type' => 'weekly',
            'lower_limit' => 0.01,
            'upper_limit' => 2653.38, // umbral: separa 2633 (con subsidio) de 2666 (sin)
            'subsidy' => 123.34,
        ]);

        // ---- Escalares 2026 (system_settings) ----
        $settings = [
            ['fiscal_retentions_enabled', '0', 'Activar retenciones ISR/IMSS/Infonavit en la nómina'],
            ['fiscal_uma_daily', '113.14', 'UMA diaria (2026)'],
            ['fiscal_minimum_wage_daily', '315.04', 'Salario mínimo diario (2026)'],
            ['fiscal_imss_worker_fixed_pct', '2.375', 'IMSS obrero: % fijo sobre SBC'],
            ['fiscal_imss_eym_excess_pct', '0.40', 'IMSS obrero: % sobre excedente de 3 UMA'],
            ['fiscal_imss_excess_uma_multiple', '3', 'IMSS: múltiplo de UMA del excedente'],
            ['fiscal_sbc_cap_uma', '25', 'Tope del SBC en UMA'],
        ];
        foreach ($settings as [$key, $value, $label]) {
            SystemSetting::updateOrCreate(
                ['key' => $key],
                ['value' => $value, 'type' => 'string', 'group' => 'fiscal', 'label' => $label],
            );
        }
    }
}
