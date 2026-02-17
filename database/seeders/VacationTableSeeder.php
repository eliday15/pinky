<?php

namespace Database\Seeders;

use App\Models\VacationTable;
use Illuminate\Database\Seeder;

/**
 * Seeds the vacation entitlement table per Mexican LFT 2023.
 */
class VacationTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $entries = [
            ['years_of_service' => 1, 'vacation_days' => 12],
            ['years_of_service' => 2, 'vacation_days' => 14],
            ['years_of_service' => 3, 'vacation_days' => 16],
            ['years_of_service' => 4, 'vacation_days' => 18],
            ['years_of_service' => 5, 'vacation_days' => 20],
            ['years_of_service' => 6, 'vacation_days' => 22],
            ['years_of_service' => 7, 'vacation_days' => 22],
            ['years_of_service' => 8, 'vacation_days' => 22],
            ['years_of_service' => 9, 'vacation_days' => 22],
            ['years_of_service' => 10, 'vacation_days' => 22],
            ['years_of_service' => 11, 'vacation_days' => 24],
            ['years_of_service' => 12, 'vacation_days' => 24],
            ['years_of_service' => 13, 'vacation_days' => 24],
            ['years_of_service' => 14, 'vacation_days' => 24],
            ['years_of_service' => 15, 'vacation_days' => 24],
            ['years_of_service' => 16, 'vacation_days' => 26],
            ['years_of_service' => 17, 'vacation_days' => 26],
            ['years_of_service' => 18, 'vacation_days' => 26],
            ['years_of_service' => 19, 'vacation_days' => 26],
            ['years_of_service' => 20, 'vacation_days' => 26],
            ['years_of_service' => 21, 'vacation_days' => 28],
            ['years_of_service' => 22, 'vacation_days' => 28],
            ['years_of_service' => 23, 'vacation_days' => 28],
            ['years_of_service' => 24, 'vacation_days' => 28],
            ['years_of_service' => 25, 'vacation_days' => 28],
            ['years_of_service' => 26, 'vacation_days' => 30],
            ['years_of_service' => 27, 'vacation_days' => 30],
            ['years_of_service' => 28, 'vacation_days' => 30],
            ['years_of_service' => 29, 'vacation_days' => 30],
            ['years_of_service' => 30, 'vacation_days' => 30],
            ['years_of_service' => 31, 'vacation_days' => 32],
            ['years_of_service' => 32, 'vacation_days' => 32],
            ['years_of_service' => 33, 'vacation_days' => 32],
            ['years_of_service' => 34, 'vacation_days' => 32],
            ['years_of_service' => 35, 'vacation_days' => 32],
        ];

        foreach ($entries as $entry) {
            VacationTable::firstOrCreate(
                ['years_of_service' => $entry['years_of_service']],
                $entry
            );
        }
    }
}
