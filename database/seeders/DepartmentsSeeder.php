<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $departments = [
            ['name' => 'Corte', 'code' => 'CORTE'],
            ['name' => 'Diseño', 'code' => 'DISENO'],
            // Almacén PT cuenta y paga el fin de semana por unidades de 6 h
            // (12 h trabajadas en sáb/dom = 2 fines de semana).
            ['name' => 'Almacén PT', 'code' => 'ALMACENPT', 'weekend_unit_hours' => 6],
            ['name' => 'Producción', 'code' => 'PRODUCCION'],
            ['name' => 'Control de Calidad', 'code' => 'CALIDAD'],
            ['name' => 'Empaque', 'code' => 'EMPAQUE'],
            ['name' => 'Administración', 'code' => 'ADMIN'],
            ['name' => 'Recursos Humanos', 'code' => 'RRHH'],
            ['name' => 'Mantenimiento', 'code' => 'MANTO'],
            ['name' => 'Logística', 'code' => 'LOGISTICA'],
        ];

        foreach ($departments as $dept) {
            Department::create($dept);
        }
    }
}
