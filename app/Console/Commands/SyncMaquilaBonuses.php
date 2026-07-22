<?php

namespace App\Console\Commands;

use App\Services\MaquilaBonusAuthorizationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Genera las autorizaciones mensuales de los bonos de maquila desde basemaquila.
 *
 * Por defecto procesa el mes anterior (ya cerrado). Es idempotente: reejecutar
 * el mismo mes actualiza las autorizaciones aún pendientes y respeta las ya
 * aprobadas/pagadas. Programado el día 1; también hay botón manual en la UI.
 */
class SyncMaquilaBonuses extends Command
{
    protected $signature = 'bonuses:sync-maquila
        {--month= : Mes a generar (YYYY-MM); por defecto el mes anterior}
        {--dry-run : Solo muestra qué se generaría, sin escribir}';

    protected $description = 'Genera/actualiza las autorizaciones mensuales de los bonos de maquila (cantidad desde basemaquila)';

    public function handle(MaquilaBonusAuthorizationService $service): int
    {
        $monthOption = $this->option('month');

        if ($monthOption !== null && ! preg_match('/^\d{4}-\d{2}$/', $monthOption)) {
            $this->error('El mes debe tener formato YYYY-MM.');

            return self::FAILURE;
        }

        $month = $monthOption
            ? Carbon::createFromFormat('Y-m-d', $monthOption . '-01')->startOfMonth()
            : Carbon::today()->startOfMonth()->subMonthNoOverflow();

        $dryRun = (bool) $this->option('dry-run');

        try {
            $summary = $service->generateForMonth(
                (int) $month->year,
                (int) $month->month,
                requestedBy: null,
                dryRun: $dryRun,
            );
        } catch (\Throwable $e) {
            $this->error("No se pudieron generar los bonos de {$month->format('Y-m')}: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Bonos de maquila {$month->format('Y-m')}" . ($dryRun ? ' (dry-run)' : '') . ':');

        $rows = array_map(fn (array $r) => [
            $r['name'],
            number_format($r['quantity']),
            $r['assigned'],
            $r['created'],
            $r['updated'],
            $r['locked'],
            $r['note'] ?? '',
        ], $summary);

        $this->table(['Concepto', 'Cantidad', 'Asignados', 'Creadas', 'Actualizadas', 'Bloqueadas', 'Nota'], $rows);

        return self::SUCCESS;
    }
}
