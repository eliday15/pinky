<?php

namespace App\Console\Commands;

use App\Models\Employee;
use Illuminate\Console\Command;

/**
 * Importa los datos fiscales por empleado (SDI, SBC, crédito Infonavit) desde un
 * JSON parseado de la Lista de Raya de Contpaq, cruzando por contpaqi_code.
 * Contpaq ya calcula el SDI/SBC (factor de integración por antigüedad) y el
 * crédito Infonavit; aquí solo se cargan a Pinky.
 *
 * JSON esperado: [{"code","sdi","sbc","dias","infonavit_cf","infonavit_fd"}, ...]
 */
class FiscalImportContpaq extends Command
{
    protected $signature = 'fiscal:import-contpaq {json : Ruta al JSON parseado del PDF} {--dry-run}';

    protected $description = 'Importa SDI, SBC y crédito Infonavit por empleado desde el PDF de Contpaq';

    public function handle(): int
    {
        $path = $this->argument('json');
        if (! is_file($path)) {
            $this->error("No existe el archivo: {$path}");

            return self::FAILURE;
        }

        $rows = json_decode(file_get_contents($path), true);
        if (! is_array($rows)) {
            $this->error('JSON inválido.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $updated = 0;
        $skipped = [];

        foreach ($rows as $r) {
            $code = $r['code'] ?? null;
            $sdi = (float) ($r['sdi'] ?? 0);
            if (! $code || $sdi <= 0) {
                continue;
            }

            $employee = Employee::where('contpaqi_code', $code)->first();
            if (! $employee) {
                $skipped[] = $code;

                continue;
            }

            $days = (float) ($r['dias'] ?? 7) ?: 7;
            $cf = (float) ($r['infonavit_cf'] ?? 0);
            $fd = (float) ($r['infonavit_fd'] ?? 0);

            $type = 'none';
            $value = null;
            if ($cf > 0) {
                $type = 'cf';                       // cuota fija semanal normalizada a semana completa
                $value = round($cf * 7 / $days, 4);
            } elseif ($fd > 0 && $sdi > 0) {
                $type = 'fd';                       // factor de descuento = monto / (SDI × días)
                $value = round($fd / ($sdi * $days), 6);
            }

            if (! $dry) {
                $employee->update([
                    'sdi' => round($sdi, 2),
                    'sbc' => round((float) ($r['sbc'] ?? $sdi), 2),
                    'infonavit_credit_type' => $type,
                    'infonavit_credit_value' => $value,
                ]);
            }
            $updated++;
        }

        $this->info(($dry ? '[dry-run] ' : '')."Empleados actualizados: {$updated}");
        if ($skipped) {
            $this->warn('Sin match por contpaqi_code ('.count($skipped).'): '.implode(', ', array_slice($skipped, 0, 10)).(count($skipped) > 10 ? '…' : ''));
        }

        return self::SUCCESS;
    }
}
