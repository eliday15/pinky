<?php

namespace App\Console\Commands;

use App\Models\PayrollPeriod;
use App\Services\PayrollShadowRecalcService;
use Illuminate\Console\Command;

/**
 * Reporte de diferencias de periodos YA PAGADOS (DECISIONES §9): recalcula
 * cada periodo pagado en sombra con las reglas corregidas, sin modificarlo,
 * y muestra qué habría cambiado por empleado y concepto para decidir ajustes.
 */
class PayrollShadowRecalc extends Command
{
    protected $signature = 'payroll:shadow-recalc
        {--period= : ID de un periodo pagado específico (default: todos los pagados)}
        {--csv= : Ruta donde escribir el detalle de diferencias en CSV}';

    protected $description = 'Recalcula en sombra los periodos de nómina pagados (sin modificarlos) y reporta las diferencias contra lo realmente pagado.';

    public function handle(PayrollShadowRecalcService $shadow): int
    {
        $query = PayrollPeriod::where('status', 'paid')->orderBy('start_date');

        if ($periodId = $this->option('period')) {
            $query->where('id', $periodId);
        }

        $periods = $query->get();

        if ($periods->isEmpty()) {
            $this->warn($this->option('period')
                ? 'No existe un periodo PAGADO con ese ID.'
                : 'No hay periodos pagados que recalcular.');

            return self::SUCCESS;
        }

        $csvRows = [];
        $grandOld = 0.0;
        $grandNew = 0.0;

        foreach ($periods as $period) {
            $diff = $shadow->diffPeriod($period);

            $this->newLine();
            $this->info("Periodo #{$diff['period']['id']} — {$diff['period']['name']} ({$diff['period']['start_date']} a {$diff['period']['end_date']}, {$diff['period']['type']})");
            $this->line("  Sin cambios: {$diff['unchanged_count']} empleado(s).");
            if ($diff['skipped_missing_employee'] > 0) {
                $this->warn("  Omitidos {$diff['skipped_missing_employee']} entry(s) sin empleado vinculado.");
            }

            if (empty($diff['rows'])) {
                $this->line('  ✓ La sombra coincide con lo pagado.');
            } else {
                $this->table(
                    ['No. Empleado', 'Empleado', 'Neto pagado', 'Neto sombra', 'Diferencia', 'Conceptos que cambian'],
                    array_map(fn (array $row) => [
                        $row['employee_number'] ?? '-',
                        $row['full_name'],
                        number_format($row['old_net'], 2),
                        number_format($row['new_net'], 2),
                        number_format($row['net_delta'], 2),
                        implode(', ', array_keys($row['fields'])),
                    ], $diff['rows']),
                );
            }

            $t = $diff['totals'];
            $this->line(sprintf(
                '  Totales: pagado %s → sombra %s (diferencia %s)',
                number_format($t['old_net'], 2),
                number_format($t['new_net'], 2),
                number_format($t['delta_net'], 2),
            ));

            $grandOld += $t['old_net'];
            $grandNew += $t['new_net'];

            foreach ($diff['rows'] as $row) {
                foreach ($row['fields'] as $field => $change) {
                    $csvRows[] = [
                        $diff['period']['id'],
                        $diff['period']['name'],
                        $row['employee_number'] ?? '-',
                        $row['full_name'],
                        $field,
                        number_format($change['old'], 2, '.', ''),
                        number_format($change['new'], 2, '.', ''),
                        number_format($change['delta'], 2, '.', ''),
                    ];
                }
            }
        }

        if ($periods->count() > 1) {
            $this->newLine();
            $this->info(sprintf(
                'TOTAL HISTÓRICO: pagado %s → sombra %s (diferencia %s)',
                number_format($grandOld, 2),
                number_format($grandNew, 2),
                number_format($grandNew - $grandOld, 2),
            ));
        }

        if ($csvPath = $this->option('csv')) {
            $this->writeCsv($csvPath, $csvRows);
            $this->info('Detalle CSV escrito en: '.$csvPath.' ('.count($csvRows).' fila(s))');
        }

        return self::SUCCESS;
    }

    /**
     * Detalle por (periodo, empleado, concepto) en CSV UTF-8 con BOM,
     * igual que los exports del módulo de reportes.
     *
     * @param  list<list<string|int>>  $rows
     */
    private function writeCsv(string $path, array $rows): void
    {
        $handle = fopen($path, 'w');

        if ($handle === false) {
            throw new \RuntimeException("No se pudo escribir el CSV en {$path}.");
        }

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['Periodo ID', 'Periodo', 'No. Empleado', 'Empleado', 'Concepto', 'Pagado', 'Sombra', 'Diferencia']);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);
    }
}
