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
        ?array $allowedPaymentPeriods = null,
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
            if (! $this->paymentPeriodAllowed($compType, $allowedPaymentPeriods)) {
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
                if (! $this->paymentPeriodAllowed($tier['comp_type'], $allowedPaymentPeriods)) {
                    continue;
                }
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

        if ($veladaType && ! $this->paymentPeriodAllowed($veladaType, $allowedPaymentPeriods)) {
            $veladaType = null;
        }

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
            // El FIN se paga SIEMPRE por unidades abajo (Almacén por horas; los
            // demás 1 por día que alcanza el umbral), nunca por fila/día aquí.
            skipWeekendPullRule: true,
            allowedPaymentPeriods: $allowedPaymentPeriods,
            // La comida por unidades es exclusiva de Almacén PT; en los demás
            // deptos la comida sigue pagándose por fila/día en el pase genérico.
            skipComidaPullRule: $weekendUnitHours !== null,
        );
        foreach ($generic['concepts'] as $concept) {
            $concepts[] = $concept;
        }
        $total += $generic['total'];

        // Fin de semana por unidades (Almacén PT): el # de unidades lo calcula la
        // nómina por cada día de fin de semana autorizado (al menos 1 por día,
        // 12 h = 2), pasado en metrics['weekend_units']. Se paga unidades × valor
        // de una unidad del concepto FIN.
        // Fin de semana por unidades. Almacén PT: # de unidades por horas
        // (weekend_units, 12 h = 2). Los demás deptos (Dani 2026-07-07): 1 fin de
        // semana por día que alcanzó el umbral; se paga con el monto del concepto
        // FIN del empleado (per_day + fixed) — si no tiene monto, 0.
        $weekendUnits = (int) ($metrics['weekend_units'] ?? 0);
        if ($weekendUnits > 0 && $authorizations) {
            $weekendConcept = $this->weekendUnitsConcept(
                $employee,
                $authorizations,
                $hourlyRate,
                $dailySalary,
                $weekendUnits,
                $weekendUnitHours,
                $allowedPaymentPeriods,
            );
            if ($weekendConcept) {
                $concepts[] = $weekendConcept;
                $total += $weekendConcept['amount'];
            }

            // Comida por unidades: EXCLUSIVA de Almacén PT (deptos que pagan por
            // unidades de horas). UNA comida por cada unidad de fin de semana
            // (12 h = 2 comidas). Los demás deptos no generan comida de fin de
            // semana.
            if ($weekendUnitHours) {
                $comidaConcept = $this->comidaUnitsConcept(
                    $employee,
                    $authorizations,
                    $hourlyRate,
                    $dailySalary,
                    $weekendUnits,
                    $weekendUnitHours,
                    $allowedPaymentPeriods,
                );
                if ($comidaConcept) {
                    $concepts[] = $comidaConcept;
                    $total += $comidaConcept['amount'];
                }
            }
        }

        return [
            'total' => round($total, 2),
            'concepts' => $concepts,
        ];
    }

    /**
     * Build the weekend pay concept for departments that count the weekend by
     * worked-hour units (e.g. Almacén PT: 6 h = 1 fin de semana). Returns null
     * when there is no weekend comp type on the authorizations.
     */
    private function weekendUnitsConcept(
        Employee $employee,
        Collection $authorizations,
        float $hourlyRate,
        float $dailySalary,
        int $units,
        ?int $weekendUnitHours,
        ?array $allowedPaymentPeriods = null,
    ): ?array {
        $weekendAuth = $authorizations->first(
            fn (Authorization $a) => $a->compensationType?->hasWeekendPullRule()
        );

        return $this->unitsConcept(
            $employee,
            $weekendAuth?->compensationType,
            $hourlyRate,
            $dailySalary,
            $units,
            $weekendUnitHours,
            'weekend_units',
            $allowedPaymentPeriods,
        );
    }

    /**
     * Build the comida (lunch) pay concept for unit-counting departments. A
     * comida is paid per weekend unit, igualada al fin de semana: 12 h = 2
     * comidas. Returns null when there is no comida comp type on the
     * authorizations.
     */
    private function comidaUnitsConcept(
        Employee $employee,
        Collection $authorizations,
        float $hourlyRate,
        float $dailySalary,
        int $units,
        int $weekendUnitHours,
        ?array $allowedPaymentPeriods = null,
    ): ?array {
        $comidaAuth = $authorizations->first(
            fn (Authorization $a) => $a->compensationType?->hasComidaPullRule()
        );

        return $this->unitsConcept(
            $employee,
            $comidaAuth?->compensationType,
            $hourlyRate,
            $dailySalary,
            $units,
            $weekendUnitHours,
            'comida_units',
            $allowedPaymentPeriods,
        );
    }

    /**
     * Pay a pull-rule concept (FIN / COM) by worked-hour units. Pays
     * `unidades × valor de una unidad`, where unidades = weekendHours ÷
     * weekendUnitHours and the per-unit value follows the comp type's mode
     * (per_day / per_hour / one_time) so it stays proportional regardless of
     * how the concept is configured. Returns null when there is no comp type,
     * the payment period doesn't apply, or the amount resolves to zero.
     */
    private function unitsConcept(
        Employee $employee,
        ?CompensationType $compType,
        float $hourlyRate,
        float $dailySalary,
        int $units,
        ?int $weekendUnitHours,
        string $source,
        ?array $allowedPaymentPeriods = null,
    ): ?array {
        // weekendUnitHours puede ser NULL en deptos que no pagan por unidades de
        // horas (todos menos Almacén PT): ahí el concepto FIN es per_day y su
        // valor por unidad NO depende de weekendUnitHours. Solo el modo per_hour
        // lo necesita (Almacén), y sin él vale 0.
        if (! $compType || $units <= 0) {
            return null;
        }
        if (! $this->paymentPeriodAllowed($compType, $allowedPaymentPeriods)) {
            return null;
        }

        $rate = $this->resolveRate($employee, $compType);

        // Valor de UNA unidad según el modo del concepto.
        $perUnit = match ($compType->application_mode) {
            CompensationType::APPLICATION_PER_HOUR => $compType->calculateCompensation(
                $hourlyRate, $dailySalary, (float) ($weekendUnitHours ?? 0), 0, $rate['percentage'], $rate['fixed_amount'],
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
            'hours' => 0.0,
            'days' => $units,
            'rate' => $rate,
            'amount' => $amount,
            'authorization_type' => $compType->authorization_type,
            'attendance_pull_rule' => $compType->attendance_pull_rule,
            'source' => $source,
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
        ?array $allowedPaymentPeriods = null,
        bool $skipComidaPullRule = false,
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
            if ($auth->compensationType
                && ! $this->paymentPeriodAllowed($auth->compensationType, $allowedPaymentPeriods)) {
                continue;
            }
            // El FIN se paga aparte por unidades (Almacén por horas; los demás 1
            // por día que alcanza el umbral) — aquí se salta para no duplicar. La
            // comida solo se salta cuando también va por unidades (Almacén PT); en
            // los demás deptos la comida sigue pagándose por fila/día aquí.
            if ($skipWeekendPullRule && $auth->compensationType?->hasWeekendPullRule()) {
                continue;
            }
            if ($skipComidaPullRule && $auth->compensationType?->hasComidaPullRule()) {
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

            // One-time concepts pay their fixed amount times the authorized
            // quantity (units/bonos, stored in auth->hours), e.g. "600 bonos ×
            // $1 = $600". The quantity defaults to 1 so a plain lump-sum concept
            // (or an authorization with no quantity) keeps paying its fixed
            // amount exactly once, as before.
            $quantity = 1.0;
            if ($compType->application_mode === CompensationType::APPLICATION_ONE_TIME
                && (float) $auth->hours > 0) {
                $quantity = (float) $auth->hours;
            }

            $amount = $compType->calculateCompensation(
                $hourlyRate,
                $dailySalary,
                $hours,
                $days,
                $rate['percentage'],
                $rate['fixed_amount'],
            );

            if ($quantity !== 1.0) {
                $amount = round($amount * $quantity, 2);
            }

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
                // Units/bonos for one-time concepts (e.g. 600); 0 otherwise so
                // the UI only annotates the quantity when it's meaningful.
                'quantity' => $compType->application_mode === CompensationType::APPLICATION_ONE_TIME
                    ? round($quantity, 2)
                    : 0,
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
     * Conceptos RECURRENTES del empleado que pagan en este periodo (Luis
     * 2026-07-09): cantidades fijas que se dan CADA periodo (semanal o mensual,
     * según payment_period) de forma automática, SIN autorización y sin
     * condición de asistencia. Una por concepto recurrente activo inscrito al
     * empleado, con el monto resuelto (personalizado del empleado → puesto →
     * depto → global). El application_mode es irrelevante: siempre paga el
     * monto fijo (o el % del sueldo diario) una vez por periodo.
     *
     * @param  Employee  $employee  Empleado con compensationTypes eager-loaded
     * @param  array|null  $allowedPaymentPeriods  Periodos que paga la nómina actual
     * @return list<array<string, mixed>>
     */
    public function calculateRecurringConcepts(
        Employee $employee,
        float $hourlyRate,
        float $dailySalary,
        ?array $allowedPaymentPeriods = null,
    ): array {
        $concepts = [];

        foreach ($employee->compensationTypes as $compType) {
            if (! $compType->pivot->is_active || ! $compType->is_recurring) {
                continue;
            }
            if (! $this->paymentPeriodAllowed($compType, $allowedPaymentPeriods)) {
                continue;
            }

            $rate = $this->resolveRate($employee, $compType);

            // Monto fijo tal cual, o el porcentaje sobre el sueldo diario. No
            // depende del application_mode: es una cantidad fija por periodo. Un
            // monto NEGATIVO es una deducción (Infonavit, préstamo) — se conserva
            // el signo; la nómina lo descuenta del efectivo.
            $amount = $rate['fixed_amount'] !== null
                ? round((float) $rate['fixed_amount'], 2)
                : round($dailySalary * ((float) ($rate['percentage'] ?? 0) / 100), 2);

            if ($amount === 0.0) {
                continue;
            }

            $concepts[] = [
                'code' => $compType->code,
                'name' => $compType->name,
                'hours' => 0.0,
                'days' => 0,
                'rate' => $rate,
                'amount' => $amount,
                'authorization_type' => $compType->authorization_type,
                'attendance_pull_rule' => $compType->attendance_pull_rule,
                'source' => 'recurring',
            ];
        }

        return $concepts;
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

    /**
     * Whether a compensation type may pay in the current period, given the set
     * of payment periods that period pays.
     *
     * A null set means "no filtering" (legacy/report callers): every concept is
     * allowed. Otherwise the concept's payment_period (default 'monthly') must
     * be in the allowed set.
     */
    private function paymentPeriodAllowed(CompensationType $compType, ?array $allowedPaymentPeriods): bool
    {
        if ($allowedPaymentPeriods === null) {
            return true;
        }

        $period = $compType->payment_period ?? CompensationType::PAYMENT_PERIOD_MONTHLY;

        return in_array($period, $allowedPaymentPeriods, true);
    }
}
