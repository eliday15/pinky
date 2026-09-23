<?php

namespace App\Console\Commands;

use App\Services\PayrollStaleAbsenceReconciler;
use App\Support\AuditContext;
use Illuminate\Console\Command;

/**
 * Detecta y corrige deducciones por falta que quedaron obsoletas porque la
 * checada se corrigió DESPUÉS de calcular (y pagar) la nómina.
 *
 * Sin --apply solo reporta. Ver PayrollStaleAbsenceReconciler para el criterio.
 */
class ReconcileStaleAbsenceDeductions extends Command
{
    protected $signature = 'payroll:reconcile-stale
        {--apply : Escribe las correcciones (sin esto solo reporta)}
        {--period= : Limita a un payroll_period_id}
        {--employee= : Limita a un employee_id}
        {--actor=CLI : Nombre que queda en el audit log}';

    protected $description = 'Reporta y corrige deducciones de falta sobre días que la asistencia ya reporta como trabajados.';

    public function handle(PayrollStaleAbsenceReconciler $reconciler): int
    {
        $periodId = $this->option('period') ? (int) $this->option('period') : null;
        $employeeId = $this->option('employee') ? (int) $this->option('employee') : null;

        $stale = $reconciler->staleDeductions($periodId, $employeeId);

        if ($stale->isEmpty()) {
            $this->info('Sin deducciones obsoletas: la nómina coincide con la asistencia.');

            return self::SUCCESS;
        }

        $total = round($stale->sum('estimated'), 2);
        $this->warn($stale->count() . ' recibo(s) con falta obsoleta — estimado ' . number_format($total, 2) . ' MXN');

        $this->table(
            ['Recibo', 'Empleado', 'Periodo', 'Fechas', 'Estimado'],
            $stale->map(fn ($row) => [
                $row['entry']->id,
                $row['entry']->employee?->full_name ?? '—',
                $row['entry']->payrollPeriod?->name ?? '—',
                implode(', ', $row['dates']),
                number_format($row['estimated'], 2),
            ])->all()
        );

        if (! $this->option('apply')) {
            $this->line('Corrida de solo lectura. Vuelve a correrlo con --apply para corregir.');

            return self::SUCCESS;
        }

        return AuditContext::actingAs(
            (string) $this->option('actor'),
            AuditContext::CONTEXT_CONSOLE,
            'Reconciliación de faltas obsoletas',
            function () use ($reconciler, $stale) {
                $result = $reconciler->apply($stale);

                $this->newLine();
                $this->table(
                    ['Empleado', 'Periodo', 'Neto antes', 'Neto después', 'Δ'],
                    collect($result['deltas'])->map(fn ($d) => [
                        $d['employee'],
                        $d['period'],
                        number_format($d['before']['net_pay'], 2),
                        number_format($d['after']['net_pay'], 2),
                        number_format($d['net_delta'], 2),
                    ])->all()
                );

                $this->info("Recibos recalculados: {$result['recalculated']}");

                if ($result['skipped_cfdi'] > 0) {
                    $this->warn("Omitidos por CFDI timbrado: {$result['skipped_cfdi']}");
                }

                return self::SUCCESS;
            }
        );
    }
}
