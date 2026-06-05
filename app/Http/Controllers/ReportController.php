<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesReportEmployees;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Incident;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Services\LateAbsenceService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller implements HasMiddleware
{
    use ScopesReportEmployees;

    /**
     * Get the middleware that should be assigned to the controller.
     */
    public static function middleware(): array
    {
        return [
            new Middleware(function ($request, $next) {
                $user = $request->user();
                if (! $user->hasPermissionTo('reports.view_all')
                    && ! $user->hasPermissionTo('reports.view_team')
                    && ! $user->hasPermissionTo('reports.view_own')) {
                    abort(403);
                }

                return $next($request);
            }),
        ];
    }

    /**
     * Reports index / menu.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Reports/Index', [
            // Null-safe like ScopesReportEmployees: real requests always carry a
            // user (auth middleware), but the reports:audit smoke test invokes this
            // without one, and an unscoped 'own' default is the safe fallback.
            'scope' => match (true) {
                $user?->hasPermissionTo('reports.view_all') => 'all',
                $user?->hasPermissionTo('reports.view_team') => 'team',
                default => 'own',
            },
        ]);
    }

    /**
     * Daily attendance report.
     */
    public function daily(Request $request): Response
    {
        $date = $request->date ? Carbon::parse($request->date) : Carbon::today();

        // Only include active employees
        $activeEmployeeIds = $this->scopedActiveEmployeeIds();

        $records = AttendanceRecord::with(['employee.department', 'employee.schedule'])
            ->where('work_date', $date->toDateString())
            ->whereIn('employee_id', $activeEmployeeIds)
            ->get();

        $summary = [
            'total' => $records->count(),
            'present' => $records->where('status', 'present')->count(),
            'late' => $records->where('status', 'late')->count(),
            'absent' => $records->where('status', 'absent')->count(),
            'partial' => $records->where('status', 'partial')->count(),
            'vacation' => $records->where('status', 'vacation')->count(),
            'sick_leave' => $records->where('status', 'sick_leave')->count(),
        ];

        $byDepartment = $records->groupBy(fn ($r) => $r->employee?->department?->name ?? 'Sin Departamento')->map(function ($group) {
            return [
                'total' => $group->count(),
                'present' => $group->whereIn('status', ['present', 'late'])->count(),
                'absent' => $group->where('status', 'absent')->count(),
                'total_hours' => $group->sum('worked_hours'),
                'overtime_hours' => $group->sum('overtime_hours'),
            ];
        });

        return Inertia::render('Reports/Daily', [
            'date' => $date->toDateString(),
            'records' => $records,
            'summary' => $summary,
            'byDepartment' => $byDepartment,
        ]);
    }

    /**
     * Weekly attendance summary.
     */
    public function weekly(Request $request): Response
    {
        $startDate = $request->start_date
            ? Carbon::parse($request->start_date)->startOfWeek()
            : Carbon::now()->startOfWeek();

        $endDate = $startDate->copy()->endOfWeek();

        // Only include active employees
        $activeEmployeeIds = $this->scopedActiveEmployeeIds();

        $records = AttendanceRecord::with(['employee.department'])
            ->whereBetween('work_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->whereIn('employee_id', $activeEmployeeIds)
            ->get();

        $byEmployee = $records->groupBy('employee_id')->map(function ($group) {
            $employee = $group->first()->employee;

            return [
                'employee' => $employee,
                'days_worked' => $group->whereIn('status', ['present', 'late', 'partial'])->count(),
                'days_absent' => $group->where('status', 'absent')->count(),
                'days_late' => $group->where('status', 'late')->count(),
                'total_hours' => $group->sum('worked_hours'),
                'overtime_hours' => $group->sum('overtime_hours'),
                'late_minutes' => $group->sum('late_minutes'),
            ];
        })->values();

        $summary = [
            'total_employees' => $byEmployee->count(),
            'total_hours' => $records->sum('worked_hours'),
            'total_overtime' => $records->sum('overtime_hours'),
            'total_absences' => $records->where('status', 'absent')->count(),
            'total_late' => $records->where('status', 'late')->count(),
        ];

        return Inertia::render('Reports/Weekly', [
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
            'byEmployee' => $byEmployee,
            'summary' => $summary,
        ]);
    }

    /**
     * Monthly summary report.
     */
    public function monthly(Request $request): Response
    {
        $month = $request->month ? Carbon::parse($request->month.'-01') : Carbon::now()->startOfMonth();
        $startDate = $month->copy()->startOfMonth();
        $endDate = $month->copy()->endOfMonth();

        // Only include active employees
        $activeEmployeeIds = $this->scopedActiveEmployeeIds();

        $records = AttendanceRecord::with(['employee.department'])
            ->whereBetween('work_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->whereIn('employee_id', $activeEmployeeIds)
            ->get();

        $incidents = Incident::with(['employee', 'incidentType'])
            ->whereIn('employee_id', $activeEmployeeIds)
            ->where('status', 'approved')
            ->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('start_date', [$startDate, $endDate])
                    ->orWhereBetween('end_date', [$startDate, $endDate])
                    // Incidencias que envuelven el mes completo (empiezan
                    // antes y terminan después) — mismo criterio que nómina.
                    ->orWhere(function ($q2) use ($startDate, $endDate) {
                        $q2->where('start_date', '<=', $startDate)
                            ->where('end_date', '>=', $endDate);
                    });
            })
            ->get();

        // Días PRORRATEADOS al mes con el count_mode del tipo (auditoría #86):
        // una vacación 28 jun–4 jul ya no aparece con 7 días en junio, y las
        // incapacidades cuentan calendario igual que la nómina.
        $holidayDates = Holiday::whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->all();

        $byEmployee = $records->groupBy('employee_id')->map(function ($group) use ($incidents, $startDate, $endDate, $holidayDates) {
            $employee = $group->first()->employee;
            $empIncidents = $incidents->where('employee_id', $employee->id);

            $vacationDays = $empIncidents->filter(fn ($i) => $i->incidentType?->category === 'vacation')
                ->sum(fn ($i) => $i->overlapDays($startDate, $endDate, $employee, $holidayDates));
            $sickDays = $empIncidents->filter(fn ($i) => $i->incidentType?->category === 'sick_leave')
                ->sum(fn ($i) => $i->overlapDays($startDate, $endDate, $employee, $holidayDates));

            return [
                'employee' => $employee,
                'days_worked' => $group->whereIn('status', ['present', 'late', 'partial'])->count(),
                'days_absent' => $group->where('status', 'absent')->count(),
                'days_late' => $group->where('status', 'late')->count(),
                'total_hours' => round($group->sum('worked_hours'), 2),
                'overtime_hours' => round($group->sum('overtime_hours'), 2),
                'vacation_days' => $vacationDays,
                'sick_days' => $sickDays,
            ];
        })->values();

        $byDepartment = $records->groupBy(fn ($r) => $r->employee?->department?->name ?? 'Sin Departamento')->map(function ($group) {
            return [
                'employees' => $group->pluck('employee_id')->unique()->count(),
                'total_hours' => round($group->sum('worked_hours'), 2),
                'overtime_hours' => round($group->sum('overtime_hours'), 2),
                'absences' => $group->where('status', 'absent')->count(),
            ];
        });

        // Totales con el mismo prorrateo por solape/count_mode que arriba.
        $totalVacationDays = $incidents->filter(fn ($i) => $i->incidentType?->category === 'vacation')
            ->sum(fn ($i) => $i->employee ? $i->overlapDays($startDate, $endDate, $i->employee, $holidayDates) : 0);
        $totalSickDays = $incidents->filter(fn ($i) => $i->incidentType?->category === 'sick_leave')
            ->sum(fn ($i) => $i->employee ? $i->overlapDays($startDate, $endDate, $i->employee, $holidayDates) : 0);

        $summary = [
            'total_employees' => $byEmployee->count(),
            'total_hours' => round($records->sum('worked_hours'), 2),
            'total_overtime' => round($records->sum('overtime_hours'), 2),
            'total_absences' => $records->where('status', 'absent')->count(),
            'total_vacation_days' => $totalVacationDays,
            'total_sick_days' => $totalSickDays,
        ];

        return Inertia::render('Reports/Monthly', [
            'month' => $month->format('Y-m'),
            'monthName' => $month->translatedFormat('F Y'),
            'byEmployee' => $byEmployee,
            'byDepartment' => $byDepartment,
            'summary' => $summary,
        ]);
    }

    /**
     * Payroll summary report.
     */
    public function payroll(Request $request): Response
    {
        $periodId = $request->period;

        // Solo periodos cerrados (aprobados/pagados): un periodo en borrador o
        // en cálculo no es nómina válida y no debe reportarse como tal (mismo
        // criterio que payrollTrends).
        $periods = PayrollPeriod::whereIn('status', ['approved', 'paid'])
            ->orderBy('start_date', 'desc')
            ->take(12)
            ->get(['id', 'name', 'start_date', 'end_date', 'status']);

        $selectedPeriod = null;
        $entries = collect();
        $summary = null;

        if ($periodId) {
            $selectedPeriod = PayrollPeriod::whereIn('status', ['approved', 'paid'])->find($periodId);

            if ($selectedPeriod) {
                $entries = PayrollEntry::with(['employee.department'])
                    ->where('payroll_period_id', $periodId)
                    ->whereIn('employee_id', $this->scopedActiveEmployeeIds())
                    ->orderBy('net_pay', 'desc')
                    ->get();

                $summary = [
                    'total_employees' => $entries->count(),
                    'total_gross' => $entries->sum('gross_pay'),
                    'total_net' => $entries->sum('net_pay'),
                    'total_overtime' => $entries->sum('overtime_pay'),
                    'total_deductions' => $entries->sum('deductions'),
                    'avg_pay' => $entries->count() > 0 ? $entries->sum('net_pay') / $entries->count() : 0,
                ];
            }
        }

        return Inertia::render('Reports/Payroll', [
            'periods' => $periods,
            'selectedPeriod' => $selectedPeriod,
            'entries' => $entries,
            'summary' => $summary,
        ]);
    }

    /**
     * Overtime report.
     */
    public function overtime(Request $request): Response
    {
        $startDate = $request->start_date ? Carbon::parse($request->start_date) : Carbon::now()->startOfMonth();
        $endDate = $request->end_date ? Carbon::parse($request->end_date) : Carbon::now()->endOfMonth();

        $activeEmployeeIds = $this->scopedActiveEmployeeIds();

        // Detectadas vs autorizadas: la nómina solo paga las horas
        // AUTORIZADAS (overtime_authorized_hours, ya redondeadas con la
        // escalera de la empresa). El reporte muestra ambas columnas para
        // conciliar, y el costo estimado se calcula sobre lo autorizado con
        // la tarifa del empleado — nunca sobre horas crudas sin autorizar.
        $records = AttendanceRecord::with(['employee.department'])
            ->whereIn('employee_id', $activeEmployeeIds)
            ->whereBetween('work_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->where(function ($q) {
                $q->where('overtime_hours', '>', 0)
                    ->orWhere('overtime_authorized_hours', '>', 0);
            })
            ->get();

        $byEmployee = $records->groupBy('employee_id')->map(function ($group) {
            $employee = $group->first()->employee;
            $hourlyRate = (float) ($employee?->hourly_rate ?? 0);
            $overtimeMultiplier = (float) ($employee?->overtime_rate ?? 1.5);
            $authorizedHours = round($group->sum('overtime_authorized_hours'), 2);

            return [
                'employee' => $employee,
                'days_with_overtime' => $group->count(),
                'total_overtime' => round($group->sum('overtime_hours'), 2),
                'total_authorized' => $authorizedHours,
                'estimated_cost' => round($authorizedHours * $hourlyRate * $overtimeMultiplier, 2),
            ];
        })->sortByDesc('total_overtime')->values();

        $summary = [
            'total_employees' => $byEmployee->count(),
            'total_overtime_hours' => round($records->sum('overtime_hours'), 2),
            'total_authorized_hours' => round($records->sum('overtime_authorized_hours'), 2),
            'total_days_with_overtime' => $records->count(),
        ];

        return Inertia::render('Reports/Overtime', [
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
            'byEmployee' => $byEmployee,
            'summary' => $summary,
        ]);
    }

    /**
     * Absences report.
     */
    public function absences(Request $request): Response
    {
        $startDate = $request->start_date ? Carbon::parse($request->start_date) : Carbon::now()->startOfMonth();
        $endDate = $request->end_date ? Carbon::parse($request->end_date) : Carbon::now()->endOfMonth();

        $activeEmployeeIds = $this->scopedActiveEmployeeIds();

        $holidayDates = Holiday::whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->all();

        $records = AttendanceRecord::with(['employee.department', 'employee.schedule:id,working_days'])
            ->whereIn('employee_id', $activeEmployeeIds)
            ->whereBetween('work_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->where('status', 'absent')
            ->when(! empty($holidayDates), fn ($q) => $q->whereNotIn('work_date', $holidayDates))
            ->get()
            // Drop absences on days the employee isn't scheduled to work
            // (e.g. a stale absent row on Saturday for a Mon-Fri employee).
            ->filter(function ($r) {
                if (! $r->employee) {
                    return false;
                }
                $dayName = Carbon::parse($r->work_date)->englishDayOfWeek;

                return $r->employee->isEffectiveWorkingDay($dayName);
            })
            ->values();

        // Use whereHas for relationship filtering
        $incidents = Incident::with(['employee.department', 'incidentType'])
            ->whereIn('employee_id', $activeEmployeeIds)
            ->where('status', 'approved')
            ->whereHas('incidentType', function ($q) {
                $q->whereIn('category', ['absence', 'late_accumulation']);
            })
            ->whereBetween('start_date', [$startDate, $endDate])
            ->get();

        $byEmployee = $records->groupBy('employee_id')->map(function ($group) {
            $employee = $group->first()->employee;

            return [
                'employee' => $employee,
                'absence_days' => $group->count(),
                'dates' => $group->pluck('work_date')->toArray(),
            ];
        })->sortByDesc('absence_days')->values();

        $summary = [
            'total_absence_records' => $records->count(),
            'employees_with_absences' => $byEmployee->count(),
            'incident_absences' => $incidents->sum('days_count'),
        ];

        return Inertia::render('Reports/Absences', [
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
            'byEmployee' => $byEmployee,
            'summary' => $summary,
        ]);
    }

    /**
     * Late arrivals report.
     */
    public function lateArrivals(Request $request): Response
    {
        $startDate = $request->start_date ? Carbon::parse($request->start_date) : Carbon::now()->startOfMonth();
        $endDate = $request->end_date ? Carbon::parse($request->end_date) : Carbon::now()->endOfMonth();

        $activeEmployeeIds = $this->scopedActiveEmployeeIds();

        $records = AttendanceRecord::with(['employee.department', 'employee.schedule'])
            ->whereIn('employee_id', $activeEmployeeIds)
            ->whereBetween('work_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->where('status', 'late')
            ->orderBy('late_minutes', 'desc')
            ->get();

        // Retardos cubiertos por una incidencia aprobada que justifica no se
        // reportan — la misma regla que faltas/retardos y nómina (auditoría #3).
        $justifiedDates = Incident::justifiedDatesByEmployee(
            $activeEmployeeIds,
            $startDate->toDateString(),
            $endDate->toDateString()
        );
        $records = $records->reject(
            fn ($r) => isset($justifiedDates[$r->employee_id][Carbon::parse($r->work_date)->toDateString()])
        )->values();

        $byEmployee = $records->groupBy('employee_id')->map(function ($group) {
            $employee = $group->first()->employee;

            return [
                'employee' => $employee,
                'late_count' => $group->count(),
                'total_late_minutes' => $group->sum('late_minutes'),
                'avg_late_minutes' => round($group->avg('late_minutes'), 0),
                'dates' => $group->map(fn ($r) => [
                    'date' => $r->work_date,
                    'minutes' => $r->late_minutes,
                    'check_in' => $r->check_in,
                ])->toArray(),
            ];
        })->sortByDesc('late_count')->values();

        // Empleados en zona crítica: umbral configurable (el mismo que usa la
        // regla mensual retardos→falta), nunca hardcodeado.
        $lateToAbsenceCount = app(LateAbsenceService::class)->threshold();
        $criticalCount = $byEmployee->filter(fn ($e) => $e['late_count'] >= $lateToAbsenceCount)->count();

        $summary = [
            'total_late_records' => $records->count(),
            'employees_with_lates' => $byEmployee->count(),
            'total_late_minutes' => $records->sum('late_minutes'),
            'avg_late_minutes' => $records->count() > 0 ? round($records->avg('late_minutes'), 0) : 0,
            'critical_employees' => $criticalCount,
            'late_to_absence_threshold' => $lateToAbsenceCount,
        ];

        return Inertia::render('Reports/LateArrivals', [
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
            'byEmployee' => $byEmployee,
            'summary' => $summary,
        ]);
    }

    /**
     * Vacation balance report.
     */
    public function vacationBalance(Request $request): Response
    {
        $departmentId = $request->department;

        $query = Employee::with(['department', 'position'])
            ->active()
            ->whereIn('id', $this->scopedActiveEmployeeIds())
            ->orderBy('full_name');

        if ($departmentId) {
            $query->where('department_id', $departmentId);
        }

        $employees = $query->get()->map(function ($employee) {
            $entitled = $employee->vacation_days_entitled ?? 0;
            $used = $employee->vacation_days_used ?? 0;
            // Saldo vía el accessor del modelo: resta también los días ya
            // APARTADOS (vacation_days_reserved) — auditoría #83: el reporte
            // mostraba más saldo del realmente disponible.
            $available = $employee->vacation_days_remaining;
            $percentage = $entitled > 0 ? round(($used / $entitled) * 100, 0) : 0;

            return [
                'employee' => $employee,
                'entitled' => $entitled,
                'used' => $used,
                'reserved' => $employee->vacation_days_reserved ?? 0,
                'available' => $available,
                'percentage' => $percentage,
            ];
        });

        $departments = Department::orderBy('name')->get(['id', 'name']);

        $summary = [
            'total_employees' => $employees->count(),
            'total_entitled' => $employees->sum('entitled'),
            'total_used' => $employees->sum('used'),
            'total_available' => $employees->sum('available'),
            'avg_usage_percentage' => $employees->count() > 0 ? round($employees->avg('percentage'), 0) : 0,
        ];

        return Inertia::render('Reports/VacationBalance', [
            'employees' => $employees,
            'departments' => $departments,
            'selectedDepartment' => $departmentId,
            'summary' => $summary,
        ]);
    }

    /**
     * Department comparison report.
     */
    public function departmentComparison(Request $request): Response
    {
        $startDate = $request->start_date ? Carbon::parse($request->start_date) : Carbon::now()->startOfMonth();
        $endDate = $request->end_date ? Carbon::parse($request->end_date) : Carbon::now()->endOfMonth();

        $activeEmployeeIds = $this->scopedActiveEmployeeIds();

        $records = AttendanceRecord::with(['employee.department'])
            ->whereIn('employee_id', $activeEmployeeIds)
            ->whereBetween('work_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->get();

        $departments = $records->groupBy(fn ($r) => $r->employee?->department?->name ?? 'Sin Departamento')->map(function ($group, $deptName) {
            $uniqueEmployees = $group->pluck('employee_id')->unique()->count();
            $totalDays = $group->count();
            $workedDays = $group->whereIn('status', ['present', 'late', 'partial'])->count();
            $absentDays = $group->where('status', 'absent')->count();
            $lateDays = $group->where('status', 'late')->count();

            return [
                'name' => $deptName ?: 'Sin Departamento',
                'employee_count' => $uniqueEmployees,
                'total_records' => $totalDays,
                'worked_days' => $workedDays,
                'absent_days' => $absentDays,
                'late_days' => $lateDays,
                'total_hours' => round($group->sum('worked_hours'), 2),
                'overtime_hours' => round($group->sum('overtime_hours'), 2),
                'late_minutes' => $group->sum('late_minutes'),
                'attendance_rate' => $totalDays > 0 ? round(($workedDays / $totalDays) * 100, 1) : 0,
                'punctuality_rate' => $workedDays > 0 ? round((($workedDays - $lateDays) / $workedDays) * 100, 1) : 0,
            ];
        })->sortByDesc('employee_count')->values();

        $summary = [
            'total_departments' => $departments->count(),
            'total_employees' => $records->pluck('employee_id')->unique()->count(),
            'total_hours' => round($records->sum('worked_hours'), 2),
            'avg_attendance_rate' => $departments->count() > 0 ? round($departments->avg('attendance_rate'), 1) : 0,
        ];

        return Inertia::render('Reports/DepartmentComparison', [
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
            'departments' => $departments,
            'summary' => $summary,
        ]);
    }

    /**
     * Incidents summary report.
     */
    public function incidents(Request $request): Response
    {
        $startDate = $request->start_date ? Carbon::parse($request->start_date) : Carbon::now()->startOfMonth();
        $endDate = $request->end_date ? Carbon::parse($request->end_date) : Carbon::now()->endOfMonth();

        $activeEmployeeIds = $this->scopedActiveEmployeeIds();

        $incidents = Incident::with(['employee.department', 'incidentType', 'approvedBy'])
            ->whereIn('employee_id', $activeEmployeeIds)
            ->whereBetween('start_date', [$startDate, $endDate])
            ->orderBy('start_date', 'desc')
            ->get();

        // By type. Los DÍAS totalizados son solo de incidencias APROBADAS —
        // el mismo criterio que la nómina (auditoría #58); pendientes y
        // rechazadas se siguen contando aparte en su desglose.
        $byType = $incidents->groupBy(fn ($i) => $i->incidentType?->name ?? 'Sin Tipo')->map(function ($group, $typeName) {
            return [
                'type' => $typeName ?: 'Sin Tipo',
                'count' => $group->count(),
                'total_days' => $group->where('status', 'approved')->sum('days_count'),
                'approved' => $group->where('status', 'approved')->count(),
                'pending' => $group->where('status', 'pending')->count(),
                'rejected' => $group->where('status', 'rejected')->count(),
            ];
        })->sortByDesc('count')->values();

        // By department (días = solo aprobadas, igual que arriba)
        $byDepartment = $incidents->groupBy(fn ($i) => $i->employee?->department?->name ?? 'Sin Departamento')->map(function ($group, $deptName) {
            return [
                'department' => $deptName ?: 'Sin Departamento',
                'count' => $group->count(),
                'total_days' => $group->where('status', 'approved')->sum('days_count'),
            ];
        })->sortByDesc('count')->values();

        // By status
        $byStatus = [
            'pending' => $incidents->where('status', 'pending')->count(),
            'approved' => $incidents->where('status', 'approved')->count(),
            'rejected' => $incidents->where('status', 'rejected')->count(),
        ];

        $summary = [
            'total_incidents' => $incidents->count(),
            // Solo días de incidencias aprobadas: lo único con efecto real.
            'total_days' => $incidents->where('status', 'approved')->sum('days_count'),
            'pending_count' => $byStatus['pending'],
            'approved_count' => $byStatus['approved'],
            'rejected_count' => $byStatus['rejected'],
        ];

        return Inertia::render('Reports/Incidents', [
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
            'incidents' => $incidents,
            'byType' => $byType,
            'byDepartment' => $byDepartment,
            'byStatus' => $byStatus,
            'summary' => $summary,
        ]);
    }

    /**
     * Employee productivity report.
     */
    public function productivity(Request $request): Response
    {
        $startDate = $request->start_date ? Carbon::parse($request->start_date) : Carbon::now()->startOfMonth();
        $endDate = $request->end_date ? Carbon::parse($request->end_date) : Carbon::now()->endOfMonth();

        $activeEmployeeIds = $this->scopedActiveEmployeeIds();

        $records = AttendanceRecord::with(['employee.department', 'employee.schedule'])
            ->whereIn('employee_id', $activeEmployeeIds)
            ->whereBetween('work_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->get();

        $byEmployee = $records->groupBy('employee_id')->map(function ($group) {
            $employee = $group->first()->employee;

            $workedRecords = $group->whereIn('status', ['present', 'late', 'partial']);
            $workedDays = $workedRecords->count();
            $totalHours = $group->sum('worked_hours');
            $overtimeHours = $group->sum('overtime_hours');
            $lateCount = $group->where('status', 'late')->count();
            $absentCount = $group->where('status', 'absent')->count();

            $expectedHours = $workedRecords->sum(function ($record) use ($employee) {
                $dayName = strtolower(Carbon::parse($record->work_date)->format('l'));
                $daySchedule = $employee?->getEffectiveScheduleForDay($dayName);

                return $daySchedule?->daily_work_hours ?? 8;
            });
            $efficiency = $expectedHours > 0 ? round(($totalHours / $expectedHours) * 100, 1) : 0;

            // Punctuality score (100 - penalties)
            $punctualityScore = 100;
            $punctualityScore -= ($lateCount * 5); // -5 per late
            $punctualityScore -= ($absentCount * 10); // -10 per absence
            $punctualityScore = max(0, $punctualityScore);

            return [
                'employee' => $employee,
                'worked_days' => $workedDays,
                'total_hours' => round($totalHours, 2),
                'overtime_hours' => round($overtimeHours, 2),
                'expected_hours' => $expectedHours,
                'efficiency' => $efficiency,
                'late_count' => $lateCount,
                'absent_count' => $absentCount,
                'punctuality_score' => $punctualityScore,
            ];
        })->sortByDesc('efficiency')->values();

        $summary = [
            'total_employees' => $byEmployee->count(),
            'avg_efficiency' => $byEmployee->count() > 0 ? round($byEmployee->avg('efficiency'), 1) : 0,
            'avg_punctuality' => $byEmployee->count() > 0 ? round($byEmployee->avg('punctuality_score'), 0) : 0,
            'total_hours' => round($records->sum('worked_hours'), 2),
            'total_overtime' => round($records->sum('overtime_hours'), 2),
        ];

        return Inertia::render('Reports/Productivity', [
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
            'byEmployee' => $byEmployee,
            'summary' => $summary,
        ]);
    }

    /**
     * Payroll history/trends report.
     */
    public function payrollTrends(Request $request): Response
    {
        $activeEmployeeIds = $this->scopedActiveEmployeeIds();

        $periods = PayrollPeriod::with(['entries' => function ($q) use ($activeEmployeeIds) {
            $q->whereIn('employee_id', $activeEmployeeIds);
        }])
            ->whereIn('status', ['approved', 'paid'])
            ->orderBy('start_date', 'desc')
            ->take(12)
            ->get()
            ->reverse()
            ->values();

        $trendData = $periods->map(function ($period) {
            return [
                'period' => $period->name,
                'start_date' => $period->start_date,
                'employee_count' => $period->entries->count(),
                'total_gross' => $period->entries->sum('gross_pay'),
                'total_net' => $period->entries->sum('net_pay'),
                'total_overtime' => $period->entries->sum('overtime_pay'),
                'total_deductions' => $period->entries->sum('deductions'),
                'avg_pay' => $period->entries->count() > 0 ? round($period->entries->avg('net_pay'), 2) : 0,
            ];
        });

        $summary = [
            'periods_count' => $trendData->count(),
            'avg_total_net' => $trendData->count() > 0 ? round($trendData->avg('total_net'), 2) : 0,
            'max_total_net' => $trendData->max('total_net') ?? 0,
            'min_total_net' => $trendData->min('total_net') ?? 0,
            'total_paid' => $trendData->sum('total_net'),
        ];

        return Inertia::render('Reports/PayrollTrends', [
            'trendData' => $trendData,
            'summary' => $summary,
        ]);
    }
}
