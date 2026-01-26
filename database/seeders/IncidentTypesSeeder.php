<?php

namespace Database\Seeders;

use App\Models\IncidentType;
use Illuminate\Database\Seeder;

class IncidentTypesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $types = [
            [
                'name' => 'Vacaciones',
                'code' => 'VAC',
                'category' => 'vacation',
                'is_paid' => true,
                'deducts_vacation' => true,
                'requires_approval' => true,
                'color' => '#10B981',
            ],
            [
                'name' => 'Incapacidad',
                'code' => 'INC',
                'category' => 'sick_leave',
                'is_paid' => true,
                'deducts_vacation' => false,
                'requires_approval' => true,
                'color' => '#F59E0B',
            ],
            [
                'name' => 'Permiso con goce',
                'code' => 'PCG',
                'category' => 'permission',
                'is_paid' => true,
                'deducts_vacation' => false,
                'requires_approval' => true,
                'color' => '#3B82F6',
            ],
            [
                'name' => 'Permiso sin goce',
                'code' => 'PSG',
                'category' => 'permission',
                'is_paid' => false,
                'deducts_vacation' => false,
                'requires_approval' => true,
                'color' => '#6366F1',
            ],
            [
                'name' => 'Falta injustificada',
                'code' => 'FIN',
                'category' => 'absence',
                'is_paid' => false,
                'deducts_vacation' => false,
                'requires_approval' => false,
                'color' => '#EF4444',
            ],
            [
                'name' => 'Falta justificada',
                'code' => 'FJU',
                'category' => 'absence',
                'is_paid' => false,
                'deducts_vacation' => false,
                'requires_approval' => true,
                'color' => '#F97316',
            ],
            [
                'name' => 'Falta por retardos',
                'code' => 'FRT',
                'category' => 'late_accumulation',
                'is_paid' => false,
                'deducts_vacation' => false,
                'requires_approval' => false,
                'color' => '#DC2626',
            ],
            [
                'name' => 'Día festivo trabajado',
                'code' => 'DFT',
                'category' => 'special',
                'is_paid' => true,
                'deducts_vacation' => false,
                'requires_approval' => false,
                'color' => '#8B5CF6',
            ],
            [
                'name' => 'Suspensión',
                'code' => 'SUS',
                'category' => 'absence',
                'is_paid' => false,
                'deducts_vacation' => false,
                'requires_approval' => true,
                'color' => '#991B1B',
            ],
        ];

        foreach ($types as $type) {
            IncidentType::create($type);
        }
    }
}
