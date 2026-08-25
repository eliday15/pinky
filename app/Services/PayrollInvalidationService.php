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

        // El empleado solo pertenece a UN alcance de nómina: si su departamento
        // lleva nómina propia (Taller), únicamente su periodo de departamento le
        // corresponde; si no, únicamente la nómina general (department_id NULL).
        // Sin este filtro, un cambio de un empleado de Taller recalcularía/
        // marcaría la nómina general (y le crearía una entrada) — doble conteo.
        $employee = Employee::with('department')->find($employeeId);

        if (! $employee) {
            return;
        }

        // Un periodo UNIFICADO (semana + extras del mes) también se ve afectado
        // por un cambio dentro de su rango de EXTRAS, aunque caiga fuera de su
        // semana: si no, el cambio del mes pasaría inadvertido.
        $periods = PayrollPeriod::where(function ($q) use ($startDate, $endDate) {
            $q->where(fn ($q2) => $q2->where('start_date', '<=', $endDate)
                ->where('end_date', '>=', $startDate))
                ->orWhere(fn ($q2) => $q2->whereNotNull('extras_start_date')
                    ->where('extras_start_date', '<=', $endDate)
                    ->where('extras_end_date', '>=', $startDate));
        })
            ->whereIn('status', ['draft', 'calculating', 'review', 'approved'])
            ->when(
                $employee->department?->has_separate_payroll,
                fn ($q) => $q->where('department_id', $employee->department_id),
                fn ($q) => $q->whereNull('department_id')
            )
            ->get();

        if ($periods->isEmpty()) {
            return;
        }

        foreach ($periods as $period) {
            if (in_array($period->status, ['draft', 'calculating'], true)) {
                if ($employee->status === 'active') {
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
