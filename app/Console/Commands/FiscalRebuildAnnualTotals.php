<?php

namespace App\Console\Commands;

use App\Services\Fiscal\EmployeeAnnualTotalService;
use Illuminate\Console\Command;

/**
 * Reconstruye los acumulados anuales por empleado (gravado, ISR retenido,
 * subsidio, días) desde los periodos semanales aprobados/pagados del año.
 * Idempotente — puede correrse cuantas veces sea necesario.
 */
class FiscalRebuildAnnualTotals extends Command
{
    protected $signature = 'fiscal:rebuild-annual-totals {year? : Año a reconstruir (default: actual)}';

    protected $description = 'Reconstruye los acumulados anuales de ISR/gravado por empleado';

    public function handle(EmployeeAnnualTotalService $service): int
    {
        $year = (int) ($this->argument('year') ?? now()->year);
        $count = $service->rebuildYear($year);
        $this->info("Acumulados {$year} reconstruidos para {$count} empleados.");

        return self::SUCCESS;
    }
}
