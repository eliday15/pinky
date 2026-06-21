<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\CompensationType;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Incident;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\SystemSetting;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Service for calculating payroll entries for employees in a period.
 */
class PayrollCalculatorService
{
    /**
     * Divisor del séptimo día (Art. 72 LFT): la falta descuenta el día + 1/6
     * del descanso pagado (SD × 7/6). Es 6 para TODOS — los horarios de 5 días
     * trabajan jornada de 9.5 h, equivalente a una semana de 6 días.
     */
    private const SEVENTH_DAY_DIVISOR = 6;

    private CompensationRateResolverService $resolver;

    private LateAbsenceService $lateAbsences;

    public function __construct(CompensationRateResolverService $resolver, LateAbsenceService $lateAbsences)
    {
        $this->resolver = $resolver;
        $this->lateAbsences = $lateAbsences;
    }

    /**
     * Calculate payroll for all active employees in a period.
     */
    public function calculatePeriod(PayrollPeriod $period): void
    {
        // Paid periods are immutable: never recalculate (and overwrite) a
        // period that has already been paid out.
        if ($period->status === 'paid') {
            return;
        }

        $period->update(['status' => 'calculating']);

        // Eager load compensation types + department (weekend unit rule) to avoid N+1
        $employees = Employee::active()
            ->with([
                'compensationTypes' => fn ($q) => $q->wherePivot('is_active', true),
                'department',
            ])
            ->get();

        foreach ($employees as $employee) {
            $this->calculateEmployeePayroll($period, $employee);
        }

        // El recálculo completo deja el periodo al día: limpia la marca de
        // invalidación (DECISIONES §7) y regresa a revisión.
        $period->update([
            'status' => 'review',
            'requires_recalculation' => false,
            'recalculation_flagged_at' => null,
        ]);
    }

    /**
     * Calculate payroll for a single employee in a period.
     */
    public function calculateEmployeePayroll(PayrollPeriod $period, Employee $employee): PayrollEntry
    {
        // Ensure compensation types are loaded for rate resolution
        if (! $employee->relationLoaded('compensationTypes')) {
            $employee->load(['compensationTypes' => fn ($q) => $q->wherePivot('is_active', true)]);
        }

        $startDate = Carbon::parse($period->start_date);
        $endDate = Carbon::parse($period->end_date);

        // Regla mensual retardos→falta: garantizar que todo mes cerrado tenga
        // su incidencia FRT generada ANTES de leer las incidencias del periodo
        // (idempotente; la FRT cae en la primera nómina tras el cierre).
        $this->lateAbsences->ensureMonthlyIncidentsGenerated($employee);

        // Compare DATE columns against plain date strings (not Carbon
        // datetimes): on SQLite a '2026-07-01' DATE sorts BEFORE the
        // '2026-07-01 00:00:00' bound and rows on the period boundary would be
        // silently dropped (MySQL treats them as equal).
        $startDateStr = $startDate->toDateString();
        $endDateStr = $endDate->toDateString();

        // Get attendance records for the period
        $attendance = AttendanceRecord::where('employee_id', $employee->id)
            ->whereBetween('work_date', [$startDateStr, $endDateStr])
            ->get();

        // Get approved incidents for the period
        $incidents = Incident::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->where(function ($q) use ($startDateStr, $endDateStr) {
                $q->whereBetween('start_date', [$startDateStr, $endDateStr])
                    ->orWhereBetween('end_date', [$startDateStr, $endDateStr])
                    ->orWhere(function ($q2) use ($startDateStr, $endDateStr) {
                        $q2->where('start_date', '<=', $startDateStr)
                            ->where('end_date', '>=', $endDateStr);
                    });
            })
            ->with('incidentType')
            ->get();

        // Get holidays in the period
        $holidays = Holiday::whereBetween('date', [$startDateStr, $endDateStr])->get();
        $holidayDates = $holidays->pluck('date')->map(fn ($d) => Carbon::parse($d)->toDateString())->toArray();

        // Get approved authorizations for the period (for holiday/weekend gating
        // AND to honor each authorization's specific compensation_type_id).
        $approvedAuthorizations = Authorization::where('employee_id', $employee->id)
            ->whereBetween('date', [$startDate, $endDate])
            ->whereIn('status', [Authorization::STATUS_APPROVED, Authorization::STATUS_PAID])
            ->with('compensationType')
            ->get();

        // Días justificados por incidencias aprobadas (DECISIONES §8): un día
        // absent/late cubierto por vacación, incapacidad, permiso o falta
        // justificada NO rompe los bonos de asistencia ni cuenta como falta —
        // no se penaliza el séptimo día. Se calcula ANTES de las métricas de
        // asistencia para poder distinguir las faltas injustificadas.
        $justifiedDates = $this->justifiedDates($incidents, $startDate, $endDate);

        // Calculate attendance metrics
        $metrics = $this->calculateAttendanceMetrics($attendance, $employee, $holidayDates, $approvedAuthorizations, $justifiedDates);

        // Calculate incident days
        $incidentMetrics = $this->calculateIncidentMetrics($incidents, $startDate, $endDate, $employee, $holidayDates);

        // ----------------------------------------------------------------
        // Period payment scope.
        // The nómina is split in two: a WEEKLY period pays the base salary
        // minus absences/lates; a MONTHLY period pays the extras (overtime,
        // velada, holiday, weekend, special concepts) plus vacations and all
        // bonuses. A legacy BIWEEKLY period pays everything together.
        // ----------------------------------------------------------------
        $payBase = $period->paysBase();
        $payExtras = $period->paysExtras();

        // Get rates
        // hourly_rate ya NO es el insumo del sueldo: se conserva derivado del
        // sueldo diario solo como compatibilidad para el cálculo legacy de
        // extras (empleados sin conceptos). El pago base se calcula por DÍA.
        $hourlyRate = $employee->hourly_rate;
        // Sueldo diario: fuente de verdad del pago base (Art. 90 LFT). Usa
        // daily_salary explícito; si faltara, el accessor lo deriva del horario.
        $dailySalary = $employee->daily_salary_computed;

        // Legacy fallback rates (used when employee has no comp types)
        $overtimeMultiplier = $employee->overtime_rate ?? 1.5;
        $holidayMultiplier = $employee->holiday_rate ?? 2.0;

        // Use CompensationType-driven calculation if employee has comp types assigned
        $useCompTypes = $this->resolver->hasCompensationTypes($employee);

        // Conjunto de payment_periods que paga este periodo: semanal→'weekly',
        // mensual→'monthly', quincenal→ambos. Un concepto solo paga si su
        // payment_period está en el set; el default 'monthly' lo mantiene donde
        // se paga hoy (en los periodos que pagan extras).
        $allowedPaymentPeriods = [];
        if ($payBase) {
            $allowedPaymentPeriods[] = CompensationType::PAYMENT_PERIOD_WEEKLY;
        }
        if ($payExtras) {
            $allowedPaymentPeriods[] = CompensationType::PAYMENT_PERIOD_MONTHLY;
        }

        // La ruta de conceptos corre en la pasada de extras (igual que hoy) y,
        // además, en un periodo BASE (semanal) SOLO si existe algún concepto
        // marcado 'weekly'. Sin conceptos semanales (config por defecto) un
        // periodo semanal se comporta exactamente como hoy: no corre la ruta.
        $runCompTypes = $useCompTypes && (
            $payExtras
            || (in_array(CompensationType::PAYMENT_PERIOD_WEEKLY, $allowedPaymentPeriods, true)
                && CompensationType::active()
                    ->where('payment_period', CompensationType::PAYMENT_PERIOD_WEEKLY)
                    ->exists())
        );

        // ---- BASE (weekly): sueldo diario × días pagados del periodo ----
        // El sueldo se paga por DÍA, no por hora (Art. 90 LFT), y la semana se
        // cubre sobre 7 días: 6 laborables + el séptimo día de descanso pagado
        // (Art. 69). Se restan del base los días pagados aparte en el periodo
        // mensual (vacaciones, incapacidad) y los no pagados (permiso sin goce),
        // para no duplicar ni regalar pago.
        $weekDays = $payBase ? $this->paidCalendarDays($employee, $startDate, $endDate) : 0;
        $daysPaidElsewhere = $payBase
            ? ($incidentMetrics['vacation_days']
                + $incidentMetrics['sick_leave_days']
                + $incidentMetrics['permission_unpaid_days'])
            : 0;
        $basePaidDays = max(0, $weekDays - $daysPaidElsewhere);
        $regularPay = round($basePaidDays * $dailySalary, 2);

        // Deducción por falta (Art. 72 LFT): cada falta injustificada y cada
        // falta por retardos (FRT) descuenta el día COMPLETO + la parte
        // proporcional del séptimo día = sueldo_diario × 7/6 (el día más 1/6 del
        // domingo). El divisor es SIEMPRE 6 para todos: aunque un empleado tenga
        // 5 días en su horario, su jornada extendida (9.5 h) equivale a una
        // semana de 6 días, así que el factor del séptimo día es idéntico para
        // todos por política de la empresa.
        $workingDaysPerWeek = self::SEVENTH_DAY_DIVISOR;
        $restDayFactor = 7 / self::SEVENTH_DAY_DIVISOR;
        $lateAbsencesGenerated = $payBase ? $incidentMetrics['late_absence_days'] : 0;
        $absenceDeductionDays = $payBase
            ? ($metrics['days_absent_unjustified'] + $lateAbsencesGenerated)
            : 0;
        $deductions = $payBase
            ? round($absenceDeductionDays * $dailySalary * $restDayFactor, 2)
            : 0.0;

        // ---- EXTRAS (monthly): overtime, velada, holiday, weekend, special
        // concepts, vacations and bonuses. Computed only when the period pays
        // extras so a weekly period never charges them. ----
        $veladaMetrics = $this->calculateVeladaMetrics($attendance);
        $veladaMultiplier = (float) SystemSetting::get('velada_rate_multiplier', 2.0);

        $nightShiftMetrics = [
            'night_shift_hours' => 0,
            'night_shift_days' => 0,
            'night_shift_bonus' => 0,
            'dinner_allowance' => 0,
        ];

        $overtimePay = 0.0;
        $veladaPay = 0.0;
        $holidayPay = 0.0;
        $weekendPay = 0.0;
        $otherCompensationPay = 0.0;
        $vacationPay = 0.0;
        $vacationPremiumPay = 0.0;
        $sickLeavePay = 0.0;
        $punctualityBonus = 0.0;
        $weeklyBonus = 0.0;
        $monthlyBonus = 0.0;
        $compensationConcepts = [];

        // Night-shift metrics feed both the comp-types velada input and the
        // legacy dinner/night bonus. Se calculan cuando se pagan extras o cuando
        // la ruta de conceptos corre en un periodo base con conceptos semanales.
        if ($payExtras || $runCompTypes) {
            // FASE 3.3: Night shifts and dinner allowances — pagados solo por
            // noche realmente trabajada (velada en checadas) y autorizada.
            $nightShiftMetrics = $this->calculateNightShiftMetrics($employee, $startDate, $endDate, $attendance);
        }

        $authorizedOvertimeHours = ($payExtras || $runCompTypes)
            ? $veladaMetrics['overtime_authorized_hours']
            : 0.0;

        // Conceptos (overtime/velada/festivo/finde/especiales) pagan donde su
        // payment_period coincide con el periodo — puede ser un periodo base
        // (semanal), no solo la pasada de extras. El filtro por
        // $allowedPaymentPeriods deja pasar exactamente los conceptos que tocan.
        if ($runCompTypes) {
            // Almacén PT (u otro depto con weekend_unit_hours) paga el fin de
            // semana por unidades de N horas trabajadas, no por día. NULL =
            // pago normal por fila/día.
            $weekendUnitHours = $employee->department?->weekend_unit_hours;

            $compensationPayments = $this->resolver->calculateAllCompensation(
                $employee,
                [
                    'overtime_hours' => $authorizedOvertimeHours,
                    'velada_hours' => $veladaMetrics['velada_authorized_hours'],
                    // Noches de velada pagables (1 por noche trabajada y
                    // autorizada): base del pago por monto fijo por velada.
                    'velada_days' => $nightShiftMetrics['night_shift_days'],
                    'holiday_hours' => $metrics['holiday_hours'],
                    'weekend_hours' => $metrics['weekend_hours'],
                ],
                $hourlyRate,
                $dailySalary,
                $approvedAuthorizations,
                $holidayDates,
                $weekendUnitHours,
                $allowedPaymentPeriods,
            );

            $compensationConcepts = $compensationPayments['concepts'];

            // Route each concept to its stored pay bucket. Overtime/velada
            // match by code; holiday/weekend/special match by the comp
            // type's authorization_type / attendance_pull_rule.
            foreach ($compensationConcepts as $concept) {
                $code = $concept['code'] ?? '';
                $authType = $concept['authorization_type'] ?? null;
                $pullRule = $concept['attendance_pull_rule'] ?? null;

                if (in_array($code, ['HE', 'HED', 'HET'], true)) {
                    $overtimePay += $concept['amount'];
                } elseif ($code === 'VEL' || $authType === Authorization::TYPE_NIGHT_SHIFT) {
                    $veladaPay += $concept['amount'];
                } elseif ($authType === Authorization::TYPE_HOLIDAY_WORKED) {
                    $holidayPay += $concept['amount'];
                } elseif ($pullRule === CompensationType::PULL_RULE_WEEKEND) {
                    $weekendPay += $concept['amount'];
                } else {
                    // Cena, comida, dominical and any other special concept.
                    $otherCompensationPay += $concept['amount'];
                }
            }
        }

        // Vacaciones, prima, incapacidad y bonos son intrínsecamente mensuales:
        // solo en la pasada de extras. El fallback legado (sin conceptos) también
        // es mensual, así que un periodo semanal nunca cobra extras legados.
        if ($payExtras) {
            $vacationPay = $incidentMetrics['vacation_days'] * $dailySalary;

            // Prima vacacional (DECISIONES §3): se paga con cada día de
            // vacación como concepto separado, con el % del empleado.
            $vacationPremiumPay = $vacationPay * ((float) ($employee->vacation_premium_percentage ?? 0) / 100);

            // Incapacidades (DECISIONES §4): con goce se pagan; sin goce el
            // día simplemente no se paga (vía horas), sin deducción extra.
            $sickLeavePay = $incidentMetrics['sick_leave_paid_days'] * $dailySalary;

            // FASE 3.2: Attendance bonuses (paid with the extras)
            $punctualityBonus = $metrics['punctual_days'] * (float) SystemSetting::get('punctuality_bonus_amount', 50);
            $weeklyBonus = $this->calculateWeeklyBonus($employee, $period, $attendance, $justifiedDates);
            $monthlyBonus = $this->calculateMonthlyBonus($employee, $period, $attendance, $justifiedDates);

            if (! $useCompTypes) {
                // Legacy fallback: hardcoded multipliers
                $overtimePay = $authorizedOvertimeHours * $hourlyRate * $overtimeMultiplier;
                $veladaPay = $veladaMetrics['velada_authorized_hours'] * $hourlyRate * $veladaMultiplier;
                $holidayPay = $metrics['holiday_hours'] * $hourlyRate * $holidayMultiplier;
                $weekendPay = $metrics['weekend_hours'] * $hourlyRate * $overtimeMultiplier;
            }
        }

        // Dinner & night-shift bonus: when the employee is on the
        // CompensationType path, dinner is paid solely from approved CENA
        // authorizations and the velada is paid per hour via VEL, so BOTH
        // legacy fixed concepts are suppressed to avoid double-paying
        // (DECISIONES_NEGOCIO_2026-06-04.md §2).
        $dinnerAllowance = $useCompTypes ? 0.0 : $nightShiftMetrics['dinner_allowance'];
        $nightShiftBonusPay = $useCompTypes ? 0.0 : $nightShiftMetrics['night_shift_bonus'];

        // Calculate total bonuses (0 on a weekly period)
        $totalBonuses = $punctualityBonus + $weeklyBonus + $monthlyBonus
            + $nightShiftBonusPay
            + $dinnerAllowance;

        $basePay = $payBase ? $regularPay : 0.0;
        $grossPay = $basePay + $overtimePay + $veladaPay + $holidayPay + $weekendPay
            + $otherCompensationPay + $vacationPay + $vacationPremiumPay + $sickLeavePay
            + $totalBonuses;
        $netPay = $grossPay - $deductions;

        // ---- Reparto efectivo / banco ----
        // El sueldo BASE se paga en EFECTIVO solo cuando el empleado sigue en
        // periodo de prueba Y aún NO está inscrito al IMSS; en cualquier otro
        // caso el base neto va por TRANSFERENCIA (banco/CONTPAQi). Los EXTRAS
        // (overtime, velada, festivo, finde, conceptos, bonos) salen SIEMPRE en
        // efectivo. La fórmula es única para los tres tipos de periodo: en
        // mensual basePay y deductions son 0, así que bank=0 y cash=net_pay (los
        // extras). NO altera regular_pay/gross_pay/net_pay.
        $baseInCash = $employee->paysBaseInCash();
        if ($baseInCash) {
            $cashAmount = round($netPay, 2);
            $bankAmount = 0.0;
        } else {
            $bankAmount = max(0.0, round($basePay - $deductions, 2));
            $cashAmount = round($netPay - $bankAmount, 2);
        }

        // Build calculation breakdown for transparency
        $breakdown = [
            'attendance' => [
                'records' => $attendance->count(),
                'regular_hours' => $metrics['regular_hours'],
                'overtime_hours' => $metrics['overtime_hours'],
                'holiday_hours' => $metrics['holiday_hours'],
                'weekend_hours' => $metrics['weekend_hours'],
                'punctual_days' => $metrics['punctual_days'],
            ],
            'unauthorized' => [
                'holiday_hours' => $metrics['unauthorized_holiday_hours'],
                'weekend_hours' => $metrics['unauthorized_weekend_hours'],
            ],
            'incidents' => [
                'vacation_days' => $incidentMetrics['vacation_days'],
                'sick_leave_days' => $incidentMetrics['sick_leave_days'],
                'sick_leave_paid_days' => $incidentMetrics['sick_leave_paid_days'],
                'permission_days' => $incidentMetrics['permission_days'],
                'permission_unpaid_days' => $incidentMetrics['permission_unpaid_days'],
                'absence_days' => $incidentMetrics['absence_days'],
                // Faltas que descuentan SD × 7/D: injustificadas (asistencia) +
                // faltas por retardos (FRT). Los días pagados aparte o no
                // pagados se restan del base, no se descuentan con castigo.
                'absence_deduction_days' => $absenceDeductionDays,
            ],
            'late_accumulation' => [
                'late_absences_generated' => $lateAbsencesGenerated,
                'source' => 'frt_incidents_mensuales',
            ],
            'night_shifts' => [
                'hours' => $nightShiftMetrics['night_shift_hours'],
                'days' => $nightShiftMetrics['night_shift_days'],
                'bonus' => $nightShiftBonusPay,
                'dinner_allowance' => $dinnerAllowance,
                'suppressed_by_comp_types' => $useCompTypes,
            ],
            'velada' => [
                'total_hours' => $veladaMetrics['velada_hours'],
                'authorized_hours' => $veladaMetrics['velada_authorized_hours'],
                'overtime_authorized_hours' => $veladaMetrics['overtime_authorized_hours'],
                // Veladas pagadas (1 por noche). Con conceptos se paga el monto
                // fijo por velada; el multiplicador solo aplica en la ruta legada.
                'days' => $nightShiftMetrics['night_shift_days'],
                'multiplier' => $useCompTypes ? null : $veladaMultiplier,
                'pay' => $veladaPay,
            ],
            'bonuses' => [
                'punctuality' => $punctualityBonus,
                'weekly' => $weeklyBonus,
                'monthly' => $monthlyBonus,
                'total' => $totalBonuses,
            ],
            'rates' => [
                'hourly' => $hourlyRate,
                'daily_salary' => $dailySalary,
                'working_days_per_week' => $workingDaysPerWeek,
                'rest_day_factor' => round($restDayFactor, 4),
                'overtime_multiplier' => $useCompTypes ? null : $overtimeMultiplier,
                'holiday_multiplier' => $useCompTypes ? null : $holidayMultiplier,
                'uses_compensation_types' => $useCompTypes,
            ],
            'base' => [
                // Días calendario pagados del periodo (séptimo día incluido).
                'week_days' => $weekDays,
                // Días restados del base por pagarse aparte o no pagarse.
                'days_paid_elsewhere' => $daysPaidElsewhere,
                'base_paid_days' => $basePaidDays,
                // Faltas que descuentan SD × 7/D (injustificadas + FRT).
                'absence_deduction_days' => $absenceDeductionDays,
            ],
            'compensation_concepts' => $compensationConcepts,
            'scope' => [
                'period_type' => $period->type,
                'pays_base' => $payBase,
                'pays_extras' => $payExtras,
            ],
            'calculations' => [
                'regular_pay' => $basePay,
                'overtime_pay' => $overtimePay,
                'velada_pay' => $veladaPay,
                'holiday_pay' => $holidayPay,
                'weekend_pay' => $weekendPay,
                'other_compensation_pay' => $otherCompensationPay,
                'vacation_pay' => $vacationPay,
                'vacation_premium_pay' => $vacationPremiumPay,
                'sick_leave_pay' => $sickLeavePay,
                'gross_pay' => $grossPay,
                'deductions' => $deductions,
                'net_pay' => $netPay,
            ],
        ];

        // Create or update payroll entry
        return PayrollEntry::updateOrCreate(
            [
                'payroll_period_id' => $period->id,
                'employee_id' => $employee->id,
            ],
            [
                'hourly_rate' => $hourlyRate,
                'daily_salary' => $dailySalary,
                'overtime_multiplier' => $overtimeMultiplier,
                'holiday_multiplier' => $holidayMultiplier,
                'regular_hours' => $payBase ? $metrics['regular_hours'] : 0,
                'overtime_hours' => $payExtras ? $metrics['overtime_hours'] : 0,
                'overtime_authorized_hours' => $payExtras ? $veladaMetrics['overtime_authorized_hours'] : 0,
                'velada_hours' => $payExtras ? $veladaMetrics['velada_hours'] : 0,
                'velada_authorized_hours' => $payExtras ? $veladaMetrics['velada_authorized_hours'] : 0,
                'velada_multiplier' => $veladaMultiplier,
                'velada_pay' => $veladaPay,
                'velada_days' => $payExtras ? $nightShiftMetrics['night_shift_days'] : 0,
                'holiday_hours' => $payExtras ? $metrics['holiday_hours'] : 0,
                'weekend_hours' => $payExtras ? $metrics['weekend_hours'] : 0,
                'night_shift_hours' => $payExtras ? $nightShiftMetrics['night_shift_hours'] : 0,
                'days_worked' => $payBase ? $metrics['days_worked'] : 0,
                'days_absent' => $payBase ? ($metrics['days_absent'] + $lateAbsencesGenerated) : 0,
                'days_late' => $payBase ? $metrics['days_late'] : 0,
                'punctuality_days' => $payExtras ? $metrics['punctual_days'] : 0,
                'night_shift_days' => $payExtras ? $nightShiftMetrics['night_shift_days'] : 0,
                'late_absences_generated' => $lateAbsencesGenerated,
                'vacation_days_paid' => $payExtras ? $incidentMetrics['vacation_days'] : 0,
                'sick_leave_days' => $payExtras ? $incidentMetrics['sick_leave_days'] : 0,
                'regular_pay' => $basePay,
                'overtime_pay' => $overtimePay,
                'holiday_pay' => $holidayPay,
                'weekend_pay' => $weekendPay,
                'other_compensation_pay' => $otherCompensationPay,
                'vacation_pay' => $vacationPay,
                'vacation_premium_pay' => $vacationPremiumPay,
                'sick_leave_pay' => $sickLeavePay,
                'punctuality_bonus' => $punctualityBonus,
                'dinner_allowance' => $dinnerAllowance,
                'night_shift_bonus' => $nightShiftBonusPay,
                'weekly_bonus' => $weeklyBonus,
                'monthly_bonus' => $monthlyBonus,
                'bonuses' => $totalBonuses,
                'deductions' => $deductions,
                'gross_pay' => $grossPay,
                'net_pay' => $netPay,
                'cash_amount' => $cashAmount,
                'bank_amount' => $bankAmount,
                'calculation_breakdown' => $breakdown,
            ]
        );
    }

    /**
     * Fechas del periodo cubiertas por incidencias aprobadas que justifican
     * (regla compartida con reportes: Incident::typeJustifiesAbsence).
     *
     * @return array<string, true> set de fechas 'Y-m-d'
     */
    private function justifiedDates(Collection $incidents, Carbon $startDate, Carbon $endDate): array
    {
        $dates = [];

        foreach ($incidents as $incident) {
            if (! $incident->incidentType || ! Incident::typeJustifiesAbsence($incident->incidentType)) {
                continue;
            }

            $from = Carbon::parse($incident->start_date)->max($startDate);
            $to = Carbon::parse($incident->end_date)->min($endDate);

            for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
                $dates[$day->toDateString()] = true;
            }
        }

        return $dates;
    }

    /**
     * Días calendario del periodo que se le pagan al empleado (séptimo día
     * incluido), acotados a su periodo de empleo para prorratear altas/bajas a
     * media semana. Una semana normal Lun–Dom devuelve 7.
     */
    private function paidCalendarDays(Employee $employee, Carbon $startDate, Carbon $endDate): int
    {
        $from = $startDate->copy()->startOfDay();
        $to = $endDate->copy()->startOfDay();

        if ($employee->hire_date) {
            $hire = Carbon::parse($employee->hire_date)->startOfDay();
            if ($hire->gt($from)) {
                $from = $hire;
            }
        }

        if ($employee->termination_date) {
            $term = Carbon::parse($employee->termination_date)->startOfDay();
            if ($term->lt($to)) {
                $to = $term;
            }
        }

        if ($to->lt($from)) {
            return 0;
        }

        return (int) $from->diffInDays($to) + 1;
    }

    /**
     * ¿El registro rompe la asistencia perfecta? Solo cuando es absent/late
     * y el día NO está justificado por una incidencia aprobada.
     */
    private function breaksPerfectAttendance(mixed $record, array $justifiedDates): bool
    {
        if (! in_array($record->status, ['absent', 'late'], true)) {
            return false;
        }

        return ! isset($justifiedDates[Carbon::parse($record->work_date)->toDateString()]);
    }

    /**
     * FASE 3.2: Calculate weekly bonus based on perfect attendance.
     *
     * Lo justificado no rompe el bono (DECISIONES §8).
     *
     * @param  Employee  $employee  Employee
     * @param  PayrollPeriod  $period  Payroll period
     * @param  Collection  $attendance  Attendance records
     * @param  array<string, true>  $justifiedDates  Fechas justificadas por incidencia aprobada
     * @return float Weekly bonus amount
     */
    private function calculateWeeklyBonus(Employee $employee, PayrollPeriod $period, Collection $attendance, array $justifiedDates): float
    {
        $weeklyBonusAmount = (float) SystemSetting::get('weekly_bonus_amount', 0);
        if ($weeklyBonusAmount <= 0) {
            return 0;
        }

        // Group attendance by week and check for perfect attendance
        $weeklyPerfect = 0;
        $attendanceByWeek = $attendance->groupBy(fn ($record) => Carbon::parse($record->work_date)->weekOfYear);

        foreach ($attendanceByWeek as $weekRecords) {
            $imperfect = $weekRecords->contains(fn ($r) => $this->breaksPerfectAttendance($r, $justifiedDates));

            if (! $imperfect) {
                $weeklyPerfect++;
            }
        }

        return $weeklyPerfect * $weeklyBonusAmount;
    }

    /**
     * FASE 3.2: Calculate monthly bonus based on perfect attendance.
     *
     * Lo justificado no rompe el bono (DECISIONES §8).
     *
     * @param  Employee  $employee  Employee
     * @param  PayrollPeriod  $period  Payroll period
     * @param  Collection  $attendance  Attendance records
     * @param  array<string, true>  $justifiedDates  Fechas justificadas por incidencia aprobada
     * @return float Monthly bonus amount
     */
    private function calculateMonthlyBonus(Employee $employee, PayrollPeriod $period, Collection $attendance, array $justifiedDates): float
    {
        $monthlyBonusAmount = (float) SystemSetting::get('monthly_bonus_amount', 0);
        if ($monthlyBonusAmount <= 0) {
            return 0;
        }

        // Check for perfect attendance in the period
        $imperfect = $attendance->contains(fn ($r) => $this->breaksPerfectAttendance($r, $justifiedDates));

        if (! $imperfect) {
            return $monthlyBonusAmount;
        }

        return 0;
    }

    /**
     * FASE 3.3: Calculate night shift metrics including hours, bonus, and dinner allowance.
     *
     * El bono fijo de velada y el vale de cena se pagan por NOCHE REALMENTE
     * TRABAJADA Y AUTORIZADA (DECISIONES_NEGOCIO_2026-06-04.md §2): máximo una
     * vez por (empleado, fecha) aunque existan filas de autorización
     * duplicadas, y solo cuando la checada de esa fecha registró velada real
     * (velada_hours > 0).
     *
     * @param  Employee  $employee  Employee
     * @param  Carbon  $startDate  Start date
     * @param  Carbon  $endDate  End date
     * @param  Collection  $attendance  Attendance records of the period
     * @return array Night shift metrics
     */
    private function calculateNightShiftMetrics(Employee $employee, Carbon $startDate, Carbon $endDate, Collection $attendance): array
    {
        $nightShiftBonus = (float) SystemSetting::get('night_shift_bonus', 100);
        $dinnerAllowanceAmount = (float) SystemSetting::get('dinner_allowance_amount', 75);

        // Get approved night shift authorizations for the period
        $approvedNightShifts = Authorization::where('employee_id', $employee->id)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->where('type', Authorization::TYPE_NIGHT_SHIFT)
            ->whereIn('status', [Authorization::STATUS_APPROVED, Authorization::STATUS_PAID])
            ->get();

        $nightShiftHours = $approvedNightShifts->sum('hours');

        // Fechas con velada real en checadas (velada_hours > 0)
        $veladaWorkedDates = $attendance
            ->filter(fn ($record) => (float) ($record->velada_hours ?? 0) > 0)
            ->map(fn ($record) => Carbon::parse($record->work_date)->toDateString())
            ->unique()
            ->all();

        // Noches pagables: fechas únicas autorizadas ∩ fechas con velada real
        $nightShiftDays = $approvedNightShifts
            ->map(fn ($authorization) => Carbon::parse($authorization->date)->toDateString())
            ->unique()
            ->filter(fn ($date) => in_array($date, $veladaWorkedDates, true))
            ->count();

        return [
            'night_shift_hours' => round($nightShiftHours, 2),
            'night_shift_days' => $nightShiftDays,
            'night_shift_bonus' => $nightShiftDays * $nightShiftBonus,
            'dinner_allowance' => $nightShiftDays * $dinnerAllowanceAmount,
        ];
    }

    /**
     * Calculate velada metrics from attendance records.
     *
     * Aggregates velada and authorized overtime/velada hours from
     * the attendance records (which are calculated by VeladaCalculatorService).
     */
    private function calculateVeladaMetrics(Collection $attendance): array
    {
        $veladaHours = 0;
        $veladaAuthorizedHours = 0;
        $overtimeAuthorizedHours = 0;

        foreach ($attendance as $record) {
            $veladaHours += (float) ($record->velada_hours ?? 0);
            $veladaAuthorizedHours += (float) ($record->velada_authorized_hours ?? 0);
            $overtimeAuthorizedHours += (float) ($record->overtime_authorized_hours ?? 0);
        }

        return [
            'velada_hours' => round($veladaHours, 2),
            'velada_authorized_hours' => round($veladaAuthorizedHours, 2),
            'overtime_authorized_hours' => round($overtimeAuthorizedHours, 2),
        ];
    }

    /**
     * Calculate attendance metrics for the period.
     *
     * Holiday and weekend hours require an approved authorization to count
     * as premium hours. Without authorization, those hours are NOT paid.
     */
    private function calculateAttendanceMetrics(
        Collection $attendance,
        Employee $employee,
        array $holidayDates,
        Collection $approvedAuthorizations,
        array $justifiedDates = [],
    ): array {
        $regularHours = 0;
        $overtimeHours = 0;
        $holidayHours = 0;
        $weekendHours = 0;
        $unauthorizedHolidayHours = 0;
        $unauthorizedWeekendHours = 0;
        $daysWorked = 0;
        $daysAbsent = 0;
        $daysAbsentUnjustified = 0;
        $daysLate = 0;
        $punctualDays = 0;

        foreach ($attendance as $record) {
            $workDate = Carbon::parse($record->work_date);
            $workDateStr = $workDate->toDateString();
            $dayName = $workDate->englishDayOfWeek;
            $isHoliday = in_array($workDateStr, $holidayDates);
            // A Saturday/Sunday only counts as "weekend premium" when it
            // falls OUTSIDE the employee's normal schedule. An employee
            // whose schedule includes Saturday gets regular pay on Saturdays.
            $isWeekend = $workDate->isWeekend() && ! $employee->isEffectiveWorkingDay($dayName);

            if ($record->status === 'absent') {
                // Holidays never count as ausencias even if a stale row was
                // synced with status='absent' before the holiday was registered.
                if ($isHoliday) {
                    continue;
                }
                // Non-working days for this employee (e.g. Saturday for a
                // Mon-Fri schedule) never count as ausencia regardless of how
                // the row was originally classified.
                if (! $employee->isEffectiveWorkingDay($workDate->englishDayOfWeek)) {
                    continue;
                }
                $daysAbsent++;

                // Una falta solo descuenta (séptimo día incluido) cuando NO está
                // justificada por una incidencia aprobada (vacación, permiso con
                // goce, incapacidad, falta justificada).
                if (! isset($justifiedDates[$workDateStr])) {
                    $daysAbsentUnjustified++;
                }

                continue;
            }

            if ($record->status === 'late') {
                $daysLate++;
            }

            // Count punctual days based on the qualifies_for_punctuality_bonus flag
            if ($record->qualifies_for_punctuality_bonus) {
                $punctualDays++;
            }

            if (in_array($record->status, ['present', 'late', 'partial'])) {
                $daysWorked++;

                $workedHours = (float) $record->worked_hours;
                $overtime = (float) $record->overtime_hours;

                if ($isHoliday) {
                    // Holiday premium requires approved holiday_worked/special authorization
                    $hasHolidayAuth = $approvedAuthorizations
                        ->where('date', $record->work_date)
                        ->whereIn('type', [Authorization::TYPE_HOLIDAY_WORKED, Authorization::TYPE_SPECIAL])
                        ->isNotEmpty();

                    if ($hasHolidayAuth) {
                        $holidayHours += $workedHours + $overtime;
                    } else {
                        $unauthorizedHolidayHours += $workedHours + $overtime;
                    }
                } elseif ($isWeekend) {
                    // Weekend premium requires approved overtime/special/holiday_worked authorization
                    $hasWeekendAuth = $approvedAuthorizations
                        ->where('date', $record->work_date)
                        ->whereIn('type', [
                            Authorization::TYPE_OVERTIME,
                            Authorization::TYPE_SPECIAL,
                            Authorization::TYPE_HOLIDAY_WORKED,
                        ])
                        ->isNotEmpty();

                    if ($hasWeekendAuth) {
                        $weekendHours += $workedHours + $overtime;
                    } else {
                        $unauthorizedWeekendHours += $workedHours + $overtime;
                    }
                } else {
                    $regularHours += $workedHours;
                    $overtimeHours += $overtime;
                }
            }
        }

        return [
            'regular_hours' => round($regularHours, 2),
            'overtime_hours' => round($overtimeHours, 2),
            'holiday_hours' => round($holidayHours, 2),
            'weekend_hours' => round($weekendHours, 2),
            'unauthorized_holiday_hours' => round($unauthorizedHolidayHours, 2),
            'unauthorized_weekend_hours' => round($unauthorizedWeekendHours, 2),
            'days_worked' => $daysWorked,
            'days_absent' => $daysAbsent,
            'days_absent_unjustified' => $daysAbsentUnjustified,
            'days_late' => $daysLate,
            'punctual_days' => $punctualDays,
        ];
    }

    /**
     * Calculate incident-related days for the period.
     */
    private function calculateIncidentMetrics(
        Collection $incidents,
        Carbon $startDate,
        Carbon $endDate,
        Employee $employee,
        array $holidayDates,
    ): array {
        $vacationDays = 0;
        $sickLeaveDays = 0;
        $sickLeavePaidDays = 0;
        $permissionDays = 0;
        $permissionPaidDays = 0;
        $permissionUnpaidDays = 0;
        $absenceDays = 0;
        $lateAbsenceDays = 0;

        foreach ($incidents as $incident) {
            $incidentStart = Carbon::parse($incident->start_date);

            $category = $incident->incidentType->category;
            $isPaid = $incident->incidentType->is_paid;

            // Retardos→falta (FRT): la incidencia está fechada el día 1 del
            // mes siguiente al acumulado y carga days_count completo en el
            // periodo que CONTIENE esa fecha — nunca se prorratea por solape
            // (DECISIONES_NEGOCIO_2026-06-04.md §1).
            if ($category === 'late_accumulation') {
                if ($incidentStart->betweenIncluded($startDate, $endDate)) {
                    $frtDays = max(1, (int) $incident->days_count);
                    $lateAbsenceDays += $frtDays;
                    $absenceDays += $frtDays;
                }

                continue;
            }

            // Días del solape contados según el count_mode del TIPO
            // (DECISIONES §6): hábiles para vacaciones/permisos, calendario
            // para incapacidades — el mismo conteo que la captura y el saldo.
            $days = $this->incidentOverlapDays($incident, $startDate, $endDate, $employee, $holidayDates);

            if ($days <= 0) {
                continue;
            }

            // "Solo no pagar el día" (DECISIONES §5 revisada): ausencias y
            // permisos sin goce NO generan deducción monetaria — el día ya
            // vale $0 porque el sueldo base se paga por horas trabajadas.
            switch ($category) {
                case 'vacation':
                    $vacationDays += $days;
                    break;
                case 'sick_leave':
                    $sickLeaveDays += $days;
                    if ($isPaid) {
                        $sickLeavePaidDays += $days;
                    }
                    break;
                case 'permission':
                    $permissionDays += $days;
                    // Permiso con goce: lo paga el sueldo base (no se resta).
                    // Permiso sin goce: día no pagado, se resta del base plano
                    // (sin castigo del séptimo día).
                    if ($isPaid) {
                        $permissionPaidDays += $days;
                    } else {
                        $permissionUnpaidDays += $days;
                    }
                    break;
                case 'absence':
                    $absenceDays += $days;
                    break;
            }
        }

        return [
            'vacation_days' => $vacationDays,
            'sick_leave_days' => $sickLeaveDays,
            'sick_leave_paid_days' => $sickLeavePaidDays,
            'permission_days' => $permissionDays,
            'permission_paid_days' => $permissionPaidDays,
            'permission_unpaid_days' => $permissionUnpaidDays,
            'absence_days' => $absenceDays,
            'late_absence_days' => $lateAbsenceDays,
        ];
    }

    /**
     * Días del solape incidencia↔periodo según el count_mode del tipo:
     * calendario = días corridos; hábiles = solo días laborables del
     * empleado, excluyendo festivos.
     */
    private function incidentOverlapDays(
        Incident $incident,
        Carbon $startDate,
        Carbon $endDate,
        Employee $employee,
        array $holidayDates,
    ): int {
        // Fuente única del prorrateo con count_mode: vive en el modelo para
        // que nómina y reportes cuenten exactamente igual (auditoría #86).
        return $incident->overlapDays($startDate, $endDate, $employee, $holidayDates);
    }

    /**
     * Get period summary statistics.
     */
    public function getPeriodSummary(PayrollPeriod $period): array
    {
        $entries = $period->entries()->with('employee.department')->get();

        $totalGross = $entries->sum('gross_pay');
        $totalNet = $entries->sum('net_pay');
        $totalDeductions = $entries->sum('deductions');
        $totalOvertime = $entries->sum('overtime_pay');
        $employeeCount = $entries->count();

        $byDepartment = $entries->groupBy('employee.department.name')->map(function ($group) {
            return [
                'count' => $group->count(),
                'total_gross' => $group->sum('gross_pay'),
                'total_net' => $group->sum('net_pay'),
            ];
        });

        return [
            'employee_count' => $employeeCount,
            'total_gross' => $totalGross,
            'total_net' => $totalNet,
            'total_deductions' => $totalDeductions,
            'total_overtime' => $totalOvertime,
            'average_pay' => $employeeCount > 0 ? $totalNet / $employeeCount : 0,
            'by_department' => $byDepartment,
        ];
    }
}
