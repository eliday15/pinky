<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

/**
 * Seeds default system settings for PINKY HR system.
 */
class SystemSettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = [
            // Attendance Settings
            [
                'key' => 'late_tolerance_minutes',
                'value' => '10',
                'type' => 'integer',
                'group' => 'attendance',
                'label' => 'Tolerancia de Retardo (minutos)',
                'description' => 'Minutos de tolerancia antes de marcar como retardo',
            ],
            [
                'key' => 'max_late_minutes_before_absence',
                'value' => '60',
                'type' => 'integer',
                'group' => 'attendance',
                'label' => 'Retardo Maximo antes de Falta (minutos)',
                'description' => 'Minutos de retardo maximo antes de convertirse en falta',
            ],
            [
                'key' => 'late_to_absence_count',
                'value' => '6',
                'type' => 'integer',
                'group' => 'attendance',
                'label' => 'Retardos para Generar Falta',
                'description' => 'Numero de retardos mensuales que generan una falta automatica',
            ],
            [
                'key' => 'punctuality_bonus_minutes',
                'value' => '5',
                'type' => 'integer',
                'group' => 'attendance',
                'label' => 'Minutos Anticipados para Bono Puntualidad',
                'description' => 'Minutos de anticipacion requeridos para calificar al bono de puntualidad (desayuno)',
            ],
            [
                'key' => 'early_departure_is_absence',
                'value' => 'true',
                'type' => 'boolean',
                'group' => 'attendance',
                'label' => 'Salida Anticipada es Falta',
                'description' => 'Si una salida anticipada sin autorizacion cuenta como falta',
            ],
            [
                'key' => 'allow_post_authorization',
                'value' => 'true',
                'type' => 'boolean',
                'group' => 'attendance',
                'label' => 'Permitir Post-Autorizacion',
                'description' => 'Permitir crear autorizaciones despues del evento (horas extra detectadas)',
            ],

            // Payroll Settings
            [
                'key' => 'payroll_week_start_day',
                'value' => '3',
                'type' => 'integer',
                'group' => 'payroll',
                'label' => 'Dia Inicio de Semana Nomina',
                'description' => 'Dia de inicio de semana para nomina (0=Domingo, 1=Lunes, ..., 3=Miercoles)',
            ],
            [
                'key' => 'payroll_week_end_day',
                'value' => '2',
                'type' => 'integer',
                'group' => 'payroll',
                'label' => 'Dia Fin de Semana Nomina',
                'description' => 'Dia de fin de semana para nomina (0=Domingo, 1=Lunes, ..., 2=Martes)',
            ],
            [
                'key' => 'punctuality_bonus_amount',
                'value' => '50.00',
                'type' => 'float',
                'group' => 'payroll',
                'label' => 'Monto Bono Puntualidad (MXN)',
                'description' => 'Monto diario del bono de puntualidad (desayuno)',
            ],
            [
                'key' => 'dinner_allowance_amount',
                'value' => '75.00',
                'type' => 'float',
                'group' => 'payroll',
                'label' => 'Monto Cena (MXN)',
                'description' => 'Monto por cena cuando hay velada autorizada',
            ],
            [
                'key' => 'overtime_rate_multiplier',
                'value' => '1.5',
                'type' => 'float',
                'group' => 'payroll',
                'label' => 'Multiplicador Horas Extra',
                'description' => 'Multiplicador por defecto para horas extra',
            ],
            [
                'key' => 'holiday_rate_multiplier',
                'value' => '2.0',
                'type' => 'float',
                'group' => 'payroll',
                'label' => 'Multiplicador Dia Festivo',
                'description' => 'Multiplicador por defecto para dias festivos trabajados',
            ],
            [
                'key' => 'weekend_rate_multiplier',
                'value' => '1.5',
                'type' => 'float',
                'group' => 'payroll',
                'label' => 'Multiplicador Fin de Semana',
                'description' => 'Multiplicador para dias de fin de semana trabajados',
            ],
            [
                'key' => 'night_shift_bonus',
                'value' => '100.00',
                'type' => 'float',
                'group' => 'payroll',
                'label' => 'Bono por Velada (MXN)',
                'description' => 'Monto adicional por turno nocturno/velada',
            ],

            // General Settings
            [
                'key' => 'company_name',
                'value' => 'Empresa PINKY',
                'type' => 'string',
                'group' => 'general',
                'label' => 'Nombre de la Empresa',
                'description' => 'Nombre de la empresa para reportes y documentos',
            ],
            [
                'key' => 'timezone',
                'value' => 'America/Mexico_City',
                'type' => 'string',
                'group' => 'general',
                'label' => 'Zona Horaria',
                'description' => 'Zona horaria del sistema',
            ],
            [
                'key' => 'date_format',
                'value' => 'd/m/Y',
                'type' => 'string',
                'group' => 'general',
                'label' => 'Formato de Fecha',
                'description' => 'Formato de fecha para mostrar en la interfaz',
            ],
        ];

        foreach ($settings as $setting) {
            SystemSetting::updateOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }
    }
}
