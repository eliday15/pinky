<?php

namespace App\Services\Reports;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\Employee;
use App\Models\Holiday;
use App\Services\ApprovedAuthorizationQuantityService;
use App\Services\CompensationRateResolverService;
use App\Services\WeekendAuthorizationUnitService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/** Approved extras accrued by work date, without payroll closing or recurring salary. */
class OvertimeSummaryReportService
{
    public function __construct(
        private readonly ApprovedAuthorizationQuantityService $quantities,
        private readonly WeekendAuthorizationUnitService $weekendUnits,
        private readonly CompensationRateResolverService $resolver,
    ) {}

    public function build(Collection $employeeIds, Carbon $start, Carbon $end): array
    {
        $from = $start->toDateString();
        $to = $end->toDateString();
        $employees = Employee::with(['department', 'schedule', 'compensationTypes'])->whereIn('id', $employeeIds)->get();
        $records = AttendanceRecord::whereIn('employee_id', $employeeIds)->whereBetween('work_date', [$from, $to])->get()->groupBy('employee_id');
        // Keep rejected/pending rows only to prevent stale attendance fallback throughout the selected range.
        $auths = Authorization::with(['compensationType.positions', 'compensationType.departments'])->whereIn('employee_id', $employeeIds)
            ->whereBetween('date', [$from, $to])->orderBy('id')->get()->groupBy('employee_id');
        $holidays = Holiday::whereBetween('date', [$from, $to])->pluck('date')->map(fn ($d) => Carbon::parse($d)->toDateString())->all();
        $rows = collect();

        foreach ($employees as $employee) {
            $employeeRecords = $records->get($employee->id, collect());
            $employeeAuths = $auths->get($employee->id, collect());
            $approved = $employeeAuths->whereIn('status', [Authorization::STATUS_APPROVED, Authorization::STATUS_PAID]);
            $extraDates = $employeeRecords->toBase()->filter(fn ($r) => $r->overtime_hours > 0 || $r->overtime_authorized_hours > 0 || $r->velada_authorized_hours > 0)
                ->map(fn ($r) => $r->work_date->toDateString())->merge($approved->map(fn ($a) => $a->date->toDateString()))->unique();
            if ($extraDates->isEmpty()) {
                continue;
            }
            // Match payroll's selected extras range. Any modern authorization of
            // a type (including rejected/pending) supersedes historical attendance.
            $authorized = $employeeAuths->contains('type', Authorization::TYPE_OVERTIME)
                ? $this->quantities->quantity($approved, Authorization::TYPE_OVERTIME, excludeWeekendPullRule: true)
                : (float) $employeeRecords->sum('overtime_authorized_hours');
            $veladaHours = $employeeAuths->contains('type', Authorization::TYPE_NIGHT_SHIFT)
                ? $this->quantities->quantity($approved, Authorization::TYPE_NIGHT_SHIFT)
                : (float) $employeeRecords->sum('velada_authorized_hours');
            $payments = $this->resolver->calculateAllCompensation($employee, [
                'overtime_hours' => $authorized,
                'velada_hours' => $veladaHours,
                'velada_days' => $this->quantities->uniqueDates($approved, Authorization::TYPE_NIGHT_SHIFT),
                'weekend_units' => $this->weekendUnits->materializedUnits($approved, $employeeRecords, $employee),
            ], (float) $employee->hourly_rate, (float) $employee->daily_salary_computed, $approved, $holidays, $employee->department?->weekend_unit_hours);
            $concepts = collect($payments['concepts']);
            foreach ($payments['zero_amount'] ?? [] as $missing) {
                $concepts->push([...$missing, 'amount' => 0, 'hours' => 0, 'days' => 0]);
            }
            $pricedOvertime = $concepts->filter(fn ($c) => in_array($c['source'] ?? null, ['explicit_authorization', 'auto_tier'], true) && ($c['authorization_type'] ?? null) !== Authorization::TYPE_NIGHT_SHIFT)->sum('hours');
            $pricedCodes = $concepts->pluck('code')->map(fn ($code) => strtoupper($code));
            $incomplete = $authorized > $pricedOvertime + 0.001
                || ($veladaHours > 0 && ! $concepts->contains('authorization_type', Authorization::TYPE_NIGHT_SHIFT))
                || $approved->contains(fn ($auth) => $auth->compensationType && ! $pricedCodes->contains(strtoupper($auth->compensationType->code)))
                || $concepts->contains(fn ($c) => (float) $c['amount'] === 0.0);
            $grouped = $this->groupConcepts($concepts);
            $rows->push([
                'employee' => ['id' => $employee->id, 'full_name' => $employee->full_name, 'employee_number' => $employee->employee_number, 'department' => $employee->department?->only('name')],
                'days_with_overtime' => $extraDates->count(),
                'total_overtime' => round($employeeRecords->sum('overtime_hours'), 2),
                'total_authorized' => round($authorized, 2),
                'estimated_cost' => round($grouped->sum('amount'), 2),
                'estimate_incomplete' => $incomplete,
                'concepts' => $grouped->all(),
            ]);
        }
        $rows = $rows->sortByDesc('estimated_cost')->values();

        return [
            'startDate' => $from, 'endDate' => $to, 'byEmployee' => $rows->all(),
            'summary' => [
                'estimate_incomplete' => $rows->contains('estimate_incomplete', true),
                'total_employees' => $rows->count(),
                'total_overtime_hours' => round($rows->sum('total_overtime'), 2),
                'total_authorized_hours' => round($rows->sum('total_authorized'), 2),
                'total_days_with_overtime' => $rows->sum('days_with_overtime'),
                'total_estimated_cost' => round($rows->sum('estimated_cost'), 2),
                'concepts' => $this->groupConcepts($rows->flatMap(fn ($r) => $r['concepts']))->all(),
            ],
        ];
    }

    private function groupConcepts(Collection $concepts): Collection
    {
        return $concepts->groupBy(fn ($c) => strtoupper($c['code']).'|'.$c['name'])->map(function ($items) {
            $first = $items->first();

            return [
                'code' => strtoupper($first['code']), 'name' => $first['name'],
                'hours' => round($items->sum('hours'), 2),
                'quantity' => round($items->sum(fn ($c) => (float) ($c['days'] ?? 0) + (float) ($c['quantity'] ?? 0)), 2),
                'amount' => round($items->sum('amount'), 2),
                'missing_rate' => $items->contains(fn ($c) => ! empty($c['reason']) || ($c['missing_rate'] ?? false)),
            ];
        })->values();
    }
}
