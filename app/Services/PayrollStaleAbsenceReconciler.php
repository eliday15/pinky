<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\PayrollCfdi;
use App\Models\PayrollEntry;
use Illuminate\Support\Collection;

/**
 * Conciliación de deducciones por falta que quedaron obsoletas.
 *
 * Problema que resuelve (Luis / Elsa, 2026-09-23): la nómina de una semana se
 * calcula con la checada incompleta de un día, el sync corrige esa checada
 * DESPUÉS y — como el periodo ya se pagó — el descuento por "falta
 * injustificada" se queda congelado para siempre. El empleado aparece con la
 * checada bien en Asistencia y con la falta en su recibo.
 *
 * Este servicio detecta exactamente eso: recibos con deducción de falta sobre
 * un día cuya checada HOY dice que se trabajó (present/late/partial o con horas
 * trabajadas). No adivina: compara el detalle guardado contra la asistencia
 * viva.
 *
 * Lo que NO toca: "Falta por acumulación de retardos" (FRT) es una sanción
 * deliberada sobre días trabajados, no un dato obsoleto.
 */
class PayrollStaleAbsenceReconciler
{
    /** Motivos de deducción que se pueden quedar obsoletos si cambia la checada. */
    private const CHECKABLE_REASONS = [
        'Falta injustificada',
        'Falta por incidencia',
    ];

    /**
     * Estados de asistencia que significan "sí trabajó ese día".
     *
     * Solo el STATUS decide. Un registro con status 'absent' y horas
     * trabajadas NO es un dato obsoleto: es una falta legítima por retardo
     * extremo o salida temprana (reglas del sync). Tomar las horas como
     * evidencia de trabajo marcaba 61 recibos sanos como si estuvieran mal.
     */
    private const WORKED_STATUSES = ['present', 'late', 'partial'];

    public function __construct(private PayrollCalculatorService $calculator)
    {
    }

    /**
     * Recibos con deducciones de falta que ya no corresponden a la asistencia.
     *
     * @param  int|null  $periodId  Limita a un periodo.
     * @param  int|null  $employeeId  Limita a un empleado.
     * @return Collection<int, array{entry: PayrollEntry, dates: array<int, string>, estimated: float}>
     */
    public function staleDeductions(?int $periodId = null, ?int $employeeId = null): Collection
    {
        $entries = PayrollEntry::query()
            // SIN recortar columnas: el recálculo necesita el empleado y el
            // periodo completos (horario, alta/baja, departamento, extras...).
            // Con un select parcial el motor calcula en cero, en silencio.
            ->with(['employee', 'payrollPeriod'])
            ->where('deductions', '>', 0)
            ->whereHas('payrollPeriod', fn ($q) => $q->whereIn('status', ['review', 'approved', 'paid']))
            ->when($periodId, fn ($q) => $q->where('payroll_period_id', $periodId))
            ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
            ->get();

        $stale = collect();

        foreach ($entries as $entry) {
            $dates = $this->staleDates($entry);

            if ($dates !== []) {
                $stale->push([
                    'entry' => $entry,
                    'dates' => $dates,
                    'estimated' => $this->estimate($entry, count($dates)),
                ]);
            }
        }

        return $stale;
    }

    /**
     * Recalcula el recibo de los empleados afectados y deja rastro en auditoría.
     *
     * El importe pagado SÍ cambia (es el punto: se le devuelve al empleado lo
     * que se le descontó de más), por eso cada corrección queda en el audit log
     * con el antes y el después.
     *
     * @param  Collection<int, array{entry: PayrollEntry, dates: array<int, string>, estimated: float}>  $stale
     * @return array{recalculated: int, skipped_cfdi: int, deltas: array<int, array<string, mixed>>}
     */
    public function apply(Collection $stale): array
    {
        $recalculated = 0;
        $skippedCfdi = 0;
        $deltas = [];

        foreach ($stale as $row) {
            /** @var PayrollEntry $entry */
            $entry = $row['entry'];
            $period = $entry->payrollPeriod;
            $employee = $entry->employee;

            if (! $period || ! $employee) {
                continue;
            }

            // Mismo candado que el recálculo manual: un recibo timbrado ante el
            // SAT no se puede reescribir sin cancelar el CFDI primero.
            if ($this->hasStampedCfdi($period->id)) {
                $skippedCfdi++;
                continue;
            }

            $before = [
                'net_pay' => (float) $entry->net_pay,
                'deductions' => (float) $entry->deductions,
                'days_absent' => (int) $entry->days_absent,
                'cash_amount' => (float) $entry->cash_amount,
                'bank_amount' => (float) $entry->bank_amount,
            ];

            $fresh = $this->calculator->calculateEmployeePayroll($period, $employee);

            $after = [
                'net_pay' => (float) $fresh->net_pay,
                'deductions' => (float) $fresh->deductions,
                'days_absent' => (int) $fresh->days_absent,
                'cash_amount' => (float) $fresh->cash_amount,
                'bank_amount' => (float) $fresh->bank_amount,
            ];

            $delta = [
                'entry_id' => $entry->id,
                'employee' => $employee->full_name,
                'period' => $period->name,
                'dates' => $row['dates'],
                'before' => $before,
                'after' => $after,
                'net_delta' => round($after['net_pay'] - $before['net_pay'], 2),
            ];
            $deltas[] = $delta;

            AuditLog::record(
                module: AuditLog::MODULE_PAYROLL,
                action: AuditLog::ACTION_RECALCULATE,
                model: $fresh,
                description: 'Concilio la falta obsoleta de ' . $employee->full_name
                    . ' en ' . $period->name
                    . ' (' . implode(', ', $row['dates']) . '): neto '
                    . number_format($before['net_pay'], 2) . ' -> ' . number_format($after['net_pay'], 2),
                subjectLabel: $employee->full_name,
                metadata: $delta,
            );

            $recalculated++;
        }

        return [
            'recalculated' => $recalculated,
            'skipped_cfdi' => $skippedCfdi,
            'deltas' => $deltas,
        ];
    }

    /**
     * Fechas del detalle del recibo con deducción de falta cuya checada actual
     * dice que se trabajó.
     *
     * @return array<int, string>
     */
    private function staleDates(PayrollEntry $entry): array
    {
        $detail = $entry->calculation_breakdown['deduction_detail'] ?? [];

        if (! is_array($detail)) {
            return [];
        }

        $dates = [];

        foreach ($detail as $item) {
            $reason = $item['reason'] ?? null;
            $date = $item['date'] ?? null;

            if (! $date || ! in_array($reason, self::CHECKABLE_REASONS, true)) {
                continue;
            }

            $record = AttendanceRecord::where('employee_id', $entry->employee_id)
                ->whereDate('work_date', $date)
                ->first(['status', 'worked_hours']);

            if (! $record) {
                continue;
            }

            if (in_array($record->status, self::WORKED_STATUSES, true)) {
                $dates[] = (string) $date;
            }
        }

        return $dates;
    }

    /**
     * Estimado del descuento obsoleto: cada falta descuenta el día + el séptimo
     * día (SD × 7/6). Es una aproximación para el reporte; el importe real sale
     * del recálculo.
     */
    private function estimate(PayrollEntry $entry, int $days): float
    {
        $dailySalary = (float) ($entry->daily_salary ?: ($entry->hourly_rate * 8));

        return round($dailySalary * (7 / 6) * $days, 2);
    }

    private function hasStampedCfdi(int $periodId): bool
    {
        return PayrollCfdi::whereHas('payrollEntry', fn ($q) => $q->where('payroll_period_id', $periodId))
            ->where('status', PayrollCfdi::STATUS_STAMPED)
            ->exists();
    }
}
