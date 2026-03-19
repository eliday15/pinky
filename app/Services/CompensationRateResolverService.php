<?php

namespace App\Services;

use App\Models\CompensationType;
use App\Models\Employee;

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
     * Looks for active comp types assigned to the employee (via employee, position,
     * or department) that match the authorization type, ordered by priority.
     *
     * Args:
     *     employee: The employee to check
     *     authType: The authorization type (e.g., 'overtime', 'night_shift')
     *
     * Returns:
     *     The best-matching CompensationType or null
     */
    public function findApplicableType(Employee $employee, string $authType): ?CompensationType
    {
        // Get all active comp types for this auth type (with relations for rate resolution)
        $compTypes = CompensationType::active()
            ->forAuthorizationType($authType)
            ->with(['positions', 'departments'])
            ->orderBy('priority')
            ->get();

        if ($compTypes->isEmpty()) {
            return null;
        }

        // Check if employee has any of these assigned (directly or via position/department)
        $employeeCompTypeIds = $employee->compensationTypes
            ->where('pivot.is_active', true)
            ->pluck('id')
            ->toArray();

        // Return first matching by priority
        foreach ($compTypes as $ct) {
            if (in_array($ct->id, $employeeCompTypeIds)) {
                return $ct;
            }
        }

        // Fallback to first available by priority (global assignment)
        return $compTypes->first();
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

        $tiers = [];
        $remaining = $totalHours;

        // Filter out HET (priority 30) — only applied via explicit authorization
        $autoTiers = $overtimeTypes->filter(fn($ct) => $ct->priority < 30)->values();

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
    ): array {
        $concepts = [];
        $total = 0;

        // Overtime (tiered: HE/HED)
        $overtimeHours = $metrics['overtime_hours'] ?? 0;
        if ($overtimeHours > 0) {
            $tiers = $this->resolveOvertimeTiers($employee, $overtimeHours);
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
                ];
                $total += $amount;
            }
        }

        // Velada
        $veladaHours = $metrics['velada_hours'] ?? 0;
        if ($veladaHours > 0) {
            $veladaType = $this->findApplicableType($employee, 'night_shift');
            if ($veladaType) {
                $rate = $this->resolveRate($employee, $veladaType);
                $amount = $veladaType->calculateCompensation(
                    $hourlyRate,
                    $dailySalary,
                    $veladaHours,
                    0,
                    $rate['percentage'],
                    $rate['fixed_amount'],
                );
                $concepts[] = [
                    'code' => $veladaType->code,
                    'name' => $veladaType->name,
                    'hours' => $veladaHours,
                    'days' => 0,
                    'rate' => $rate,
                    'amount' => $amount,
                ];
                $total += $amount;
            }
        }

        // Holiday
        $holidayHours = $metrics['holiday_hours'] ?? 0;
        if ($holidayHours > 0) {
            $holidayType = $this->findApplicableType($employee, 'holiday_worked');
            if ($holidayType) {
                $rate = $this->resolveRate($employee, $holidayType);
                // Holiday can be per_hour or per_day depending on comp type config
                $days = $holidayHours > 0 ? ceil($holidayHours / 8) : 0;
                $amount = $holidayType->calculateCompensation(
                    $hourlyRate,
                    $dailySalary,
                    $holidayHours,
                    $days,
                    $rate['percentage'],
                    $rate['fixed_amount'],
                );
                $concepts[] = [
                    'code' => $holidayType->code,
                    'name' => $holidayType->name,
                    'hours' => $holidayHours,
                    'days' => $days,
                    'rate' => $rate,
                    'amount' => $amount,
                ];
                $total += $amount;
            }
        }

        // Weekend (uses overtime comp type as fallback)
        $weekendHours = $metrics['weekend_hours'] ?? 0;
        if ($weekendHours > 0) {
            $weekendType = $this->findApplicableType($employee, 'overtime');
            if ($weekendType) {
                $rate = $this->resolveRate($employee, $weekendType);
                $amount = $weekendType->calculateCompensation(
                    $hourlyRate,
                    $dailySalary,
                    $weekendHours,
                    0,
                    $rate['percentage'],
                    $rate['fixed_amount'],
                );
                $concepts[] = [
                    'code' => $weekendType->code,
                    'name' => $weekendType->name . ' (Fin de semana)',
                    'hours' => $weekendHours,
                    'days' => 0,
                    'rate' => $rate,
                    'amount' => $amount,
                ];
                $total += $amount;
            }
        }

        return [
            'total' => round($total, 2),
            'concepts' => $concepts,
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
        // Check direct assignment
        $hasDirectAssignment = $employee->compensationTypes
            ->where('pivot.is_active', true)
            ->isNotEmpty();

        if ($hasDirectAssignment) {
            return true;
        }

        // Check via position or department (any active comp type with authorization_type)
        return CompensationType::active()
            ->whereNotNull('authorization_type')
            ->exists();
    }
}
