<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Módulo de desayunos: ajustes globales (grupo 'breakfast').
 *
 * - breakfast_cost: costo fijo por desayuno; se congela en cada cobro y es lo
 *   que la nómina semanal le paga al vendedor por desayuno entregado.
 * - breakfast_window_minutes: minutos ANTES de la hora de entrada del empleado
 *   en los que puede cobrar su desayuno (en la hora de entrada en punto ya no).
 * - breakfast_vendor_employee_id: empleado vendedor que cobra el total de los
 *   desayunos en su nómina semanal.
 * - breakfast_face_max_distance: distancia máxima (euclidiana, face-api.js)
 *   entre el rostro en vivo y la foto del empleado para aceptar el cobro.
 */
return new class extends Migration
{
    private const SETTINGS = [
        [
            'key' => 'breakfast_cost',
            'value' => '30',
            'type' => 'float',
            'label' => 'Costo por Desayuno',
            'description' => 'Costo fijo de cada desayuno. Se congela al momento del cobro y es lo que se paga al vendedor por desayuno en la nomina semanal.',
        ],
        [
            'key' => 'breakfast_window_minutes',
            'value' => '60',
            'type' => 'integer',
            'label' => 'Ventana de Cobro (minutos)',
            'description' => 'Minutos antes de la hora de entrada en los que el empleado puede cobrar su desayuno. Al llegar la hora de entrada ya no se entrega.',
        ],
        [
            'key' => 'breakfast_vendor_employee_id',
            'value' => '0',
            'type' => 'integer',
            'label' => 'Empleado Vendedor',
            'description' => 'Empleado que cobra el total de los desayunos entregados en su nomina semanal.',
        ],
        [
            'key' => 'breakfast_face_max_distance',
            'value' => '0.5',
            'type' => 'float',
            'label' => 'Umbral de Reconocimiento Facial',
            'description' => 'Distancia maxima entre el rostro en vivo y la foto del empleado para aceptar el cobro (menor = mas estricto). Rango tipico 0.4 - 0.6.',
        ],
    ];

    public function up(): void
    {
        foreach (self::SETTINGS as $setting) {
            $exists = DB::table('system_settings')->where('key', $setting['key'])->exists();
            if ($exists) {
                continue;
            }

            DB::table('system_settings')->insert($setting + [
                'group' => 'breakfast',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('system_settings')
            ->whereIn('key', array_column(self::SETTINGS, 'key'))
            ->delete();
    }
};
