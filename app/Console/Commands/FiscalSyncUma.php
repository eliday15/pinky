<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Trae el valor OFICIAL de la UMA diaria desde el API gratuito del Banco de
 * Indicadores de INEGI y actualiza la config fiscal (`fiscal_uma_daily`).
 *
 * La UMA la publica INEGI una vez al año (vigente el 1 de febrero). Este comando
 * es un "traer para revisar" MANUAL: no corre solo, no cambia números a media
 * semana. Corrélo cada febrero y valida contra Contpaq antes de re-nominar.
 *
 * Requiere un TOKEN GRATIS de INEGI (regístrate en
 * https://www.inegi.org.mx/app/api/, va en INEGI_API_TOKEN) y el ID del
 * indicador de la UMA diaria (búscalo en el "Constructor de Consultas" de INEGI
 * y ponlo en INEGI_UMA_INDICATOR). Ambos se pueden pasar por opción.
 *
 * Uso:
 *   php artisan fiscal:sync-uma --dry-run
 *   php artisan fiscal:sync-uma --token=XXXX --indicator=NNNN
 */
class FiscalSyncUma extends Command
{
    protected $signature = 'fiscal:sync-uma '
        .'{--token= : Token de INEGI (default: env INEGI_API_TOKEN)} '
        .'{--indicator= : ID del indicador UMA diaria (default: env INEGI_UMA_INDICATOR)} '
        .'{--dry-run : Solo muestra el valor traído, no lo guarda}';

    protected $description = 'Trae la UMA diaria oficial del API de INEGI y actualiza la config fiscal';

    public function handle(): int
    {
        $token = $this->option('token') ?: env('INEGI_API_TOKEN');
        $indicator = $this->option('indicator') ?: env('INEGI_UMA_INDICATOR');

        if (! $token) {
            $this->error('Falta el token de INEGI. Regístrate gratis en https://www.inegi.org.mx/app/api/ y ponlo en INEGI_API_TOKEN (o usa --token=).');

            return self::FAILURE;
        }
        if (! $indicator) {
            $this->error('Falta el ID del indicador de la UMA. Búscalo en el Constructor de Consultas de INEGI y ponlo en INEGI_UMA_INDICATOR (o usa --indicator=).');

            return self::FAILURE;
        }

        // Endpoint del Banco de Indicadores (JSON). DatoReciente=true → solo el
        // dato más reciente de la serie.
        $url = sprintf(
            'https://www.inegi.org.mx/app/api/indicadores/desarrolladores/jsonxml/INDICATOR/%s/es/00/true/BISE/2.0/%s',
            rawurlencode((string) $indicator),
            rawurlencode((string) $token),
        );

        $this->info("Consultando INEGI (indicador {$indicator})…");

        try {
            $response = Http::timeout(20)->get($url, ['type' => 'json']);
        } catch (\Throwable $e) {
            $this->error('No se pudo conectar con INEGI: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error("INEGI respondió {$response->status()}. Revisa el token y el ID del indicador.");

            return self::FAILURE;
        }

        $value = $this->extractLatestValue($response->json());
        if ($value === null) {
            $this->error('No se pudo interpretar la respuesta de INEGI (sin observaciones). Revisa que el indicador sea el de la UMA diaria.');
            $this->line('Respuesta cruda: '.substr((string) $response->body(), 0, 500));

            return self::FAILURE;
        }

        $current = (float) SystemSetting::get('fiscal_uma_daily', 0);
        $this->line(sprintf('UMA actual en Pinky: $%.2f', $current));
        $this->line(sprintf('UMA oficial de INEGI: $%.2f', $value));

        if ($this->option('dry-run')) {
            $this->warn('--dry-run: no se guardó nada.');

            return self::SUCCESS;
        }

        if (abs($current - $value) < 0.005) {
            $this->info('La UMA ya está al día. Nada que actualizar.');

            return self::SUCCESS;
        }

        SystemSetting::updateOrCreate(
            ['key' => 'fiscal_uma_daily'],
            ['value' => (string) $value, 'type' => 'float', 'group' => 'fiscal', 'label' => 'UMA diaria (INEGI)'],
        );

        $this->info(sprintf('UMA actualizada: $%.2f → $%.2f. Recuerda RECALCULAR los periodos y validar contra Contpaq.', $current, $value));

        return self::SUCCESS;
    }

    /**
     * Extrae el valor (OBS_VALUE) de la observación más reciente de la respuesta
     * del Banco de Indicadores. Tolera la variación de mayúsculas/estructura del
     * API (Series[].OBSERVATIONS[].OBS_VALUE / TIME_PERIOD).
     *
     * @param  mixed  $json  Respuesta decodificada de INEGI.
     */
    private function extractLatestValue(mixed $json): ?float
    {
        if (! is_array($json)) {
            return null;
        }

        $series = $json['Series'] ?? $json['series'] ?? null;
        $first = is_array($series) ? ($series[0] ?? null) : null;
        if (! is_array($first)) {
            return null;
        }

        $obs = $first['OBSERVATIONS'] ?? $first['Observations'] ?? $first['observations'] ?? null;
        if (! is_array($obs) || empty($obs)) {
            return null;
        }

        // Elige la observación con el TIME_PERIOD más reciente.
        $latest = null;
        $latestPeriod = null;
        foreach ($obs as $o) {
            if (! is_array($o)) {
                continue;
            }
            $period = (string) ($o['TIME_PERIOD'] ?? $o['time_period'] ?? '');
            $raw = $o['OBS_VALUE'] ?? $o['obs_value'] ?? null;
            if ($raw === null || $raw === '' || ! is_numeric($raw)) {
                continue;
            }
            if ($latestPeriod === null || strcmp($period, $latestPeriod) >= 0) {
                $latestPeriod = $period;
                $latest = (float) $raw;
            }
        }

        return $latest;
    }
}
