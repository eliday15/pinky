<?php

namespace App\Services\Reports;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\Department;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the weekly overtime report DTO for a department.
 *
 * Reports only AUTHORIZED hours (Authorization with status approved/paid)
 * that are also backed by an actual attendance record (i.e. employee
 * checked in and out that day). Hours are classified by their attached
 * compensation_type.code:
 *
 *   - Daily extra hours cell  : HE + HED + HET (per_hour overtime)
 *   - FIN DE SEMANA hours     : FIN
 *   - VELADA marker (1/0)     : VEL
 *   - CENA marker (1/0)       : Cena
 *   - COMIDA marker (1/0)     : COM
 *
 * No fallback to unauthorized hours — if it's not authorized, it's not
 * shown, by design.
 */
class WeeklyOvertimeReportService
{
    /**
     * Compensation type codes that count as "horas extra" in daily cells.
     */
    private const OVERTIME_CODES = ['HE', 'HED', 'HET'];

    private const WEEKEND_CODE = 'FIN';

    private const VELADA_CODE = 'VEL';

    private const CENA_CODE = 'CENA';

    private const COMIDA_CODE = 'COM';

    /**
     * Build the report payload for a department and week.
     *
     * Args:
     *     department: The department to report on.
     *     weekStart: Any date in the target week (will be normalized to startOfWeek).
     *
     * Returns:
     *     Array with department, dates, rows and totals ready for templates.
     */
    public function buildReport(Department $department, Carbon $weekStart): array
    {
        $start = $weekStart->copy()->startOfWeek();
        $end = $start->copy()->endOfWeek();

        $dates = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $dates[] = $cursor->toDateString();
            $cursor->addDay();
        }

        $employees = Employee::with(['schedule', 'department'])
            ->where('department_id', $department->id)
            ->where('status', 'active')
            ->orderBy('full_name')
            ->get();

        $employeeIds = $employees->pluck('id');

        $records = AttendanceRecord::whereIn('employee_id', $employeeIds)
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->groupBy('employee_id');

        $authorizations = Authorization::with('compensationType')
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('status', [Authorization::STATUS_APPROVED, Authorization::STATUS_PAID])
            ->get()
            ->groupBy('employee_id');

        $rows = $employees->map(fn (Employee $employee) => $this->buildEmployeeRow(
            $employee,
            $dates,
            $records->get($employee->id, collect()),
            $authorizations->get($employee->id, collect()),
        ))->values()->all();

        $totals = $this->buildGrandTotals($rows);

        return [
            'department' => [
                'id' => $department->id,
                'name' => $department->name,
                'code' => strtoupper($department->code ?? ''),
            ],
            'week_start' => $start->toDateString(),
            'week_end' => $end->toDateString(),
            'dates' => $dates,
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    /**
     * Build the full row payload for a single employee.
     */
    private function buildEmployeeRow(
        Employee $employee,
        array $dates,
        Collection $records,
        Collection $authorizations,
    ): array {
        $recordsByDate = $records->keyBy(fn (AttendanceRecord $r) => $r->work_date->toDateString());
        $authsByDate = $authorizations->groupBy(fn (Authorization $a) => $a->date->toDateString());

        $days = [];
        $weeklyExtra = 0.0;
        $weeklyWeekend = 0.0;
        $veladaCount = 0;
        $cenaCount = 0;
        $comidaCount = 0;

        foreach ($dates as $date) {
            $record = $recordsByDate->get($date);
            $dayAuths = $authsByDate->get($date, collect());

            $day = $this->buildDay($date, $record, $dayAuths);

            $days[$date] = $day;
            $weeklyExtra += $day['overtime_hours'];
            $weeklyWeekend += $day['weekend_hours'];
            $veladaCount += $day['velada_marker'];
            $cenaCount += $day['cena_marker'];
            $comidaCount += $day['comida_marker'];
        }

        return [
            'employee' => [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
                'employee_number' => $employee->employee_number,
                'has_night_shift' => collect($days)->contains(fn ($d) => $d['is_night_shift']),
            ],
            'days' => $days,
            'totals' => [
                'total_hours' => round($weeklyExtra, 2),
                'weekend_hours' => round($weeklyWeekend, 2),
                'velada_count' => $veladaCount,
                'cena_count' => $cenaCount,
                'comida_count' => $comidaCount,
            ],
            'observations' => $this->buildObservations($records, $authorizations),
        ];
    }

    /**
     * Build per-day metrics, classifying authorized hours by compensation_type.code.
     *
     * Day with no check-in/check-out yields all zeros — we only count hours
     * that are both authorized AND backed by an actual checada.
     */
    private function buildDay(
        string $date,
        ?AttendanceRecord $record,
        Collection $dayAuthorizations,
    ): array {
        $dateObj = Carbon::parse($date);
        $isWeekendDate = $dateObj->isWeekend();

        $blank = [
            'date' => $date,
            'is_weekend_date' => $isWeekendDate,
            'overtime_hours' => 0.0,
            'velada_hours' => 0.0,
            'weekend_hours' => 0.0,
            'worked_hours' => 0.0,
            'is_night_shift' => false,
            'is_weekend_work' => false,
            'm_hours' => 0.0,
            'v_hours' => 0.0,
            'velada_marker' => 0,
            'cena_marker' => 0,
            'comida_marker' => 0,
        ];

        if (! $record || ! $record->check_in || ! $record->check_out) {
            return $blank;
        }

        $byCode = $dayAuthorizations->groupBy(fn (Authorization $a) => $this->normalizeCode($a->compensationType?->code));

        $overtimeHours = 0.0;
        foreach (self::OVERTIME_CODES as $code) {
            $overtimeHours += (float) $byCode->get($code, collect())->sum('hours');
        }

        $weekendHours = (float) $byCode->get(self::WEEKEND_CODE, collect())->sum('hours');
        $veladaHours = (float) $byCode->get(self::VELADA_CODE, collect())->sum('hours');

        $veladaMarker = $byCode->has(self::VELADA_CODE) ? 1 : 0;
        $cenaMarker = $byCode->has(self::CENA_CODE) ? 1 : 0;
        $comidaMarker = $byCode->has(self::COMIDA_CODE) ? 1 : 0;

        $isNightShift = (bool) $record->is_night_shift;
        $isWeekendWork = (bool) $record->is_weekend_work;

        // M/V split for DISEÑO: morning shift hours go to M, night shift to V.
        $mHours = $isNightShift ? 0.0 : $overtimeHours;
        $vHours = $isNightShift ? $overtimeHours : 0.0;

        return [
            'date' => $date,
            'is_weekend_date' => $isWeekendDate,
            'overtime_hours' => round($overtimeHours, 2),
            'velada_hours' => round($veladaHours, 2),
            'weekend_hours' => round($weekendHours, 2),
            'worked_hours' => round((float) ($record->worked_hours ?? 0), 2),
            'is_night_shift' => $isNightShift,
            'is_weekend_work' => $isWeekendWork,
            'm_hours' => round($mHours, 2),
            'v_hours' => round($vHours, 2),
            'velada_marker' => $veladaMarker,
            'cena_marker' => $cenaMarker,
            'comida_marker' => $comidaMarker,
        ];
    }

    /**
     * Normalize a compensation type code for matching (uppercased, trimmed).
     */
    private function normalizeCode(?string $code): string
    {
        return strtoupper(trim((string) $code));
    }

    /**
     * Concatenate observations from attendance notes + authorization reasons.
     */
    private function buildObservations(Collection $records, Collection $authorizations): string
    {
        $parts = [];

        foreach ($records as $record) {
            if (! empty($record->notes)) {
                $parts[] = trim($record->notes);
            }
        }

        foreach ($authorizations as $auth) {
            $reason = trim($auth->reason ?? '');
            if ($reason === '') {
                continue;
            }
            $label = $auth->compensationType?->name
                ?? match ($auth->type) {
                    Authorization::TYPE_NIGHT_SHIFT => 'Velada',
                    Authorization::TYPE_OVERTIME => 'Extra',
                    Authorization::TYPE_HOLIDAY_WORKED => 'Festivo',
                    Authorization::TYPE_SPECIAL => 'Especial',
                    default => 'Auth',
                };
            $parts[] = "{$label}: {$reason}";
        }

        $unique = array_values(array_unique(array_filter($parts)));

        return implode('; ', $unique);
    }

    /**
     * Sum totals across all rows.
     */
    private function buildGrandTotals(array $rows): array
    {
        $totalHours = 0.0;
        $weekendHours = 0.0;
        $veladaCount = 0;
        $cenaCount = 0;
        $comidaCount = 0;

        foreach ($rows as $row) {
            $totalHours += $row['totals']['total_hours'];
            $weekendHours += $row['totals']['weekend_hours'];
            $veladaCount += $row['totals']['velada_count'];
            $cenaCount += $row['totals']['cena_count'];
            $comidaCount += $row['totals']['comida_count'];
        }

        return [
            'total_hours' => round($totalHours, 2),
            'weekend_hours' => round($weekendHours, 2),
            'velada_count' => $veladaCount,
            'cena_count' => $cenaCount,
            'comida_count' => $comidaCount,
            'employee_count' => count($rows),
        ];
    }
}
