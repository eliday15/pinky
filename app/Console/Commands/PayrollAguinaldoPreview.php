<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\SystemSetting;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Preview del AGUINALDO anual por empleado (lo que pagará automáticamente el
 * periodo que contenga fiscal_aguinaldo_payment_date): días de aguinaldo × SD
 * × proporción de días del año trabajados. Solo formalizados (transferencia).
 */
class PayrollAguinaldoPreview extends Command
{
    protected $signature = 'payroll:aguinaldo-preview {year? : Año (default actual)}';

    protected $description = 'Preview del aguinaldo proporcional por empleado (formalizados)';

    public function handle(): int
    {
        $year = (int) ($this->argument('year') ?? now()->year);
        $aguinaldoDays = (float) SystemSetting::get('fiscal_aguinaldo_days', 15);
        $payDate = (string) SystemSetting::get('fiscal_aguinaldo_payment_date', '');
        $endOfYear = Carbon::create($year, 12, 31);
        $yearStart = Carbon::create($year, 1, 1);

        $this->info("Aguinaldo {$year} — {$aguinaldoDays} días; fecha de pago configurada: ".($payDate ?: '(NINGUNA — configúrala en Fiscal para que se pague)'));

        $rows = [];
        $total = 0.0;
        $employees = Employee::where('status', 'active')->orderBy('full_name')->get()
            ->filter(fn (Employee $e) => ! $e->paysBaseInCash() && (float) $e->daily_salary_computed > 0);

        foreach ($employees as $e) {
            $workedFrom = $e->hire_date && $e->hire_date->gt($yearStart) ? $e->hire_date->copy() : $yearStart;
            $daysWorked = min(365, max(0, (int) $workedFrom->diffInDays($endOfYear) + 1));
            $amount = round($aguinaldoDays * (float) $e->daily_salary_computed * ($daysWorked / 365), 2);
            $total += $amount;
            $rows[] = [
                $e->contpaqi_code ?? ('#'.$e->id),
                mb_substr($e->full_name, 0, 32),
                number_format((float) $e->daily_salary_computed, 2),
                $daysWorked,
                number_format($amount, 2),
            ];
        }

        $this->table(['Código', 'Empleado', 'SD', 'Días año', 'Aguinaldo'], $rows);
        $this->info(sprintf('Total: %d empleados → $%s', count($rows), number_format($total, 2)));

        return self::SUCCESS;
    }
}
