<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\Fiscal\SdiCalculatorService;
use Illuminate\Console\Command;

/**
 * Recalcula el SDI/SBC de los empleados con la fórmula propia de Pinky
 * (SdiCalculatorService, factor de integración Art. 27 LSS).
 *
 * Modos:
 *   --compare  Solo compara el SDI calculado vs el almacenado (importado de
 *              Contpaq) y reporta diferencias — la validación de aceptación.
 *   --apply    Escribe sdi/sbc calculados en los empleados.
 *   (default)  Igual que --compare.
 *
 * Alcance: empleados activos con salario diario > 0. Los que no tienen
 * hire_date usan el primer año de la tabla (12 días).
 */
class FiscalRecalcSdi extends Command
{
    protected $signature = 'fiscal:recalc-sdi {--apply : Escribe los valores calculados} {--compare : Solo compara vs lo almacenado} {--tol=0.05 : Tolerancia en pesos para contar como igual}';

    protected $description = 'Recalcula (o compara) el SDI/SBC con la fórmula propia del factor de integración';

    public function handle(SdiCalculatorService $calc): int
    {
        $apply = (bool) $this->option('apply');
        $tol = (float) $this->option('tol');

        $employees = Employee::where('status', 'active')->get()
            ->filter(fn (Employee $e) => (float) $e->daily_salary_computed > 0);

        $match = 0;
        $diff = [];
        $noStored = 0;
        $applied = 0;

        foreach ($employees as $employee) {
            $sdi = $calc->sdi($employee);
            $sbc = $calc->sbc($employee);
            $stored = (float) ($employee->sdi ?? 0);

            if ($stored <= 0) {
                $noStored++;
            } elseif (abs($sdi - $stored) <= $tol) {
                $match++;
            } else {
                $diff[] = sprintf(
                    '  %-12s %-30s calc=%.2f vs contpaq=%.2f (Δ%.2f, factor=%.4f, sal=%.2f)',
                    $employee->contpaqi_code ?? ('#'.$employee->id),
                    mb_substr($employee->full_name ?? '', 0, 30),
                    $sdi,
                    $stored,
                    $sdi - $stored,
                    $calc->integrationFactor($employee),
                    (float) $employee->daily_salary_computed,
                );
            }

            if ($apply) {
                $employee->sdi = $sdi;
                $employee->sbc = $sbc;
                $employee->save();
                $applied++;
            }
        }

        $this->info(sprintf(
            'Empleados: %d · Coinciden con lo almacenado (±$%.2f): %d · Difieren: %d · Sin SDI almacenado: %d',
            $employees->count(),
            $tol,
            $match,
            count($diff),
            $noStored,
        ));

        if (! empty($diff)) {
            $this->warn('Diferencias (calc vs Contpaq):');
            foreach (array_slice($diff, 0, 60) as $line) {
                $this->line($line);
            }
            if (count($diff) > 60) {
                $this->line('  … y '.(count($diff) - 60).' más');
            }
        }

        if ($apply) {
            $this->info("SDI/SBC escritos en {$applied} empleados.");
        } else {
            $this->warn('Modo comparación: no se escribió nada (usa --apply para aplicar).');
        }

        return self::SUCCESS;
    }
}
