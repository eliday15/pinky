<?php

namespace App\Console\Commands;

use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Carga masiva de FECHAS DE NACIMIENTO desde un CSV, para el bono de cumpleaños
 * (1 día de sueldo la semana del cumpleaños). Cruza al empleado por
 * contpaqi_code o número de empleado. Idempotente y con --dry-run.
 *
 * CSV con cabeceras (flexibles, sin acentos ni mayúsculas):
 *   - identificador: `contpaqi_code` / `codigo` / `employee_number` / `numero`
 *   - fecha: `birth_date` / `fecha_nacimiento` / `nacimiento` / `cumpleanos`
 * Formatos de fecha aceptados: d/m/Y, d-m-Y, Y-m-d.
 *
 * Uso:
 *   php artisan employees:import-birthdays fechas.csv --dry-run
 *   php artisan employees:import-birthdays fechas.csv
 */
class ImportEmployeeBirthdays extends Command
{
    protected $signature = 'employees:import-birthdays {csv : Ruta al CSV} {--dry-run}';

    protected $description = 'Carga fechas de nacimiento por CSV (para el bono de cumpleaños)';

    /** @var array<int,string> */
    private array $idKeys = ['contpaqi_code', 'codigo', 'employee_number', 'numero'];

    /** @var array<int,string> */
    private array $dateKeys = ['birth_date', 'fecha_nacimiento', 'nacimiento', 'cumpleanos', 'cumpleaños'];

    public function handle(): int
    {
        $path = $this->argument('csv');
        if (! is_file($path)) {
            $this->error("No existe el archivo: {$path}");

            return self::FAILURE;
        }

        $rows = $this->readCsv($path);
        if ($rows === null) {
            return self::FAILURE;
        }
        if (empty($rows)) {
            $this->error('El CSV no tiene filas de datos.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $updated = 0;
        $unchanged = 0;
        $notFound = [];
        $badDate = [];

        foreach ($rows as $i => $row) {
            $id = $this->firstValue($row, $this->idKeys);
            $rawDate = $this->firstValue($row, $this->dateKeys);
            $line = $i + 2; // +1 por cabecera, +1 por índice base 0

            if ($id === null || $id === '') {
                $notFound[] = "L{$line}: sin identificador";

                continue;
            }

            $employee = Employee::where('contpaqi_code', $id)
                ->orWhere('employee_number', $id)
                ->first();

            if (! $employee) {
                $notFound[] = "L{$line}: {$id}";

                continue;
            }

            $date = $this->parseDate($rawDate);
            if ($date === null) {
                $badDate[] = "L{$line}: {$id} → '{$rawDate}'";

                continue;
            }

            $newValue = $date->format('Y-m-d');
            $current = $employee->birth_date?->format('Y-m-d');
            if ($current === $newValue) {
                $unchanged++;

                continue;
            }

            if (! $dry) {
                $employee->birth_date = $newValue;
                $employee->save();
            }
            $updated++;
            $this->line(sprintf('  %s  %s → %s', $id, $current ?? '(vacío)', $newValue));
        }

        $this->newLine();
        $this->info(($dry ? '[DRY-RUN] ' : '')."Actualizados: {$updated} · Sin cambio: {$unchanged} · No encontrados: ".count($notFound)." · Fecha inválida: ".count($badDate));

        if (! empty($notFound)) {
            $this->warn('No encontrados: '.implode(', ', array_slice($notFound, 0, 30)).(count($notFound) > 30 ? ' …' : ''));
        }
        if (! empty($badDate)) {
            $this->warn('Fechas inválidas: '.implode(', ', array_slice($badDate, 0, 30)).(count($badDate) > 30 ? ' …' : ''));
        }
        if ($dry) {
            $this->warn('--dry-run: no se guardó nada.');
        }

        return self::SUCCESS;
    }

    /**
     * Lee el CSV a un arreglo de filas asociativas, normalizando las cabeceras
     * (minúsculas, sin espacios). Devuelve null si no pudo abrirlo.
     *
     * @return array<int,array<string,string>>|null
     */
    private function readCsv(string $path): ?array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            $this->error('No se pudo abrir el CSV.');

            return null;
        }

        $headers = null;
        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            if ($data === [null] || $data === false) {
                continue;
            }
            if ($headers === null) {
                $headers = array_map(fn ($h) => strtolower(trim((string) $h)), $data);

                continue;
            }
            $row = [];
            foreach ($headers as $col => $key) {
                $row[$key] = isset($data[$col]) ? trim((string) $data[$col]) : '';
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Primer valor no vacío de la fila entre las claves candidatas.
     *
     * @param  array<string,string>  $row
     * @param  array<int,string>  $keys
     */
    private function firstValue(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && trim($row[$key]) !== '') {
                return trim($row[$key]);
            }
        }

        return null;
    }

    /**
     * Parsea una fecha en d/m/Y, d-m-Y o Y-m-d. Devuelve null si no es válida.
     */
    private function parseDate(?string $raw): ?Carbon
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $raw = str_replace('.', '/', trim($raw));
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'Y/m/d'] as $fmt) {
            try {
                $d = Carbon::createFromFormat($fmt, $raw);
                if ($d !== false && $d->year >= 1900 && $d->year <= 2100) {
                    return $d;
                }
            } catch (\Throwable) {
                // siguiente formato
            }
        }

        return null;
    }
}
