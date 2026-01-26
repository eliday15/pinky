<?php

namespace Database\Seeders;

use App\Models\Position;
use Illuminate\Database\Seeder;

class PositionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $positions = [
            ['name' => 'Operador de Corte', 'code' => 'OP_CORTE', 'base_hourly_rate' => 50.00],
            ['name' => 'Costurera', 'code' => 'COSTURERA', 'base_hourly_rate' => 45.00],
            ['name' => 'Supervisor de Línea', 'code' => 'SUP_LINEA', 'base_hourly_rate' => 75.00],
            ['name' => 'Inspector de Calidad', 'code' => 'INS_CALIDAD', 'base_hourly_rate' => 55.00],
            ['name' => 'Empacador', 'code' => 'EMPACADOR', 'base_hourly_rate' => 40.00],
            ['name' => 'Almacenista', 'code' => 'ALMACENISTA', 'base_hourly_rate' => 45.00],
            ['name' => 'Diseñador', 'code' => 'DISENADOR', 'base_hourly_rate' => 80.00],
            ['name' => 'Auxiliar Administrativo', 'code' => 'AUX_ADMIN', 'base_hourly_rate' => 55.00],
            ['name' => 'Gerente de Área', 'code' => 'GTE_AREA', 'base_hourly_rate' => 120.00],
            ['name' => 'Técnico de Mantenimiento', 'code' => 'TEC_MANTO', 'base_hourly_rate' => 65.00],
            ['name' => 'Chofer', 'code' => 'CHOFER', 'base_hourly_rate' => 50.00],
            ['name' => 'Planchador', 'code' => 'PLANCHADOR', 'base_hourly_rate' => 45.00],
        ];

        foreach ($positions as $pos) {
            Position::create($pos);
        }
    }
}
