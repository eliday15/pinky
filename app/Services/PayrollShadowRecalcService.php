<?php

namespace App\Services;

use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Recálculo EN SOMBRA de periodos de nómina ya pagados (DECISIONES §9).
 *
 * Un periodo pagado es inmutable, pero el negocio necesita cuantificar qué
 * habría pagado con las reglas corregidas para decidir ajustes. Este servicio
 * ejecuta el MISMO camino de cálculo de producción (PayrollCalculatorService)
 * dentro de una transacción que SIEMPRE se revierte: nada se persiste, ni el
 * payroll_entry recalculado ni las incidencias FRT que la regla mensual
 * pudiera generar de paso.
 */
class PayrollShadowRecalcService
{
    /**
     * Conceptos monetarios comparados entre lo pagado y la sombra.
     *
     * @var list<string>
     */
    public const MONEY_FIELDS = [
        'regular_pay',
        'overtime_pay',
        'velada_pay',
        'holiday_pay',
        'weekend_pay',
        'other_compensation_pay',
        'vacation_pay',
        'vacation_premium_pay',
        'sick_leave_pay',
        'punctuality_bonus',
        'dinner_allowance',
        'night_shift_bonus',
        'weekly_bonus',
        'monthly_bonus',
        'bonuses',
        'deductions',
        'gross_pay',
        'net_pay',
    ];

    /**
     * Diferencias por debajo de medio centavo son ruido de redondeo.
     */
    private const EPSILON = 0.005;

    public function __construct(private PayrollCalculatorService $calculator) {}

    /**
     * Recalcula en sombra un periodo PAGADO y devuelve las diferencias
     * contra lo persistido, sin modificar nada.
     *
     * @return array{
     *     period: array{id: int, name: string, type: string, start_date: string, end_date: string},
     *     rows: list<array<string, mixed>>,
     *     unchanged_count: int,
     *     skipped_missing_employee: int,
     *     totals: array{old_net: float, new_net: float, delta_net: float}
     * }
     *
     * @throws \InvalidArgumentException si el periodo no está pagado
     */
    public function diffPeriod(PayrollPeriod $period): array
    {
        if ($period->status !== 'paid') {
            throw new \InvalidArgumentException(
                "El recálculo en sombra es solo para periodos pagados; el periodo {$period->id} está '{$period->status}'."
            );
        }

        $rows = [];
        $unchanged = 0;
        $skipped = 0;
        $totals = ['old_net' => 0.0, 'new_net' => 0.0, 'delta_net' => 0.0];

        DB::beginTransaction();

        try {
            // El roster comparable es el que se pagó: las entries del periodo.
            $entries = PayrollEntry::where('payroll_period_id', $period->id)
                ->with(['employee.compensationTypes' => fn ($q) => $q->wherePivot('is_active', true)])
                ->orderBy('employee_id')
                ->get();

            foreach ($entries as $entry) {
                $employee = $entry->employee;

                if (! $employee) {
                    $skipped++;

                    continue;
                }

                // Snapshot ANTES: calculateEmployeePayroll hace updateOrCreate
                // sobre esta misma fila (dentro de la transacción revertida).
                $paid = $this->moneyValues($entry);

                $shadowEntry = $this->calculator->calculateEmployeePayroll($period, $employee);
                $shadow = $this->moneyValues($shadowEntry);

                $changedFields = [];
                foreach (self::MONEY_FIELDS as $field) {
                    $delta = $shadow[$field] - $paid[$field];
                    if (abs($delta) >= self::EPSILON) {
                        $changedFields[$field] = [
                            'old' => $paid[$field],
                            'new' => $shadow[$field],
                            'delta' => round($delta, 2),
                        ];
                    }
                }

                $totals['old_net'] += $paid['net_pay'];
                $totals['new_net'] += $shadow['net_pay'];

                if ($changedFields === []) {
                    $unchanged++;

                    continue;
                }

                $rows[] = [
                    'employee_id' => $employee->id,
                    'employee_number' => $employee->employee_number,
                    'full_name' => $employee->full_name,
                    'old_net' => $paid['net_pay'],
                    'new_net' => $shadow['net_pay'],
                    'net_delta' => round($shadow['net_pay'] - $paid['net_pay'], 2),
                    'fields' => $changedFields,
                ];
            }
        } finally {
            // SIEMPRE revertir: la sombra jamás persiste.
            DB::rollBack();
        }

        $totals['old_net'] = round($totals['old_net'], 2);
        $totals['new_net'] = round($totals['new_net'], 2);
        $totals['delta_net'] = round($totals['new_net'] - $totals['old_net'], 2);

        return [
            'period' => [
                'id' => $period->id,
                'name' => $period->name,
                'type' => $period->type,
                'start_date' => $period->start_date->toDateString(),
                'end_date' => $period->end_date->toDateString(),
            ],
            'rows' => $rows,
            'unchanged_count' => $unchanged,
            'skipped_missing_employee' => $skipped,
            'totals' => $totals,
        ];
    }

    /**
     * Valores monetarios de una entry como float.
     *
     * @return array<string, float>
     */
    private function moneyValues(PayrollEntry $entry): array
    {
        $values = [];
        foreach (self::MONEY_FIELDS as $field) {
            $values[$field] = round((float) $entry->{$field}, 2);
        }

        return $values;
    }
}
