<?php

use App\Models\Holiday;
use Illuminate\Database\Migrations\Migration;

/**
 * Idempotently seeds the holidays table with:
 *   - Mexican federal holidays (Article 74 LFT, published in the DOF) 2025-2030
 *   - Jewish high-holiday Yom Tov days (Rosh Hashana 1-2 and Yom Kippur) 2025-2030
 *
 * Used by the absence/payroll engine so a holiday date is never counted as a
 * "falta" or "ausencia" — see ZktecoSyncService::calculateAttendanceMetrics
 * and PayrollCalculatorService::calculateAttendanceMetrics.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = array_merge(
            $this->mexicanDofHolidays(),
            $this->jewishYomTovDays(),
        );

        foreach ($rows as $row) {
            Holiday::updateOrCreate(
                ['date' => $row['date']],
                [
                    'name' => $row['name'],
                    'is_mandatory' => $row['is_mandatory'] ?? true,
                    'pay_multiplier' => $row['pay_multiplier'] ?? 2.00,
                ],
            );
        }
    }

    public function down(): void
    {
        // No-op: removing seed rows could orphan attendance flags. If reseeding
        // is needed, edit the holidays table directly.
    }

    /**
     * Article 74 LFT holidays for 2025-2030.
     * Source: Diario Oficial de la Federación.
     */
    private function mexicanDofHolidays(): array
    {
        return [
            ['date' => '2025-01-01', 'name' => 'Año Nuevo'],
            ['date' => '2025-02-03', 'name' => 'Día de la Constitución'],
            ['date' => '2025-03-17', 'name' => 'Natalicio de Benito Juárez'],
            ['date' => '2025-05-01', 'name' => 'Día del Trabajo'],
            ['date' => '2025-09-16', 'name' => 'Día de la Independencia'],
            ['date' => '2025-11-17', 'name' => 'Día de la Revolución'],
            ['date' => '2025-12-25', 'name' => 'Navidad'],

            ['date' => '2026-01-01', 'name' => 'Año Nuevo'],
            ['date' => '2026-02-02', 'name' => 'Día de la Constitución'],
            ['date' => '2026-03-16', 'name' => 'Natalicio de Benito Juárez'],
            ['date' => '2026-05-01', 'name' => 'Día del Trabajo'],
            ['date' => '2026-09-16', 'name' => 'Día de la Independencia'],
            ['date' => '2026-11-16', 'name' => 'Día de la Revolución'],
            ['date' => '2026-12-25', 'name' => 'Navidad'],

            ['date' => '2027-01-01', 'name' => 'Año Nuevo'],
            ['date' => '2027-02-01', 'name' => 'Día de la Constitución'],
            ['date' => '2027-03-15', 'name' => 'Natalicio de Benito Juárez'],
            ['date' => '2027-05-01', 'name' => 'Día del Trabajo'],
            ['date' => '2027-09-16', 'name' => 'Día de la Independencia'],
            ['date' => '2027-11-15', 'name' => 'Día de la Revolución'],
            ['date' => '2027-12-25', 'name' => 'Navidad'],

            ['date' => '2028-01-01', 'name' => 'Año Nuevo'],
            ['date' => '2028-02-07', 'name' => 'Día de la Constitución'],
            ['date' => '2028-03-20', 'name' => 'Natalicio de Benito Juárez'],
            ['date' => '2028-05-01', 'name' => 'Día del Trabajo'],
            ['date' => '2028-09-16', 'name' => 'Día de la Independencia'],
            ['date' => '2028-11-20', 'name' => 'Día de la Revolución'],
            ['date' => '2028-12-25', 'name' => 'Navidad'],

            ['date' => '2029-01-01', 'name' => 'Año Nuevo'],
            ['date' => '2029-02-05', 'name' => 'Día de la Constitución'],
            ['date' => '2029-03-19', 'name' => 'Natalicio de Benito Juárez'],
            ['date' => '2029-05-01', 'name' => 'Día del Trabajo'],
            ['date' => '2029-09-16', 'name' => 'Día de la Independencia'],
            ['date' => '2029-11-19', 'name' => 'Día de la Revolución'],
            ['date' => '2029-12-25', 'name' => 'Navidad'],

            ['date' => '2030-01-01', 'name' => 'Año Nuevo'],
            ['date' => '2030-02-04', 'name' => 'Día de la Constitución'],
            ['date' => '2030-03-18', 'name' => 'Natalicio de Benito Juárez'],
            ['date' => '2030-05-01', 'name' => 'Día del Trabajo'],
            ['date' => '2030-09-16', 'name' => 'Día de la Independencia'],
            ['date' => '2030-11-18', 'name' => 'Día de la Revolución'],
            ['date' => '2030-12-25', 'name' => 'Navidad'],
        ];
    }

    /**
     * Yom Tov days the user requested: Rosh Hashana day 1, day 2, and Yom Kippur.
     * Civil dates align with the Hebrew calendar 5786-5791.
     */
    private function jewishYomTovDays(): array
    {
        $rows = [
            ['date' => '2025-09-23', 'name' => 'Rosh Hashaná (día 1)'],
            ['date' => '2025-09-24', 'name' => 'Rosh Hashaná (día 2)'],
            ['date' => '2025-10-02', 'name' => 'Yom Kipur'],

            ['date' => '2026-09-12', 'name' => 'Rosh Hashaná (día 1)'],
            ['date' => '2026-09-13', 'name' => 'Rosh Hashaná (día 2)'],
            ['date' => '2026-09-21', 'name' => 'Yom Kipur'],

            ['date' => '2027-10-02', 'name' => 'Rosh Hashaná (día 1)'],
            ['date' => '2027-10-03', 'name' => 'Rosh Hashaná (día 2)'],
            ['date' => '2027-10-11', 'name' => 'Yom Kipur'],

            ['date' => '2028-09-21', 'name' => 'Rosh Hashaná (día 1)'],
            ['date' => '2028-09-22', 'name' => 'Rosh Hashaná (día 2)'],
            ['date' => '2028-09-30', 'name' => 'Yom Kipur'],

            ['date' => '2029-09-10', 'name' => 'Rosh Hashaná (día 1)'],
            ['date' => '2029-09-11', 'name' => 'Rosh Hashaná (día 2)'],
            ['date' => '2029-09-19', 'name' => 'Yom Kipur'],

            ['date' => '2030-09-28', 'name' => 'Rosh Hashaná (día 1)'],
            ['date' => '2030-09-29', 'name' => 'Rosh Hashaná (día 2)'],
            ['date' => '2030-10-07', 'name' => 'Yom Kipur'],
        ];

        return $rows;
    }
};
