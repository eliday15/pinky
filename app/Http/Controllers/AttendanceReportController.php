<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\SystemSetting;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Discipline-focused attendance reports.
 *
 * Provides Faltas, Asistencia Perfecta, Retardos, and Salidas Tempranas
 * reports using configurable thresholds from SystemSettings.
 */
class AttendanceReportController extends Controller implements HasMiddleware
{
    /**
     * Get the middleware that should be assigned to the controller.
     */
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

    /**
     * Faltas report: absences from late >= threshold, no-shows, early departures, and retardo accumulation.
     */
    public function faltas(Request $request): Response
    {
        [$startDate, $endDate] = $this->getDateRange($request);
        $activeEmployeeIds = Employee::active()->pluck('id');

        $lateToAbsenceCount = (int) SystemSetting::get('late_to_absence_count', 6);
        $maxLateBeforeAbsence = (int) SystemSetting::get('max_late_minutes_before_absence', 60);
        $earlyDepartureThreshold = (int) SystemSetting::get('early_departure_absence_threshold', 30);
        $earlyDepartureIsAbsence = (bool) SystemSetting::get('early_departure_is_absence', true);

        // Direct faltas: all records with status 'absent' (covers late >= 60, no-shows, early departure >= threshold)
        $absentRecords = AttendanceRecord::with(['employee.department'])
            ->whereBetween('work_date', [$startDate, $endDate])
            ->whereIn('employee_id', $activeEmployeeIds)
            ->where('status', 'absent')
            ->get();

        // Retardo records for accumulated faltas
        $lateRecords = AttendanceRecord::whereBetween('work_date', [$startDate, $endDate])
            ->whereIn('employee_id', $activeEmployeeIds)
            ->where('status', 'late')
            ->get();

        // Calculate retardo-accumulated faltas per employee (monthly reset)
        $retardoFaltasByEmployee = [];
        foreach ($lateRecords->groupBy('employee_id') as $employeeId => $empRecords) {
            $byMonth = $empRecords->groupBy(fn ($r) => Carbon::parse($r->work_date)->format('Y-m'));
            $totalRetardoFaltas = 0;
            foreach ($byMonth as $monthRecords) {
                $totalRetardoFaltas += intdiv($monthRecords->count(), $lateToAbsenceCount);
            }
            if ($totalRetardoFaltas > 0) {
                $retardoFaltasByEmployee[$employeeId] = $totalRetardoFaltas;
            }
        }

        // Combine by employee
        $allEmployeeIds = $absentRecords->pluck('employee_id')
            ->merge(array_keys($retardoFaltasByEmployee))
            ->unique();

        $byEmployee = $allEmployeeIds->map(function ($employeeId) use ($absentRecords, $retardoFaltasByEmployee) {
            $empAbsent = $absentRecords->where('employee_id', $employeeId);
            $employee = $empAbsent->first()?->employee;

            // If employee only has retardo-faltas, load them
            if (!$employee) {
                $employee = Employee::with('department')->find($employeeId);
            }

            $directFaltas = $empAbsent->count();
            $retardoFaltas = $retardoFaltasByEmployee[$employeeId] ?? 0;

            return [
                'employee' => $employee,
                'direct_faltas' => $directFaltas,
                'retardo_faltas' => $retardoFaltas,
                'total_faltas' => $directFaltas + $retardoFaltas,
                'dates' => $empAbsent->pluck('work_date')->toArray(),
            ];
        })->sortByDesc('total_faltas')->values();

        $summary = [
            'total_faltas' => $byEmployee->sum('total_faltas'),
            'employees_with_faltas' => $byEmployee->count(),
            'direct_faltas' => $byEmployee->sum('direct_faltas'),
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

    /**
     * Asistencia perfecta report: employees who completed the period without issues.
     */
    public function asistencia(Request $request): Response
    {
        [$startDate, $endDate] = $this->getDateRange($request);

        $employees = Employee::with(['schedule', 'department'])->active()->get();

        $allRecords = AttendanceRecord::whereBetween('work_date', [$startDate, $endDate])
            ->whereIn('employee_id', $employees->pluck('id'))
            ->get();

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

            // Count expected working days in range
            $expectedDays = 0;
            $currentDate = $startDate->copy();
            while ($currentDate->lte($endDate)) {
                if (in_array($currentDate->englishDayOfWeek, $workingDays)) {
                    $expectedDays++;
                }
                $currentDate->addDay();
            }

            if ($expectedDays === 0) {
                continue;
            }

            $records = $allRecords->where('employee_id', $employee->id);

            // Excused days don't count against expected
            $excusedDays = $records->whereIn('status', ['holiday', 'vacation', 'sick_leave', 'permission'])->count();
            $adjustedExpected = $expectedDays - $excusedDays;

            if ($adjustedExpected <= 0) {
                continue;
            }

            // Perfect = present for all adjusted expected days, no late, no early departure
            $presentRecords = $records->where('status', 'present');
            $hasLate = $records->where('late_minutes', '>', 0)->isNotEmpty();
            $hasEarlyDeparture = $records->where('early_departure_minutes', '>', 0)->isNotEmpty();
            $hasAbsence = $records->where('status', 'absent')->isNotEmpty();

            if ($presentRecords->count() >= $adjustedExpected && !$hasLate && !$hasEarlyDeparture && !$hasAbsence) {
                $byEmployee->push([
                    'employee' => $employee,
                    'days_worked' => $presentRecords->count(),
                    'total_hours' => round($records->sum('worked_hours'), 2),
                ]);
            }
        }

        $totalActive = $employees->count();
        $perfectCount = $byEmployee->count();

        $summary = [
            'perfect_count' => $perfectCount,
            'total_active' => $totalActive,
            'percentage' => $totalActive > 0 ? round(($perfectCount / $totalActive) * 100, 1) : 0,
        ];

        return Inertia::render('Reports/Asistencia', [
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
            'byEmployee' => $byEmployee->values(),
            'summary' => $summary,
        ]);
    }

    /**
     * Retardos report: late arrivals under the absence threshold.
     */
    public function retardos(Request $request): Response
    {
        [$startDate, $endDate] = $this->getDateRange($request);
        $activeEmployeeIds = Employee::active()->pluck('id');
        $lateToAbsenceCount = (int) SystemSetting::get('late_to_absence_count', 6);

        $records = AttendanceRecord::with(['employee.department'])
            ->whereBetween('work_date', [$startDate, $endDate])
            ->whereIn('employee_id', $activeEmployeeIds)
            ->where('status', 'late')
            ->orderBy('late_minutes', 'desc')
            ->get();

        $byEmployee = $records->groupBy('employee_id')->map(function ($group) use ($lateToAbsenceCount) {
            $employee = $group->first()->employee;
            $lateCount = $group->count();

            return [
                'employee' => $employee,
                'late_count' => $lateCount,
                'total_late_minutes' => $group->sum('late_minutes'),
                'avg_late_minutes' => round($group->avg('late_minutes'), 0),
                'generates_falta' => $lateCount >= $lateToAbsenceCount,
                'dates' => $group->map(fn ($r) => [
                    'date' => $r->work_date,
                    'minutes' => $r->late_minutes,
                    'check_in' => $r->check_in,
                ])->toArray(),
            ];
        })->sortByDesc('late_count')->values();

        $faltaCount = $byEmployee->filter(fn ($e) => $e['generates_falta'])->count();

        $summary = [
            'total_retardos' => $records->count(),
            'employees_with_retardos' => $byEmployee->count(),
            'total_late_minutes' => $records->sum('late_minutes'),
            'avg_late_minutes' => $records->count() > 0 ? round($records->avg('late_minutes'), 0) : 0,
            'faltas_generated' => $faltaCount,
        ];

        return Inertia::render('Reports/Retardos', [
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
            'byEmployee' => $byEmployee,
            'summary' => $summary,
            'lateToAbsenceCount' => $lateToAbsenceCount,
        ]);
    }

    /**
     * Salidas tempranas report: employees who left before their scheduled end time.
     */
    public function earlyDepartures(Request $request): Response
    {
        [$startDate, $endDate] = $this->getDateRange($request);
        $activeEmployeeIds = Employee::active()->pluck('id');

        $earlyDepartureThreshold = (int) SystemSetting::get('early_departure_absence_threshold', 30);
        $earlyDepartureIsAbsence = (bool) SystemSetting::get('early_departure_is_absence', true);

        $records = AttendanceRecord::with(['employee.department'])
            ->whereBetween('work_date', [$startDate, $endDate])
            ->whereIn('employee_id', $activeEmployeeIds)
            ->where('early_departure_minutes', '>', 0)
            ->orderBy('early_departure_minutes', 'desc')
            ->get();

        $byEmployee = $records->groupBy('employee_id')->map(function ($group) use ($earlyDepartureThreshold, $earlyDepartureIsAbsence) {
            $employee = $group->first()->employee;

            return [
                'employee' => $employee,
                'departure_count' => $group->count(),
                'total_early_minutes' => $group->sum('early_departure_minutes'),
                'avg_early_minutes' => round($group->avg('early_departure_minutes'), 0),
                'faltas_count' => $earlyDepartureIsAbsence
                    ? $group->where('early_departure_minutes', '>=', $earlyDepartureThreshold)->count()
                    : 0,
                'dates' => $group->map(fn ($r) => [
                    'date' => $r->work_date,
                    'minutes' => $r->early_departure_minutes,
                    'check_out' => $r->check_out,
                    'is_falta' => $earlyDepartureIsAbsence && $r->early_departure_minutes >= $earlyDepartureThreshold,
                ])->toArray(),
            ];
        })->sortByDesc('departure_count')->values();

        $summary = [
            'total_early_departures' => $records->count(),
            'employees_with_early_departures' => $byEmployee->count(),
            'total_early_minutes' => $records->sum('early_departure_minutes'),
            'avg_early_minutes' => $records->count() > 0 ? round($records->avg('early_departure_minutes'), 0) : 0,
            'faltas_generated' => $byEmployee->sum('faltas_count'),
        ];

        return Inertia::render('Reports/SalidasTempranas', [
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
            'byEmployee' => $byEmployee,
            'summary' => $summary,
            'settings' => [
                'earlyThreshold' => $earlyDepartureThreshold,
                'earlyIsAbsence' => $earlyDepartureIsAbsence,
            ],
        ]);
    }

    /**
     * Get date range from request, defaulting to current week.
     *
     * @param Request $request HTTP request with optional start_date and end_date
     * @return array{0: Carbon, 1: Carbon} Start and end dates
     */
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
}
