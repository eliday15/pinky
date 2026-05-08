<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\SystemSetting;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceReportController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(function ($request, $next) {
                $user = $request->user();
                if (!$user->hasPermissionTo('reports.view_all')
                    && !$user->hasPermissionTo('reports.view_team')
                    && !$user->hasPermissionTo('reports.view_own')) {
                    abort(403);
                }
                return $next($request);
            }),
        ];
    }

    public function faltas(Request $request): Response
    {
        [$startDate, $endDate] = $this->getDateRange($request);
        $activeEmployeeIds = Employee::active()->pluck('id');

        $lateToAbsenceCount = (int) SystemSetting::get('late_to_absence_count', 6);
        $maxLateBeforeAbsence = (int) SystemSetting::get('max_late_minutes_before_absence', 60);
        $earlyDepartureThreshold = (int) SystemSetting::get('early_departure_absence_threshold', 30);
        $earlyDepartureIsAbsence = (bool) SystemSetting::get('early_departure_is_absence', true);

        $employees = $this->getEmployeeLookup($activeEmployeeIds);
        $holidayDates = $this->getHolidayDates($startDate, $endDate);

        // Absent records — only fetch columns we need. Holidays never count
        // as faltas regardless of how the row was originally classified.
        $absentRows = DB::table('attendance_records')
            ->select('employee_id', 'work_date', 'check_in', 'late_minutes', 'early_departure_minutes')
            ->whereBetween('work_date', [$startDate, $endDate])
            ->whereIn('employee_id', $activeEmployeeIds)
            ->where('status', 'absent')
            ->when(! empty($holidayDates), fn ($q) => $q->whereNotIn('work_date', $holidayDates))
            ->get();

        $noShowByEmp = [];
        $thresholdByEmp = [];

        foreach ($absentRows as $row) {
            $eid = $row->employee_id;
            if (is_null($row->check_in)) {
                $noShowByEmp[$eid][] = ['date' => $row->work_date, 'label' => 'No se presentó'];
            } else {
                if (($row->late_minutes ?? 0) >= $maxLateBeforeAbsence) {
                    $label = "Retardo excesivo ({$row->late_minutes} min)";
                } elseif ($earlyDepartureIsAbsence && ($row->early_departure_minutes ?? 0) >= $earlyDepartureThreshold) {
                    $label = "Salida temprana ({$row->early_departure_minutes} min)";
                } else {
                    $label = 'Por umbral';
                }
                $thresholdByEmp[$eid][] = ['date' => $row->work_date, 'label' => $label];
            }
        }

        // Late counts per employee per month for retardo-accumulated faltas.
        // Lates that fall on a holiday don't accumulate toward a generated falta.
        $lateCounts = DB::table('attendance_records')
            ->select('employee_id', DB::raw("DATE_FORMAT(work_date, '%Y-%m') as month"), DB::raw('COUNT(*) as cnt'))
            ->whereBetween('work_date', [$startDate, $endDate])
            ->whereIn('employee_id', $activeEmployeeIds)
            ->where('status', 'late')
            ->when(! empty($holidayDates), fn ($q) => $q->whereNotIn('work_date', $holidayDates))
            ->groupBy('employee_id', DB::raw("DATE_FORMAT(work_date, '%Y-%m')"))
            ->get();

        $retardoFaltasByEmployee = [];
        $retardoDetailsByEmployee = [];
        foreach ($lateCounts as $row) {
            $faltas = intdiv($row->cnt, $lateToAbsenceCount);
            if ($faltas > 0) {
                $retardoFaltasByEmployee[$row->employee_id] = ($retardoFaltasByEmployee[$row->employee_id] ?? 0) + $faltas;
                $retardoDetailsByEmployee[$row->employee_id][] = [
                    'month' => $row->month,
                    'late_count' => $row->cnt,
                    'faltas' => $faltas,
                ];
            }
        }

        // Combine all employee IDs
        $allEmployeeIds = collect(array_keys($noShowByEmp))
            ->merge(array_keys($thresholdByEmp))
            ->merge(array_keys($retardoFaltasByEmployee))
            ->unique();

        $byEmployee = $allEmployeeIds->map(function ($eid) use ($noShowByEmp, $thresholdByEmp, $retardoFaltasByEmployee, $retardoDetailsByEmployee, $employees) {
            $noShowDates = $noShowByEmp[$eid] ?? [];
            $thresholdDates = $thresholdByEmp[$eid] ?? [];
            $noShowFaltas = count($noShowDates);
            $thresholdFaltas = count($thresholdDates);
            $retardoFaltas = $retardoFaltasByEmployee[$eid] ?? 0;

            return [
                'employee' => $employees[$eid] ?? null,
                'no_show_faltas' => $noShowFaltas,
                'threshold_faltas' => $thresholdFaltas,
                'direct_faltas' => $noShowFaltas + $thresholdFaltas,
                'retardo_faltas' => $retardoFaltas,
                'total_faltas' => $noShowFaltas + $thresholdFaltas + $retardoFaltas,
                'no_show_dates' => $noShowDates,
                'threshold_dates' => $thresholdDates,
                'retardo_details' => $retardoDetailsByEmployee[$eid] ?? [],
            ];
        })->filter(fn ($e) => $e['employee'] !== null)
            ->sortByDesc('total_faltas')
            ->values();

        $summary = [
            'total_faltas' => $byEmployee->sum('total_faltas'),
            'employees_with_faltas' => $byEmployee->count(),
            'direct_faltas' => $byEmployee->sum('direct_faltas'),
            'no_show_faltas' => $byEmployee->sum('no_show_faltas'),
            'threshold_faltas' => $byEmployee->sum('threshold_faltas'),
            'retardo_faltas' => $byEmployee->sum('retardo_faltas'),
        ];

        return Inertia::render('Reports/Faltas', [
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
            'byEmployee' => $byEmployee,
            'summary' => $summary,
            'settings' => [
                'maxLate' => $maxLateBeforeAbsence,
                'earlyThreshold' => $earlyDepartureThreshold,
                'earlyIsAbsence' => $earlyDepartureIsAbsence,
                'lateToAbsence' => $lateToAbsenceCount,
            ],
        ]);
    }

    public function asistencia(Request $request): Response
    {
        [$startDate, $endDate] = $this->getDateRange($request);

        $employees = Employee::with(['schedule', 'department'])
            ->active()
            ->select('id', 'employee_number', 'full_name', 'department_id', 'schedule_id', 'schedule_overrides')
            ->get();

        $allRecords = AttendanceRecord::select('employee_id', 'work_date', 'status', 'worked_hours', 'late_minutes', 'early_departure_minutes')
            ->whereBetween('work_date', [$startDate, $endDate])
            ->whereIn('employee_id', $employees->pluck('id'))
            ->get();

        $holidayDates = $this->getHolidayDates($startDate, $endDate);

        $byEmployee = collect();

        foreach ($employees as $employee) {
            $effectiveSchedule = $employee->getEffectiveSchedule();
            if (!$effectiveSchedule) {
                continue;
            }

            $workingDays = $effectiveSchedule->working_days ?? [];
            if (empty($workingDays)) {
                continue;
            }

            $expectedDays = 0;
            $currentDate = $startDate->copy();
            while ($currentDate->lte($endDate)) {
                if (in_array($currentDate->englishDayOfWeek, $workingDays)
                    && ! in_array($currentDate->toDateString(), $holidayDates)) {
                    $expectedDays++;
                }
                $currentDate->addDay();
            }

            if ($expectedDays === 0) {
                continue;
            }

            $records = $allRecords->where('employee_id', $employee->id);
            // A record on a holiday date is excused regardless of status,
            // so a stale 'absent' row on a now-registered holiday doesn't break perfect attendance.
            $excusedDays = $records->filter(fn ($r) => in_array($r->status, ['holiday', 'vacation', 'sick_leave', 'permission'])
                || in_array(Carbon::parse($r->work_date)->toDateString(), $holidayDates))->count();
            $adjustedExpected = $expectedDays - $excusedDays;

            if ($adjustedExpected <= 0) {
                continue;
            }

            // Filter out holiday-dated rows so a stale absent/late on a holiday can't break the streak.
            $nonHolidayRecords = $records->filter(fn ($r) => ! in_array(Carbon::parse($r->work_date)->toDateString(), $holidayDates));
            $presentRecords = $nonHolidayRecords->where('status', 'present');
            $hasLate = $nonHolidayRecords->where('late_minutes', '>', 0)->isNotEmpty();
            $hasEarlyDeparture = $nonHolidayRecords->where('early_departure_minutes', '>', 0)->isNotEmpty();
            $hasAbsence = $nonHolidayRecords->where('status', 'absent')->isNotEmpty();

            if ($presentRecords->count() >= $adjustedExpected && !$hasLate && !$hasEarlyDeparture && !$hasAbsence) {
                $byEmployee->push([
                    'employee' => [
                        'id' => $employee->id,
                        'employee_number' => $employee->employee_number,
                        'full_name' => $employee->full_name,
                        'department' => $employee->department ? ['name' => $employee->department->name] : null,
                    ],
                    'days_worked' => $presentRecords->count(),
                    'total_hours' => round($records->sum('worked_hours'), 2),
                ]);
            }
        }

        $totalActive = $employees->count();
        $perfectCount = $byEmployee->count();

        return Inertia::render('Reports/Asistencia', [
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
            'byEmployee' => $byEmployee->values(),
            'summary' => [
                'perfect_count' => $perfectCount,
                'total_active' => $totalActive,
                'percentage' => $totalActive > 0 ? round(($perfectCount / $totalActive) * 100, 1) : 0,
            ],
        ]);
    }

    public function retardos(Request $request): Response
    {
        [$startDate, $endDate] = $this->getDateRange($request);
        $activeEmployeeIds = Employee::active()->pluck('id');
        $lateToAbsenceCount = (int) SystemSetting::get('late_to_absence_count', 6);

        $employees = $this->getEmployeeLookup($activeEmployeeIds);
        $holidayDates = $this->getHolidayDates($startDate, $endDate);

        $rows = DB::table('attendance_records')
            ->select('employee_id', 'work_date', 'late_minutes', 'check_in')
            ->whereBetween('work_date', [$startDate, $endDate])
            ->whereIn('employee_id', $activeEmployeeIds)
            ->where('status', 'late')
            ->when(! empty($holidayDates), fn ($q) => $q->whereNotIn('work_date', $holidayDates))
            ->orderBy('late_minutes', 'desc')
            ->get();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->employee_id][] = $row;
        }

        $totalRetardos = $rows->count();
        $totalLateMinutes = $rows->sum('late_minutes');

        $byEmployee = collect($grouped)->map(function ($records, $eid) use ($lateToAbsenceCount, $employees) {
            $lateCount = count($records);
            $totalMinutes = array_sum(array_column($records, 'late_minutes'));

            return [
                'employee' => $employees[$eid] ?? null,
                'late_count' => $lateCount,
                'total_late_minutes' => $totalMinutes,
                'avg_late_minutes' => $lateCount > 0 ? round($totalMinutes / $lateCount) : 0,
                'generates_falta' => $lateCount >= $lateToAbsenceCount,
                'dates' => array_map(fn ($r) => [
                    'date' => $r->work_date,
                    'minutes' => $r->late_minutes,
                    'check_in' => $r->check_in,
                ], $records),
            ];
        })->filter(fn ($e) => $e['employee'] !== null)
            ->sortByDesc('late_count')
            ->values();

        $faltaCount = $byEmployee->filter(fn ($e) => $e['generates_falta'])->count();

        return Inertia::render('Reports/Retardos', [
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
            'byEmployee' => $byEmployee,
            'summary' => [
                'total_retardos' => $totalRetardos,
                'employees_with_retardos' => $byEmployee->count(),
                'total_late_minutes' => $totalLateMinutes,
                'avg_late_minutes' => $totalRetardos > 0 ? round($totalLateMinutes / $totalRetardos) : 0,
                'faltas_generated' => $faltaCount,
            ],
            'lateToAbsenceCount' => $lateToAbsenceCount,
        ]);
    }

    public function earlyDepartures(Request $request): Response
    {
        [$startDate, $endDate] = $this->getDateRange($request);
        $activeEmployeeIds = Employee::active()->pluck('id');

        $earlyDepartureThreshold = (int) SystemSetting::get('early_departure_absence_threshold', 30);
        $earlyDepartureIsAbsence = (bool) SystemSetting::get('early_departure_is_absence', true);

        $employees = $this->getEmployeeLookup($activeEmployeeIds);
        $holidayDates = $this->getHolidayDates($startDate, $endDate);

        $rows = DB::table('attendance_records')
            ->select('employee_id', 'work_date', 'early_departure_minutes', 'check_out')
            ->whereBetween('work_date', [$startDate, $endDate])
            ->whereIn('employee_id', $activeEmployeeIds)
            ->where('early_departure_minutes', '>', 0)
            ->when(! empty($holidayDates), fn ($q) => $q->whereNotIn('work_date', $holidayDates))
            ->orderBy('early_departure_minutes', 'desc')
            ->get();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->employee_id][] = $row;
        }

        $totalEarlyDepartures = $rows->count();
        $totalEarlyMinutes = $rows->sum('early_departure_minutes');

        $byEmployee = collect($grouped)->map(function ($records, $eid) use ($earlyDepartureThreshold, $earlyDepartureIsAbsence, $employees) {
            $count = count($records);
            $totalMinutes = array_sum(array_column($records, 'early_departure_minutes'));
            $faltasCount = $earlyDepartureIsAbsence
                ? count(array_filter($records, fn ($r) => $r->early_departure_minutes >= $earlyDepartureThreshold))
                : 0;

            return [
                'employee' => $employees[$eid] ?? null,
                'departure_count' => $count,
                'total_early_minutes' => $totalMinutes,
                'avg_early_minutes' => $count > 0 ? round($totalMinutes / $count) : 0,
                'faltas_count' => $faltasCount,
                'dates' => array_map(fn ($r) => [
                    'date' => $r->work_date,
                    'minutes' => $r->early_departure_minutes,
                    'check_out' => $r->check_out,
                    'is_falta' => $earlyDepartureIsAbsence && $r->early_departure_minutes >= $earlyDepartureThreshold,
                ], $records),
            ];
        })->filter(fn ($e) => $e['employee'] !== null)
            ->sortByDesc('departure_count')
            ->values();

        return Inertia::render('Reports/SalidasTempranas', [
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
            'byEmployee' => $byEmployee,
            'summary' => [
                'total_early_departures' => $totalEarlyDepartures,
                'employees_with_early_departures' => $byEmployee->count(),
                'total_early_minutes' => $totalEarlyMinutes,
                'avg_early_minutes' => $totalEarlyDepartures > 0 ? round($totalEarlyMinutes / $totalEarlyDepartures) : 0,
                'faltas_generated' => $byEmployee->sum('faltas_count'),
            ],
            'settings' => [
                'earlyThreshold' => $earlyDepartureThreshold,
                'earlyIsAbsence' => $earlyDepartureIsAbsence,
            ],
        ]);
    }

    /**
     * Return DOF + Yom Tov holiday dates (as YYYY-MM-DD strings) within range.
     * Used to exclude holidays from absence/late/early-departure counts.
     */
    private function getHolidayDates(Carbon $startDate, Carbon $endDate): array
    {
        return Holiday::whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->all();
    }

    private function getDateRange(Request $request): array
    {
        $startDate = $request->start_date
            ? Carbon::parse($request->start_date)
            : Carbon::now()->startOfWeek();
        $endDate = $request->end_date
            ? Carbon::parse($request->end_date)
            : Carbon::now()->endOfWeek();

        return [$startDate, $endDate];
    }

    /**
     * Build a lightweight employee lookup keyed by ID.
     * Only includes fields needed for report display.
     */
    private function getEmployeeLookup($employeeIds): array
    {
        return Employee::with('department:id,name')
            ->select('id', 'employee_number', 'full_name', 'department_id')
            ->whereIn('id', $employeeIds)
            ->get()
            ->mapWithKeys(fn ($e) => [
                $e->id => [
                    'id' => $e->id,
                    'employee_number' => $e->employee_number,
                    'full_name' => $e->full_name,
                    'department' => $e->department ? ['id' => $e->department->id, 'name' => $e->department->name] : null,
                ],
            ])
            ->toArray();
    }
}
