<?php

namespace App\Console\Commands;

use App\Models\Employee;
use Illuminate\Console\Command;

/**
 * One-time command to fix employee names from an authoritative CSV.
 *
 * CSV format: zkteco_user_id,first_name,last_name,department,...
 */
class FixEmployeeNamesFromCsv extends Command
{
    protected $signature = 'employees:fix-names
                            {csv : Path to the CSV file with correct names}
                            {--dry-run : Show changes without applying them}';

    protected $description = 'Update employee first_name, last_name, full_name from a CSV keyed by zkteco_user_id';

    public function handle(): int
    {
        $csvPath = $this->argument('csv');

        if (! file_exists($csvPath)) {
            $this->error("CSV file not found: {$csvPath}");

            return Command::FAILURE;
        }

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be saved.');
        }

        $lines = array_filter(array_map('trim', file($csvPath)));
        $updated = 0;
        $skipped = 0;
        $notFound = 0;
        $unchanged = 0;
        $changes = [];

        foreach ($lines as $line) {
            $fields = str_getcsv($line);

            if (count($fields) < 3) {
                $skipped++;

                continue;
            }

            $zktecoId = (int) trim($fields[0]);
            $firstName = mb_convert_case(trim($fields[1]), MB_CASE_TITLE, 'UTF-8');
            $lastName = mb_convert_case(trim($fields[2]), MB_CASE_TITLE, 'UTF-8');
            $fullName = trim("{$firstName} {$lastName}");

            if (! $zktecoId || $zktecoId >= 9999) {
                $skipped++;

                continue;
            }

            $employee = Employee::withTrashed()
                ->where('zkteco_user_id', $zktecoId)
                ->first();

            if (! $employee) {
                $notFound++;
                $this->warn("  ZKTeco #{$zktecoId}: not found in employees table");

                continue;
            }

            if ($employee->first_name === $firstName
                && $employee->last_name === $lastName
                && $employee->full_name === $fullName) {
                $unchanged++;

                continue;
            }

            $changes[] = [
                $zktecoId,
                $employee->first_name.' '.$employee->last_name,
                $fullName,
            ];

            if (! $dryRun) {
                $employee->updateQuietly([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'full_name' => $fullName,
                ]);
            }

            $updated++;
        }

        if (! empty($changes)) {
            $this->newLine();
            $this->table(
                ['ZKTeco ID', 'Antes', 'Después'],
                $changes
            );
        }

        $this->newLine();
        $this->info("Resultados:");
        $this->table(
            ['Métrica', 'Cantidad'],
            [
                ['Actualizados', $updated],
                ['Sin cambios', $unchanged],
                ['No encontrados', $notFound],
                ['Saltados (líneas inválidas)', $skipped],
            ]
        );

        if ($dryRun && $updated > 0) {
            $this->warn("Ejecuta sin --dry-run para aplicar los {$updated} cambios.");
        }

        return Command::SUCCESS;
    }
}
