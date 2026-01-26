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
            ['name' => 'Almacén PT', 'code' => 'ALMACENPT'],
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
