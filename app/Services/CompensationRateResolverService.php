<?php

namespace App\Services;

use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Resolves the effective compensation rate for an employee and compensation type.
 *
 * Resolution chain: employee custom → position default → department default → global.
 * Also handles overtime tier distribution (HE/HED/HET).
 */
class CompensationRateResolverService
{
    /**
     * Resolve the effective percentage and fixed amount for a given compensation type.
     *
     * Args:
     *     employee: The employee to resolve rates for
     *     compType: The compensation type to resolve
     *
     * Returns:
     *     Array with 'percentage' and 'fixed_amount' keys
     */
    public function resolveRate(Employee $employee, CompensationType $compType): array
    {
        // 1. Employee custom rate (pivot)
        $employeePivot = $employee->compensationTypes
            ->firstWhere('id', $compType->id);

        if ($employeePivot && $employeePivot->pivot->is_active) {
            if ($employeePivot->pivot->custom_percentage !== null) {
                return [
                    'percentage' => (float) $employeePivot->pivot->custom_percentage,
                    'fixed_amount' => null,
                ];
            }
            if ($employeePivot->pivot->custom_fixed_amount !== null) {
                return [
                    'percentage' => null,
                    'fixed_amount' => (float) $employeePivot->pivot->custom_fixed_amount,
                ];
            }
        }

        // 2. Position default rate
        if ($employee->position_id) {
            $positionPivot = $compType->positions
                ->firstWhere('id', $employee->position_id);

            if ($positionPivot) {
                if ($positionPivot->pivot->default_percentage !== null) {
                    return [
                        'percentage' => (float) $positionPivot->pivot->default_percentage,
                        'fixed_amount' => null,
                    ];
                }
                if ($positionPivot->pivot->default_fixed_amount !== null) {
                    return [
                        'percentage' => null,
                        'fixed_amount' => (float) $positionPivot->pivot->default_fixed_amount,
                    ];
                }
            }
        }

        // 3. Department default rate
        if ($employee->department_id) {
            $deptPivot = $compType->departments
                ->firstWhere('id', $employee->department_id);

            if ($deptPivot) {
                if ($deptPivot->pivot->default_percentage !== null) {
                    return [
                        'percentage' => (float) $deptPivot->pivot->default_percentage,
                        'fixed_amount' => null,
                    ];
                }
                if ($deptPivot->pivot->default_fixed_amount !== null) {
                    return [
                        'percentage' => null,
                        'fixed_amount' => (float) $deptPivot->pivot->default_fixed_amount,
                    ];
                }
            }
        }

        // 4. Global rate (from the compensation type itself)
        return [
            'percentage' => $compType->calculation_type === 'percentage'
                ? (float) $compType->percentage_value
                : null,
            'fixed_amount' => $compType->calculation_type === 'fixed'
                ? (float) $compType->fixed_amount
                : null,
        ];
    }

    /**
     * Find the applicable compensation type for a given authorization type.
     *
     * Only returns a comp type the employee is explicitly assigned to (via the
     * employee_compensation_type pivot). No global/priority fallback.
     *
     * Args:
     *     employee: The employee to check
     *     authType: The authorization type (e.g., 'overtime', 'night_shift')
     *
     * Returns:
     *     The best-matching CompensationType or null when the employee has none
     */
    public function findApplicableType(Employee $employee, string $authType): ?CompensationType
    {
        $compTypes = CompensationType::active()
            ->forAuthorizationType($authType)
            ->with(['positions', 'departments'])
            ->orderBy('priority')
            ->get();

        if ($compTypes->isEmpty()) {
            return null;
        }

        $employeeCompTypeIds = $employee->compensationTypes
            ->where('pivot.is_active', true)
            ->pluck('id')
            ->toArray();

        foreach ($compTypes as $ct) {
            if (in_array($ct->id, $employeeCompTypeIds)) {
                return $ct;
            }
        }

        return null;
    }

    /**
     * Distribute overtime hours across tiers (HE/HED/HET).
     *
     * - First tier (HE, priority 10): up to 9 hrs/week
     * - Second tier (HED, priority 20): remaining hours
     * - HET (priority 30): only via explicit authorization with compensation_type_id
     *
     * Args:
     *     employee: The employee
     *     totalHours: Total overtime hours to distribute
     *     weeklyThreshold: Hours threshold for first tier (default 9)
     *
     * Returns:
     *     Array of ['comp_type' => CompensationType, 'hours' => float] entries
     */
    public function resolveOvertimeTiers(Employee $employee, float $totalHours, float $weeklyThreshold = 9.0): array
    {
        $overtimeTypes = CompensationType::active()
            ->forAuthorizationType('overtime')
            ->with(['positions', 'departments'])
            ->orderBy('priority')
            ->get();

        if ($overtimeTypes->isEmpty() || $totalHours <= 0) {
            return [];
        }

        // Only auto-tier comp types the employee is explicitly assigned to.
        $assignedIds = $employee->compensationTypes
            ->where('pivot.is_active', true)
            ->pluck('id')
            ->toArray();

        $tiers = [];
        $remaining = $totalHours;

        // Filter out HET (priority 30) — only applied via explicit authorization —
        // and any comp type not assigned to this employee.
        $autoTiers = $overtimeTypes
            ->filter(fn ($ct) => $ct->priority < 30 && in_array($ct->id, $assignedIds))
            ->values();

        if ($autoTiers->isEmpty()) {
            return [];
        }

        foreach ($autoTiers as $index => $compType) {
            if ($remaining <= 0) {
                break;
            }

            if ($index === 0) {
                // First tier: up to threshold
                $tierHours = min($remaining, $weeklyThreshold);
            } else {
                // Subsequent tiers: all remaining
                $tierHours = $remaining;
            }

            $tiers[] = [
                'comp_type' => $compType,
                'hours' => round($tierHours, 2),
            ];

            $remaining -= $tierHours;
        }

        return $tiers;
    }

    /**
     * Calculate all compensation payments for an employee in a payroll period.
     *
     * Args:
     *     employee: The employee (must have compensationTypes eager loaded)
     *     metrics: Array with keys like overtime_hours, velada_hours, holiday_hours, weekend_hours
     *     hourlyRate: Employee's hourly rate
     *     dailySalary: Employee's daily salary
     *
     * Returns:
     *     Array with 'total' and 'concepts' breakdown
     */
    public function calculateAllCompensation(
        Employee $employee,
        array $metrics,
        float $hourlyRate,
        float $dailySalary,
        ?Collection $authorizations = null,
        array $holidayDates = [],
        ?int $weekendUnitHours = null,
    ): array {
        $concepts = [];
        $total = 0;

        // Authorizations already paid by a dedicated metric-gated path
        // (overtime/velada) so the generic pass never double-pays them.
        $consumedAuthIds = [];

        // Split authorizations into explicit (have compensation_type_id) and generic
        $explicitOvertime = collect();
        $explicitVelada = collect();
        if ($authorizations) {
            $explicitOvertime = $authorizations->filter(
                fn (Authorization $a) => $a->compensation_type_id
                    && $a->type === Authorization::TYPE_OVERTIME
                    && (float) $a->hours > 0
            );
            $explicitVelada = $authorizations->filter(
                fn (Authorization $a) => $a->compensation_type_id
                    && $a->type === Authorization::TYPE_NIGHT_SHIFT
                    && (float) $a->hours > 0
            );
        }

        // Overtime: explicit auths (HED/HET) consume their hours at their own rate first.
        $overtimeHours = (float) ($metrics['overtime_hours'] ?? 0);
        $remainingOvertime = $overtimeHours;

        foreach ($explicitOvertime as $auth) {
            $compType = $auth->compensationType;
            if (! $compType) {
                continue;
            }
            // Cap the auth at the actually-worked overtime so we don't pay for hours
            // that aren't on the timecard.
            $hours = min((float) $auth->hours, $remainingOvertime);
            if ($hours <= 0) {
                continue;
            }
            $rate = $this->resolveRate($employee, $compType);
            $amount = $compType->calculateCompensation(
                $hourlyRate,
                $dailySalary,
                $hours,
                0,
                $rate['percentage'],
                $rate['fixed_amount'],
            );
            $concepts[] = [
                'code' => $compType->code,
                'name' => $compType->name,
                'hours' => round($hours, 2),
                'days' => 0,
                'rate' => $rate,
                'amount' => $amount,
                'source' => 'explicit_authorization',
            ];
            $total += $amount;
            $remainingOvertime -= $hours;
            $consumedAuthIds[] = $auth->id;
        }

        // Auto-tier whatever overtime is left (HE up to weekly threshold, then HED).
        if ($remainingOvertime > 0) {
            $tiers = $this->resolveOvertimeTiers($employee, $remainingOvertime);
            foreach ($tiers as $tier) {
                $rate = $this->resolveRate($employee, $tier['comp_type']);
                $amount = $tier['comp_type']->calculateCompensation(
                    $hourlyRate,
                    $dailySalary,
                    $tier['hours'],
                    0,
                    $rate['percentage'],
                    $rate['fixed_amount'],
                );
                $concepts[] = [
                    'code' => $tier['comp_type']->code,
                    'name' => $tier['comp_type']->name,
                    'hours' => $tier['hours'],
                    'days' => 0,
                    'rate' => $rate,
                    'amount' => $amount,
                    'source' => 'auto_tier',
                ];
                $total += $amount;
            }
        }

        // Velada: se paga por NOCHE trabajada y autorizada (velada_days), no por
        // hora. El valor por noche sigue el modo del concepto VEL: per_day +
        // fixed paga el "Monto" del empleado tal cual (1 velada = monto, n
        // veladas = n × monto, sin multiplicar por horas); per_hour (config
        // legada) sigue pagando por hora. El concepto se toma de la autorización
        // explícita o, si no la trae, del que tenga asignado el empleado (VEL).
        $veladaHours = (float) ($metrics['velada_hours'] ?? 0);
        $veladaDays = (int) ($metrics['velada_days'] ?? 0);

        $veladaType = $explicitVelada->first()?->compensationType
            ?? $this->findApplicableType($employee, 'night_shift');

        if ($veladaType && ($veladaDays > 0 || $veladaHours > 0)) {
            $rate = $this->resolveRate($employee, $veladaType);
            $amount = $veladaType->calculateCompensation(
                $hourlyRate,
                $dailySalary,
                $veladaHours,
                $veladaDays,
                $rate['percentage'],
                $rate['fixed_amount'],
            );
            $isPerDay = $veladaType->application_mode === CompensationType::APPLICATION_PER_DAY;
            $concepts[] = [
                'code' => $veladaType->code,
                'name' => $veladaType->name,
                'hours' => $isPerDay ? 0 : round($veladaHours, 2),
                'days' => $isPerDay ? $veladaDays : 0,
                'rate' => $rate,
                'amount' => $amount,
                'authorization_type' => $veladaType->authorization_type,
                'source' => $explicitVelada->isNotEmpty() ? 'explicit_authorization' : 'auto_tier',
            ];
            $total += $amount;

            // La velada es metric-gated: marca sus autorizaciones como pagadas
            // para que el pase genérico no las vuelva a pagar.
            foreach ($explicitVelada as $auth) {
                $consumedAuthIds[] = $auth->id;
            }
        }

        // Everything else (holiday/FEST, weekend/FIN, cena, comida, dominical
        // and any future "special" concept) is paid directly from its approved
        // authorization using its own CompensationType. Overtime and velada
        // stay metric-gated above; this generic pass covers the rest exactly
        // once (deduped against $consumedAuthIds), so the report and payroll
        // agree on which concepts pay.
        $generic = $this->payAuthorizationConcepts(
            $employee,
            $authorizations ?? collect(),
            $hourlyRate,
            $dailySalary,
            $consumedAuthIds,
            $holidayDates,
            // Cuando el depto cuenta el fin de semana por unidades de horas
            // trabajadas, NO se paga por fila/día aquí: se paga abajo por unidades.
            $weekendUnitHours !== null,
        );
        foreach ($generic['concepts'] as $concept) {
            $concepts[] = $concept;
        }
        $total += $generic['total'];

        // Fin de semana por unidades (Almacén PT): el pago se basa en las horas
        // realmente trabajadas en sáb/dom, no por fila/día. unidades =
        // horas_fin_de_semana ÷ weekend_unit_hours, y se paga unidades × valor
        // de una unidad del concepto FIN.
        if ($weekendUnitHours && (float) ($metrics['weekend_hours'] ?? 0) > 0 && $authorizations) {
            $weekendConcept = $this->weekendUnitsConcept(
                $employee,
                $authorizations,
                $hourlyRate,
                $dailySalary,
                (float) $metrics['weekend_hours'],
                $weekendUnitHours,
            );
            if ($weekendConcept) {
                $concepts[] = $weekendConcept;
                $total += $weekendConcept['amount'];
            }
        }

        return [
            'total' => round($total, 2),
            'concepts' => $concepts,
        ];
    }

    /**
     * Build the weekend pay concept for departments that count the weekend by
     * worked-hour units (e.g. Almacén PT: 6 h = 1 fin de semana). Pays
     * `unidades × valor de una unidad`, where unidades = weekendHours ÷
     * weekendUnitHours and the per-unit value follows the FIN comp type's mode
     * (per_day / per_hour / one_time) so it stays proportional regardless of
     * how the concept is configured. Returns null when there is no weekend
     * comp type on the authorizations or the amount resolves to zero.
     */
    private function weekendUnitsConcept(
        Employee $employee,
        Collection $authorizations,
        float $hourlyRate,
        float $dailySalary,
        float $weekendHours,
        int $weekendUnitHours,
    ): ?array {
        if ($weekendUnitHours <= 0) {
            return null;
        }

        $weekendAuth = $authorizations->first(
            fn (Authorization $a) => $a->compensationType?->hasWeekendPullRule()
        );
        $compType = $weekendAuth?->compensationType;
        if (! $compType) {
            return null;
        }

        $units = round($weekendHours / $weekendUnitHours, 2);
        if ($units <= 0) {
            return null;
        }

        $rate = $this->resolveRate($employee, $compType);

        // Valor de UNA unidad de fin de semana según el modo del concepto.
        $perUnit = match ($compType->application_mode) {
            CompensationType::APPLICATION_PER_HOUR => $compType->calculateCompensation(
                $hourlyRate, $dailySalary, (float) $weekendUnitHours, 0, $rate['percentage'], $rate['fixed_amount'],
            ),
            CompensationType::APPLICATION_PER_DAY => $compType->calculateCompensation(
                $hourlyRate, $dailySalary, 0, 1, $rate['percentage'], $rate['fixed_amount'],
            ),
            default => $compType->calculateCompensation(
                $hourlyRate, $dailySalary, 0, 0, $rate['percentage'], $rate['fixed_amount'],
            ),
        };

        $amount = round($units * $perUnit, 2);
        if ($amount <= 0) {
            return null;
        }

        return [
            'code' => $compType->code,
            'name' => $compType->name,
            'hours' => round($weekendHours, 2),
            'days' => $units,
            'rate' => $rate,
            'amount' => $amount,
            'authorization_type' => $compType->authorization_type,
            'attendance_pull_rule' => $compType->attendance_pull_rule,
            'source' => 'weekend_units',
        ];
    }

    /**
     * Pay every approved authorization that is not handled by the metric-gated
     * overtime/velada paths, using its own CompensationType.
     *
     * Covers holiday (FEST), weekend (FIN), cena (CENA), comida (COM),
     * dominical (DOM) and any future "special" concept. Each authorization is
     * paid exactly once: rows already consumed by the overtime/velada loops are
     * skipped, and overtime/night_shift types (which are metric-gated and may
     * not be on the timecard) never pay from the authorization directly.
     *
     * Args:
     *     employee: The employee (compensationTypes must be eager loaded)
     *     authorizations: Approved/paid authorizations for the period
     *     hourlyRate: Employee's hourly rate
     *     dailySalary: Employee's daily salary
     *     consumedAuthIds: Authorization ids already paid by another path
     *     holidayDates: Y-m-d dates that are official holidays (to avoid paying
     *                   a weekend premium on a day already paid as holiday)
     *
     * Returns:
     *     Array with 'concepts' and 'total'
     */
    private function payAuthorizationConcepts(
        Employee $employee,
        Collection $authorizations,
        float $hourlyRate,
        float $dailySalary,
        array $consumedAuthIds,
        array $holidayDates = [],
        bool $skipWeekendPullRule = false,
    ): array {
        $concepts = [];
        $total = 0.0;
        $holidaySet = array_flip($holidayDates);

        foreach ($authorizations as $auth) {
            // Only pay authorizations that carry a compensation type — mirrors
            // the report, which ignores authorizations without a comp type.
            if (! $auth->compensation_type_id) {
                continue;
            }
            // Cuando el depto paga el fin de semana por unidades de horas
            // trabajadas, el concepto FIN se paga aparte (no por fila/día).
            if ($skipWeekendPullRule && $auth->compensationType?->hasWeekendPullRule()) {
                continue;
            }
            // Never re-pay something the overtime/velada paths already paid.
            if (in_array($auth->id, $consumedAuthIds, true)) {
                continue;
            }
            // Overtime/velada are metric-gated (capped to the timecard) and must
            // not pay straight from the authorization row.
            if (in_array($auth->type, [Authorization::TYPE_OVERTIME, Authorization::TYPE_NIGHT_SHIFT], true)) {
                continue;
            }

            $compType = $auth->compensationType;
            if (! $compType) {
                continue;
            }

            // A holiday that falls on a weekend is paid as holiday (FEST), not
            // also as a weekend premium (FIN).
            if ($compType->hasWeekendPullRule() && isset($holidaySet[Carbon::parse($auth->date)->toDateString()])) {
                continue;
            }

            $rate = $this->resolveRate($employee, $compType);

            // Per-day concepts pay one day per approved authorization row
            // (1 row = 1 day). Partial approval rejects whole rows, never
            // fractions of a day.
            [$hours, $days] = match ($compType->application_mode) {
                CompensationType::APPLICATION_PER_HOUR => [(float) $auth->hours, 0.0],
                CompensationType::APPLICATION_PER_DAY => [0.0, 1.0],
                default => [0.0, 0.0],
            };

            $amount = $compType->calculateCompensation(
                $hourlyRate,
                $dailySalary,
                $hours,
                $days,
                $rate['percentage'],
                $rate['fixed_amount'],
            );

            if ($amount <= 0) {
                // Misconfigured comp type (e.g. a fixed amount left at 0).
                // Surface it instead of silently paying nothing.
                Log::warning('Compensation authorization resolved to zero amount', [
                    'authorization_id' => $auth->id,
                    'compensation_type_id' => $compType->id,
                    'code' => $compType->code,
                    'application_mode' => $compType->application_mode,
                ]);

                continue;
            }

            $concepts[] = [
                'code' => $compType->code,
                'name' => $compType->name,
                'hours' => round($hours, 2),
                'days' => round($days, 2),
                'rate' => $rate,
                'amount' => $amount,
                'authorization_type' => $compType->authorization_type,
                'attendance_pull_rule' => $compType->attendance_pull_rule,
                'source' => 'authorization',
                'authorization_id' => $auth->id,
            ];
            $total += $amount;
        }

        return [
            'concepts' => $concepts,
            'total' => round($total, 2),
        ];
    }

    /**
     * Check if an employee has any compensation types assigned.
     *
     * Args:
     *     employee: The employee (must have compensationTypes eager loaded)
     *
     * Returns:
     *     True if the employee has active compensation types
     */
    public function hasCompensationTypes(Employee $employee): bool
    {
        // Only count explicit assignments — no global/position/department fallback.
        return $employee->compensationTypes
            ->where('pivot.is_active', true)
            ->isNotEmpty();
    }
}
