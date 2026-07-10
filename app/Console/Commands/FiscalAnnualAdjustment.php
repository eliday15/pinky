<?php

namespace App\Console\Commands;

use App\Models\EmployeeAnnualTotal;
use App\Models\FiscalIsrBracket;
use Illuminate\Console\Command;

/**
 * REPORTE del ajuste anual de ISR (Art. 97 LISR): para cada empleado con
 * acumulados del año (y gravado anual < $400,000), compara el ISR anual por
 * tarifa ANUAL contra lo retenido en el año; la diferencia se retiene o
 * devuelve en la última nómina de diciembre (aplicación manual vía concepto,
 * revisada con el contador).
 *
 * Requiere la tarifa ANUAL sembrada en fiscal_isr_brackets con
 * period_type='annual' (se publica en el DOF; capturarla en diciembre).
 */
class FiscalAnnualAdjustment extends Command
{
    protected $signature = 'fiscal:annual-adjustment {year? : Año (default actual)} {--limit=400000 : Tope de ingresos para aplicar el ajuste}';

    protected $description = 'Reporte del ajuste anual de ISR (Art. 97) desde los acumulados';

    public function handle(): int
    {
        $year = (int) ($this->argument('year') ?? now()->year);
        $limit = (float) $this->option('limit');

        $hasAnnual = FiscalIsrBracket::where('period_type', 'annual')->exists();
        if (! $hasAnnual) {
            $this->error('No hay tarifa ANUAL sembrada (fiscal_isr_brackets period_type=annual). Captura la tarifa anual del DOF antes de correr el ajuste (ver docs/FISCAL_ANNUAL_CHECKLIST.md).');

            return self::FAILURE;
        }

        $totals = EmployeeAnnualTotal::with('employee')->where('year', $year)->get();
        if ($totals->isEmpty()) {
            $this->warn("Sin acumulados {$year}. Corre fiscal:rebuild-annual-totals {$year} primero.");

            return self::FAILURE;
        }

        $rows = [];
        foreach ($totals as $t) {
            $gravado = $t->totalTaxable();
            $retenido = $t->totalIsrWithheld();
            if ($gravado <= 0 || $gravado >= $limit) {
                continue;
            }

            $bracket = FiscalIsrBracket::where('period_type', 'annual')
                ->where('lower_limit', '<=', $gravado)
                ->orderByDesc('lower_limit')
                ->first();
            if (! $bracket) {
                continue;
            }
            $isrAnual = round((float) $bracket->fixed_fee
                + ((float) $bracket->percent_over_excess / 100) * ($gravado - (float) $bracket->lower_limit), 2);
            $diff = round($isrAnual - $retenido, 2);

            $rows[] = [
                $t->employee?->contpaqi_code ?? ('#'.$t->employee_id),
                mb_substr($t->employee?->full_name ?? '', 0, 30),
                number_format($gravado, 2),
                number_format($retenido, 2),
                number_format($isrAnual, 2),
                ($diff >= 0 ? 'retener ' : 'devolver ').number_format(abs($diff), 2),
            ];
        }

        $this->table(['Código', 'Empleado', 'Gravado anual', 'ISR retenido', 'ISR anual', 'Ajuste'], $rows);
        $this->info(count($rows).' empleados con ajuste calculado (aplicación manual en la última nómina de diciembre, validada con el contador).');

        return self::SUCCESS;
    }
}
