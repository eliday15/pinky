<?php

namespace App\Console\Commands;

use App\Models\Employee;
use Illuminate\Console\Command;

/**
 * Carga masiva de los datos de empleado que exige el CFDI de nómina (CURP,
 * RFC, CLABE, banco, código postal) desde un CSV, cruzando por contpaqi_code
 * o número de empleado. Solo escribe columnas presentes y no vacías (no pisa
 * lo capturado). Idempotente, con --dry-run.
 *
 * CSV con cabeceras flexibles:
 *   - id: `contpaqi_code` / `codigo` / `employee_number` / `numero`
 *   - datos: `curp`, `rfc`, `clabe`, `banco`/`bank_code`, `cp`/`codigo_postal`
 *
 * Uso: php artisan employees:import-cfdi-data datos.csv [--dry-run]
 */
class ImportEmployeeCfdiData extends Command
{
    protected $signature = 'employees:import-cfdi-data {csv : Ruta al CSV} {--dry-run}';

    protected $description = 'Carga CURP/RFC/CLABE/banco/CP por CSV (datos del CFDI de nómina)';

    /** @var array<string, array<int, string>> destino => cabeceras aceptadas */
    private array $fieldMap = [
        'curp' => ['curp'],
        'rfc' => ['rfc'],
        'clabe' => ['clabe'],
        'bank_code' => ['banco', 'bank_code', 'bank'],
        'address_zip' => ['cp', 'codigo_postal', 'zip', 'address_zip'],
    ];

    public function handle(): int
    {
        $path = $this->argument('csv');
        if (! is_file($path)) {
            $this->error("No existe el archivo: {$path}");

            return self::FAILURE;
        }

        $handle = fopen($path, 'r');
        $headers = null;
        $dry = (bool) $this->option('dry-run');
        $updated = 0;
        $notFound = [];
        $unchanged = 0;

        while (($data = fgetcsv($handle)) !== false) {
            if ($headers === null) {
                $headers = array_map(fn ($h) => strtolower(trim((string) $h)), $data);

                continue;
            }
            $row = [];
            foreach ($headers as $i => $key) {
                $row[$key] = isset($data[$i]) ? trim((string) $data[$i]) : '';
            }

            $id = $row['contpaqi_code'] ?? $row['codigo'] ?? $row['employee_number'] ?? $row['numero'] ?? '';
            if ($id === '') {
                continue;
            }

            $employee = Employee::where('contpaqi_code', $id)->orWhere('employee_number', $id)->first();
            if (! $employee) {
                $notFound[] = $id;

                continue;
            }

            $changes = [];
            foreach ($this->fieldMap as $column => $aliases) {
                foreach ($aliases as $alias) {
                    $value = $row[$alias] ?? '';
                    if ($value !== '') {
                        $normalized = in_array($column, ['curp', 'rfc'], true) ? mb_strtoupper($value) : $value;
                        if ((string) $employee->{$column} !== $normalized) {
                            $changes[$column] = $normalized;
                        }
                        break;
                    }
                }
            }

            if (empty($changes)) {
                $unchanged++;

                continue;
            }

            if (! $dry) {
                $employee->fill($changes)->save();
            }
            $updated++;
            $this->line("  {$id}: ".implode(', ', array_keys($changes)));
        }
        fclose($handle);

        $this->info(($dry ? '[DRY-RUN] ' : '')."Actualizados: {$updated} · Sin cambio: {$unchanged} · No encontrados: ".count($notFound));
        if (! empty($notFound)) {
            $this->warn('No encontrados: '.implode(', ', array_slice($notFound, 0, 30)).(count($notFound) > 30 ? ' …' : ''));
        }
        if ($dry) {
            $this->warn('--dry-run: no se guardó nada.');
        }

        return self::SUCCESS;
    }
}
