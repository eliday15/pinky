<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\PayrollPeriod;

/**
 * Invalidación de nómina precalculada (DECISIONES_NEGOCIO_2026-06-04.md §7,
 * "Auto + marcar según estado").
 *
 * Cuando cambian datos que afectan el pago de un rango de fechas (aprobar/
 * crear/eliminar incidencias, aprobar autorizaciones, editar checadas):
 * - Periodos en draft/calculating: se recalcula automáticamente la entrada
 *   del empleado afectado, de inmediato.
 * - Periodos en review/approved: se marcan requires_recalculation para que
 *   un admin recalcule explícitamente (el periodo vuelve a review).
 * - Periodos paid: inmutables — nunca se tocan; el cambio queda en el audit
 *   log del modelo que lo originó.
 *
 * El cableado vive en los CONTROLADORES (no en observers de modelo) a
 * propósito: las escrituras internas de servicios (p.ej. la generación de
 * FRT durante el propio cálculo) no deben disparar invalidaciones
 * reentrantes.
 */
class PayrollInvalidationService
{
    public function __construct(private PayrollCalculatorService $calculator)
    {
    }

    /**
     * Invalida los periodos que solapan el rango de fechas para un empleado.
     */
    public function invalidate(int $employeeId, string $startDate, ?string $endDate = null): void
    {
        $endDate = $endDate ?? $startDate;

        $periods = PayrollPeriod::where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate)
            ->whereIn('status', ['draft', 'calculating', 'review', 'approved'])
            ->get();

        if ($periods->isEmpty()) {
            return;
        }

        $employee = null;

        foreach ($periods as $period) {
            if (in_array($period->status, ['draft', 'calculating'], true)) {
                $employee = $employee ?? Employee::find($employeeId);

                if ($employee && $employee->status === 'active') {
                    $this->calculator->calculateEmployeePayroll($period, $employee);
                }

                continue;
            }

            // review/approved: marcar, no recalcular en silencio.
            if (! $period->requires_recalculation) {
                $period->update([
                    'requires_recalculation' => true,
                    'recalculation_flagged_at' => now(),
                ]);
            }
        }
    }
}
