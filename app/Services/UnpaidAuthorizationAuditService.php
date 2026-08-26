<?php

namespace App\Services;

use App\Models\Authorization;
use App\Models\CheckOmission;
use App\Models\CompensationType;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Autorizaciones APROBADAS que ninguna nómina paga (Elias 2026-08-26:
 * "arreglar los problemas desde el fondo para que no vuelvan a suceder").
 *
 * El sistema tiene dos alcances de pago —la nómina que paga el SUELDO BASE y la
 * que paga los EXTRAS— y cada concepto cae en uno de los dos según su
 * "¿Cuándo se paga?". Cuando falta la nómina del alcance que le toca, la
 * autorización se queda aprobada y sin pagarse, sin que nada lo diga: le pasó a
 * Taller al dejar de llevar mensual, y es el mismo agujero por el que se
 * perdieron los Descuentos Infonavit.
 *
 * Este servicio revisa, para un periodo, las autorizaciones aprobadas de sus
 * fechas que NO paga él y comprueba si existe otra nómina del mismo alcance que
 * sí las pague. Las que no aparecen en ninguna se reportan.
 */
class UnpaidAuthorizationAuditService
{
    /**
     * @return list<array{employee: string, concept: string, date: string, kind: string, reason: string}>
     */
    public function forPeriod(PayrollPeriod $period): array
    {
        [$from, $to] = $this->coveredRange($period);

        $employeeIds = $this->employeeIdsInScope($period);
        if ($employeeIds === []) {
            return [];
        }

        $authorizations = Authorization::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereNotNull('compensation_type_id')
            ->whereIn('status', [Authorization::STATUS_APPROVED, Authorization::STATUS_PAID])
            ->whereBetween('date', [$from, $to])
            ->with(['compensationType:id,name,code,payment_period', 'employee:id,full_name'])
            ->orderBy('date')
            ->get();

        if ($authorizations->isEmpty()) {
            return [];
        }

        $scopePeriods = PayrollPeriod::query()
            ->where('department_id', $period->department_id)
            ->get(['id', 'type', 'start_date', 'end_date', 'extras_start_date', 'extras_end_date']);

        $alerts = [];

        foreach ($authorizations as $authorization) {
            $date = Carbon::parse($authorization->date)->toDateString();
            $kind = $authorization->compensationType?->payment_period ?? CompensationType::PAYMENT_PERIOD_MONTHLY;

            if ($this->periodPays($period, $kind, $date)) {
                continue;
            }

            $paidElsewhere = $scopePeriods->contains(
                fn (PayrollPeriod $candidate) => $candidate->id !== $period->id
                    && $this->periodPays($candidate, $kind, $date)
            );

            if ($paidElsewhere) {
                continue;
            }

            $alerts[] = [
                'employee' => $authorization->employee?->full_name ?? 'Empleado',
                'concept' => $authorization->compensationType?->name
                    ?? $authorization->compensationType?->code
                    ?? 'Concepto',
                'date' => $date,
                'kind' => $kind,
                'reason' => $kind === CompensationType::PAYMENT_PERIOD_WEEKLY
                    ? 'Su concepto se paga con el sueldo base y no hay nomina de esas fechas que lo cubra'
                    : 'Su concepto se paga con los extras del mes y no hay nomina de esas fechas que los pague',
            ];
        }

        return $alerts;
    }

    /**
     * Empleados cuya semana se recortó por su FECHA DE ALTA y que, aun así,
     * tienen omisiones o autorizaciones APROBADAS de días anteriores a esa
     * fecha (Elias/Luis 2026-08-26, caso Juan José López: nuevo, apenas dado de
     * alta en el checador, con omisiones aprobadas del 19, 20 y 21 y una fecha
     * de ingreso del 24 — la nómina le pagaba 2 días).
     *
     * Trabajó esos días según lo aprobado, pero la nómina no puede pagarlos
     * porque, para el sistema, todavía no existía. Casi siempre significa que la
     * fecha de ingreso está mal capturada.
     *
     * @return list<array{employee: string, hire_date: string, approved_before: int, first_date: string}>
     */
    public function hireDateConflicts(PayrollPeriod $period): array
    {
        if (! $period->paysBase()) {
            return [];
        }

        $baseStart = Carbon::parse($period->start_date)->startOfDay();
        $baseEnd = $period->type === 'weekly'
            ? $baseStart->copy()->addDays(6)
            : Carbon::parse($period->end_date)->startOfDay();

        $employees = Employee::active()
            ->whereIn('id', $this->employeeIdsInScope($period))
            ->whereNotNull('hire_date')
            ->whereBetween('hire_date', [$baseStart->toDateString(), $baseEnd->toDateString()])
            ->get(['id', 'full_name', 'hire_date']);

        if ($employees->isEmpty()) {
            return [];
        }

        $alerts = [];

        foreach ($employees as $employee) {
            $hire = Carbon::parse($employee->hire_date)->startOfDay();
            if (! $hire->gt($baseStart)) {
                continue;
            }

            $window = [$baseStart->toDateString(), $hire->copy()->subDay()->toDateString()];

            $omissionDates = CheckOmission::query()
                ->where('employee_id', $employee->id)
                ->where('status', CheckOmission::STATUS_APPROVED)
                ->whereBetween('work_date', $window)
                ->orderBy('work_date')
                ->pluck('work_date');

            $authorizationDates = Authorization::query()
                ->where('employee_id', $employee->id)
                ->whereIn('status', [Authorization::STATUS_APPROVED, Authorization::STATUS_PAID])
                ->whereBetween('date', $window)
                ->orderBy('date')
                ->pluck('date');

            $dates = $omissionDates->concat($authorizationDates)
                ->map(fn ($d) => Carbon::parse($d)->toDateString())
                ->sort()
                ->values();

            if ($dates->isEmpty()) {
                continue;
            }

            $alerts[] = [
                'employee' => $employee->full_name,
                'hire_date' => $hire->toDateString(),
                'approved_before' => $dates->count(),
                'first_date' => $dates->first(),
            ];
        }

        return $alerts;
    }

    /**
     * ¿Este periodo paga un concepto de ese alcance en esa fecha?
     */
    private function periodPays(PayrollPeriod $period, string $kind, string $date): bool
    {
        if ($kind === CompensationType::PAYMENT_PERIOD_WEEKLY) {
            return $period->paysBase() && $this->between($date, $period->start_date, $period->end_date);
        }

        if (! $period->paysExtras()) {
            return false;
        }

        return $period->isUnified()
            ? $this->between($date, $period->extras_start_date, $period->extras_end_date)
            : $this->between($date, $period->start_date, $period->end_date);
    }

    /**
     * Rango completo que cubre el periodo (la semana del base más, si es
     * unificado, el mes de los extras).
     *
     * @return array{0: string, 1: string}
     */
    private function coveredRange(PayrollPeriod $period): array
    {
        $starts = [Carbon::parse($period->start_date)];
        $ends = [Carbon::parse($period->end_date)];

        if ($period->isUnified()) {
            $starts[] = Carbon::parse($period->extras_start_date);
            $ends[] = Carbon::parse($period->extras_end_date);
        }

        return [
            collect($starts)->min()->toDateString(),
            collect($ends)->max()->toDateString(),
        ];
    }

    /**
     * Los empleados que le tocan al periodo: los de su departamento, o —en la
     * general— todos menos los de departamentos con nómina propia. Mismo
     * criterio que el calculador, para no reportar a quien no le corresponde.
     *
     * @return list<int>
     */
    private function employeeIdsInScope(PayrollPeriod $period): array
    {
        return Employee::active()
            ->when($period->department_id, fn ($q) => $q->where('department_id', $period->department_id))
            ->when(
                $period->department_id === null,
                fn ($q) => $q->whereDoesntHave('department', fn ($d) => $d->where('has_separate_payroll', true))
            )
            ->pluck('id')
            ->all();
    }

    private function between(string $date, $start, $end): bool
    {
        return $date >= Carbon::parse($start)->toDateString()
            && $date <= Carbon::parse($end)->toDateString();
    }
}
