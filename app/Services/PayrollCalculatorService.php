<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\BreakfastClaim;
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

    /**
     * Contexto precargado del periodo en curso (solo durante calculatePeriod):
     * evita repetir por empleado las consultas de asistencia, incidencias,
     * autorizaciones y festivos. Null en el camino de un solo empleado.
     */
    private ?PayrollPeriodCalculationContext $periodContext = null;

    /**
     * Memo: ¿existe algún concepto activo con payment_period=weekly? Es un
     * dato de catálogo estable durante el cálculo; antes se consultaba por
     * empleado.
     */
    private ?bool $weeklyConceptsExist = null;

    /**
     * Memo: códigos de conceptos con pays_via_transfer=true (catálogo chico y
     * estable; antes un query por empleado formalizado).
     *
     * @var list<string>|null
     */
    private ?array $transferConceptCodes = null;

    private CompensationRateResolverService $resolver;

    private LateAbsenceService $lateAbsences;

    private \App\Services\Fiscal\FiscalDeductionService $fiscal;

    public function __construct(
        CompensationRateResolverService $resolver,
        LateAbsenceService $lateAbsences,
        \App\Services\Fiscal\FiscalDeductionService $fiscal,
        private \App\Services\Fiscal\EmployerQuotaCalculatorService $employerQuotas,
    ) {
        $this->resolver = $resolver;
        $this->lateAbsences = $lateAbsences;
        $this->fiscal = $fiscal;
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

        // Eager load compensation types + department (weekend unit rule) to avoid N+1.
        //
        // Alcance del periodo (nómina por departamento):
        // - department_id set  => SOLO ese departamento.
        // - department_id NULL => nómina GENERAL: todos menos los deptos que
        //   llevan su propia nómina (has_separate_payroll), p. ej. Taller.
        $employees = Employee::active()
            ->with([
                'compensationTypes' => fn ($q) => $q->wherePivot('is_active', true),
                'department',
                // El horario alimenta daily_salary_computed / isObligatoryWorkDay;
                // sin eager load se lazy-loadea una vez por empleado.
                'schedule',
            ])
            ->when($period->department_id, fn ($q) => $q->where('department_id', $period->department_id))
            ->when(
                $period->department_id === null,
                fn ($q) => $q->whereDoesntHave('department', fn ($d) => $d->where('has_separate_payroll', true))
            )
            ->get();

        // Precarga en LOTE lo que calculateEmployeePayroll consultaba por
        // empleado. El finally garantiza que el contexto no sobreviva al
        // periodo (los caminos de un solo empleado siguen consultando directo).
        if ($period->isUnified()) {
            $this->calculateUnifiedPeriod($period, $employees);
        } else {
            $this->periodContext = $this->buildPeriodContext($period, $employees);

            try {
                foreach ($employees as $employee) {
                    $this->calculateEmployeePayroll($period, $employee);
                }
            } finally {
                $this->periodContext = null;
            }
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
     * Pago UNIFICADO (Elias 2026-08-25): un solo recibo con el sueldo de la
     * SEMANA y los extras del MES.
     *
     * Se calcula en DOS pasadas con la lógica de siempre —la semanal sobre
     * start/end y la mensual sobre el rango de extras— y se suman en una sola
     * entrada. Así el dinero es idéntico al de las dos nóminas separadas de
     * antes (mismas reglas, mismas retenciones); lo único que cambia es que se
     * paga junto. Cada pasada trae su propio contexto en lote porque los rangos
     * de fechas son distintos.
     *
     * @param  \Illuminate\Support\Collection<int, Employee>  $employees
     */
    private function calculateUnifiedPeriod(PayrollPeriod $period, Collection $employees): void
    {
        $baseView = $period->calculationView('base');
        $extrasView = $period->calculationView('extras');

        $baseAttributes = [];

        $this->periodContext = $this->buildPeriodContext($baseView, $employees);
        try {
            foreach ($employees as $employee) {
                $baseAttributes[$employee->id] = $this->computeEntryAttributes($baseView, $employee);
            }
        } finally {
            $this->periodContext = null;
        }

        $this->periodContext = $this->buildPeriodContext($extrasView, $employees);
        try {
            foreach ($employees as $employee) {
                $base = $baseAttributes[$employee->id] ?? [];
                $extras = $this->computeEntryAttributes(
                    $extrasView,
                    $employee,
                    (float) ($base['regular_pay'] ?? 0) > 0,
                );

                PayrollEntry::updateOrCreate(
                    [
                        'payroll_period_id' => $period->id,
                        'employee_id' => $employee->id,
                    ],
                    $this->mergeUnifiedAttributes($base, $extras, $period),
                );
            }
        } finally {
            $this->periodContext = null;
        }
    }

    /**
     * Precarga en 4 consultas lo que el cálculo necesita de TODOS los
     * empleados del periodo (asistencia, incidencias aprobadas, autorizaciones
     * aprobadas/pagadas y festivos), agrupado por empleado. Las condiciones
     * replican EXACTAMENTE las consultas por-empleado de
     * computeEntryAttributes — solo cambia el número de viajes a la base.
     *
     * Las FRT mensuales se generan ANTES de leer incidencias (en lote), igual
     * que en el camino por-empleado, para que una FRT recién creada que caiga
     * en este periodo sí se lea.
     *
     * @param  \Illuminate\Support\Collection<int, Employee>  $employees
     */
    private function buildPeriodContext(PayrollPeriod $period, Collection $employees): PayrollPeriodCalculationContext
    {
        $startDate = Carbon::parse($period->start_date);
        $endDate = Carbon::parse($period->end_date);
        $startDateStr = $startDate->toDateString();
        $endDateStr = $endDate->toDateString();
        $employeeIds = $employees->pluck('id')->all();

        $this->lateAbsences->ensureForEmployees($employees);

        $attendanceByEmployee = AttendanceRecord::whereIn('employee_id', $employeeIds)
            ->whereBetween('work_date', [$startDateStr, $endDateStr])
            ->orderBy('id')
            ->get()
            ->groupBy('employee_id');

        $incidentsByEmployee = Incident::whereIn('employee_id', $employeeIds)
            ->where('status', 'approved')
            // Las incidencias "a cuenta de horas" (HxV) son vales de conversión a
            // la bolsa: no representan días tomados → invisibles para la nómina.
            ->where('converts_to_vacation_hours', false)
            ->where(function ($q) use ($startDateStr, $endDateStr) {
                $q->whereBetween('start_date', [$startDateStr, $endDateStr])
                    ->orWhereBetween('end_date', [$startDateStr, $endDateStr])
                    ->orWhere(function ($q2) use ($startDateStr, $endDateStr) {
                        $q2->where('start_date', '<=', $startDateStr)
                            ->where('end_date', '>=', $endDateStr);
                    });
            })
            ->with('incidentType')
            ->orderBy('id')
            ->get()
            ->groupBy('employee_id');

        $authorizationsByEmployee = Authorization::whereIn('employee_id', $employeeIds)
            ->whereBetween('date', [$startDate, $endDate])
            ->whereIn('status', [Authorization::STATUS_APPROVED, Authorization::STATUS_PAID])
            ->with('compensationType')
            ->orderBy('id')
            ->get()
            ->groupBy('employee_id');

        $holidays = Holiday::whereBetween('date', [$startDateStr, $endDateStr])->get();

        return new PayrollPeriodCalculationContext(
            periodId: $period->id,
            attendanceByEmployee: $attendanceByEmployee,
            incidentsByEmployee: $incidentsByEmployee,
            authorizationsByEmployee: $authorizationsByEmployee,
            holidays: $holidays,
            monthlyIncidentsEnsured: true,
            scope: $period->calculationScope,
        );
    }

    /**
     * Calculate payroll for a single employee in a period.
     */
    public function calculateEmployeePayroll(PayrollPeriod $period, Employee $employee): PayrollEntry
    {
        return PayrollEntry::updateOrCreate(
            [
                'payroll_period_id' => $period->id,
                'employee_id' => $employee->id,
            ],
            $this->entryAttributesFor($period, $employee),
        );
    }

    /**
     * Atributos del recibo de un empleado, SIN persistir.
     *
     * En un periodo normal es una sola pasada. En el UNIFICADO son dos (semana
     * + mes) sumadas: ver calculateUnifiedPeriod.
     *
     * @return array<string, mixed>
     */
    private function entryAttributesFor(PayrollPeriod $period, Employee $employee): array
    {
        if (! $period->isUnified()) {
            return $this->computeEntryAttributes($period, $employee);
        }

        $base = $this->computeEntryAttributes($period->calculationView('base'), $employee);
        $extras = $this->computeEntryAttributes(
            $period->calculationView('extras'),
            $employee,
            (float) ($base['regular_pay'] ?? 0) > 0,
        );

        return $this->mergeUnifiedAttributes($base, $extras, $period);
    }

    /**
     * Calcula (sin guardar) los atributos del recibo de un empleado en el
     * periodo/alcance dado.
     *
     * @param  bool  $suppressBaseSalaryConcepts  El sueldo base ya se le pagó en
     *                                            la otra pasada del pago unificado:
     *                                            los conceptos marcados "es sueldo"
     *                                            no se vuelven a pagar aquí.
     * @return array<string, mixed>
     */
    private function computeEntryAttributes(
        PayrollPeriod $period,
        Employee $employee,
        bool $suppressBaseSalaryConcepts = false,
    ): array {
        // Ensure compensation types are loaded for rate resolution
        if (! $employee->relationLoaded('compensationTypes')) {
            $employee->load(['compensationTypes' => fn ($q) => $q->wherePivot('is_active', true)]);
        }

        $startDate = Carbon::parse($period->start_date);
        $endDate = Carbon::parse($period->end_date);

        // Contexto del periodo (cálculo en lote): datos ya precargados por
        // buildPeriodContext. Null en el camino de un solo empleado.
        $ctx = ($this->periodContext
            && $this->periodContext->periodId === $period->id
            && $this->periodContext->scope === $period->calculationScope)
            ? $this->periodContext
            : null;

        // Regla mensual retardos→falta: garantizar que todo mes cerrado tenga
        // su incidencia FRT generada ANTES de leer las incidencias del periodo
        // (idempotente; la FRT cae en la primera nómina tras el cierre). En el
        // cálculo en lote ya se garantizó para todos en buildPeriodContext.
        if (! $ctx?->monthlyIncidentsEnsured) {
            $this->lateAbsences->ensureMonthlyIncidentsGenerated($employee);
        }

        // Compare DATE columns against plain date strings (not Carbon
        // datetimes): on SQLite a '2026-07-01' DATE sorts BEFORE the
        // '2026-07-01 00:00:00' bound and rows on the period boundary would be
        // silently dropped (MySQL treats them as equal).
        $startDateStr = $startDate->toDateString();
        $endDateStr = $endDate->toDateString();

        if ($ctx) {
            $attendance = $ctx->attendanceByEmployee->get($employee->id, collect());
            $incidents = $ctx->incidentsByEmployee->get($employee->id, collect());
            $holidays = $ctx->holidays;
            $approvedAuthorizations = $ctx->authorizationsByEmployee->get($employee->id, collect());
        } else {
            // Get attendance records for the period
            $attendance = AttendanceRecord::where('employee_id', $employee->id)
                ->whereBetween('work_date', [$startDateStr, $endDateStr])
                ->get();

            // Get approved incidents for the period
            $incidents = Incident::where('employee_id', $employee->id)
                ->where('status', 'approved')
                // HxV (vale de conversión a la bolsa): invisible para la nómina.
                ->where('converts_to_vacation_hours', false)
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

            // Get approved authorizations for the period (for holiday/weekend gating
            // AND to honor each authorization's specific compensation_type_id).
            $approvedAuthorizations = Authorization::where('employee_id', $employee->id)
                ->whereBetween('date', [$startDate, $endDate])
                ->whereIn('status', [Authorization::STATUS_APPROVED, Authorization::STATUS_PAID])
                ->with('compensationType')
                ->get();
        }

        $holidayDates = $holidays->pluck('date')->map(fn ($d) => Carbon::parse($d)->toDateString())->toArray();

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

        // Faltas injustificadas capturadas por INCIDENCIA (no por checada):
        // categoría 'absence' con is_paid=false. Descuentan SD×7/6 igual que una
        // falta de asistencia, deduplicadas por fecha contra las faltas ya
        // contadas en asistencia y los días trabajados, y excluyendo días
        // justificados. Es el canal de falta de los empleados sin checador.
        $incidentAbsenceDeductionDates = $this->incidentAbsenceDeductionDates(
            $incidents,
            $startDate,
            $endDate,
            $employee,
            $holidayDates,
            $justifiedDates,
            $metrics['days_absent_unjustified_dates'],
            $metrics['worked_dates'],
        );
        $incidentAbsenceDays = count($incidentAbsenceDeductionDates);

        // ----------------------------------------------------------------
        // Period payment scope.
        // The nómina is split in two: a WEEKLY period pays the base salary
        // minus absences/lates; a MONTHLY period pays the extras (overtime,
        // velada, holiday, weekend, special concepts) plus vacations and all
        // bonuses. A legacy BIWEEKLY period pays everything together.
        // ----------------------------------------------------------------
        $payBase = $period->paysBase();
        $payExtras = $period->paysExtras();

        // Formalizado (va a transferencia) vs efectivo. Se iza aquí porque el
        // reparto de la prima vacacional (semanal para formalizados, mensual
        // para efectivo) lo necesita antes del cálculo de extras.
        $baseInCash = $employee->paysBaseInCash();

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
                && ($this->weeklyConceptsExist ??= CompensationType::active()
                    ->where('payment_period', CompensationType::PAYMENT_PERIOD_WEEKLY)
                    ->exists()))
        );

        // ---- BASE (weekly): sueldo diario × días pagados del periodo ----
        // El sueldo se paga por DÍA, no por hora (Art. 90 LFT), y la semana se
        // cubre sobre 7 días: 6 laborables + el séptimo día de descanso pagado
        // (Art. 69). Las VACACIONES se pagan como parte del base en el periodo
        // semanal (igual que Contpaq, que las mete en el recibo de la semana con
        // días pagados = 7), así que NO se restan del base. Sí se restan la
        // incapacidad (la cubre el IMSS) y el permiso sin goce (día no pagado),
        // para no duplicar ni regalar pago.
        // Sueldo base SEMANAL = semana completa de 7 días (séptimo día, Art. 69).
        //
        // SIEMPRE se pagan los 7 días (inicio + 6) sin importar en qué día corte
        // el periodo (decisión de negocio 2026-07-15): la nómina a veces se saca
        // antes del día correcto y el trabajador no debe recibir semana corta
        // por eso. Las faltas, descuentos y extras de los días posteriores al
        // corte caen por fecha en el periodo siguiente, donde se ajustan.
        // Solo aplica al tipo semanal; quincenal/mensual conservan sus días
        // calendario. El prorrateo por alta/baja lo conserva paidCalendarDays.
        $baseStartDate = $startDate;
        $baseEndDate = $endDate;
        if ($payBase && $period->type === 'weekly') {
            $baseEndDate = $startDate->copy()->addDays(6);
        }
        $weekDays = $payBase ? $this->paidCalendarDays($employee, $baseStartDate, $baseEndDate) : 0;
        $daysPaidElsewhere = $payBase
            ? ($incidentMetrics['sick_leave_days']
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
        // Faltas injustificadas capturadas por incidencia (mismo castigo SD×7/6),
        // ya deduplicadas por fecha contra asistencia; solo en periodo base.
        $incidentAbsenceDeductionDays = $payBase ? $incidentAbsenceDays : 0;
        $absenceDeductionDays = $payBase
            ? ($metrics['days_absent_unjustified'] + $lateAbsencesGenerated + $incidentAbsenceDeductionDays)
            : 0;
        $deductions = $payBase
            ? round($absenceDeductionDays * $dailySalary * $restDayFactor, 2)
            : 0.0;

        // ---- Conceptos que SON el sueldo (Elias 2026-08-25) ----
        // Algunos sueldos (los del personal en periodo de prueba, por ejemplo)
        // se capturan como concepto. Si el periodo ya le está pagando sueldo
        // BASE a este empleado, el concepto no se vuelve a pagar: manda el
        // sueldo base. Si no cobra base (sueldo diario en 0), el concepto sigue
        // siendo su único pago y se paga igual que siempre.
        $suppressSalaryConcepts = $suppressBaseSalaryConcepts || ($payBase && $regularPay > 0);
        $suppressedSalaryConcepts = [];
        // Conceptos CAPTURADOS que no pagaron nada porque su monto está en $0
        // (caso Descuento Infonavit 2026-08-26): se reportan en el recibo en vez
        // de desaparecer sin avisar.
        $unpaidZeroAmountConcepts = [];
        // Fines de semana autorizados que no llegaron al umbral (se explican en
        // el recibo en vez de dejar la pregunta "¿por qué solo aparece uno?").
        $weekendUnits = 0;
        $weekendNotCounted = [];
        $dropSalaryConcepts = function (array $concepts) use ($suppressSalaryConcepts, &$suppressedSalaryConcepts): array {
            if (! $suppressSalaryConcepts) {
                return $concepts;
            }

            $kept = [];
            foreach ($concepts as $concept) {
                if (! empty($concept['is_base_salary_concept'])) {
                    $suppressedSalaryConcepts[] = [
                        'code' => $concept['code'] ?? null,
                        'name' => $concept['name'] ?? null,
                        'amount' => (float) ($concept['amount'] ?? 0),
                        'reason' => 'El periodo ya paga el sueldo base',
                    ];

                    continue;
                }
                $kept[] = $concept;
            }

            return $kept;
        };

        // ---- EXTRAS (monthly): overtime, velada, holiday, weekend, special
        // concepts, vacations and bonuses. Computed only when the period pays
        // extras so a weekly period never charges them. ----
        $veladaMetrics = $this->calculateVeladaMetrics($attendance);

        // Días SIN checada completa: el tiempo extra APROBADO vale por su
        // autorización (Dani 2026-07-08, caso Julissa: TE aprobado con la
        // salida no marcada). Cubre también a los exentos de asistencia (no
        // tienen checadas en absoluto). Los días con checada completa ya
        // aportan su overtime_authorized_hours topado al timecard vía
        // attendance_records; aquí solo se suman las fechas sin timecard
        // medible. Se excluye el FIN (weekend pull rule), que paga por
        // unidades en su propio camino.
        // Semanas en las que el colaborador está marcado como PERSONAL DE
        // ENTREGAS (Dani 2026-07-28): aunque tenga checada completa, su tiempo
        // extra se cuenta a la AUTORIZACIÓN (no se topa al timecard) porque
        // andaba en la calle y su checada no refleja lo trabajado — mismo
        // criterio que la velada. Los días de esas semanas se excluyen de las
        // fechas "medidas" para que caigan en la suma de OT sin timecard (una
        // sola vía, sin doble conteo).
        $deliveryPeriods = \App\Models\DeliveryPeriod::query()
            ->where('employee_id', $employee->id)
            ->get(['start_date', 'end_date'])
            ->map(fn ($p) => [$p->start_date->toDateString(), $p->end_date->toDateString()])
            ->all();

        $isDeliveryDate = function ($date) use ($deliveryPeriods) {
            $d = Carbon::parse($date)->toDateString();
            foreach ($deliveryPeriods as [$from, $to]) {
                if ($d >= $from && $d <= $to) {
                    return true;
                }
            }

            return false;
        };

        $measuredOtDates = $attendance
            ->filter(fn ($r) => $r->check_in && $r->check_out && ! $isDeliveryDate($r->work_date))
            ->map(fn ($r) => Carbon::parse($r->work_date)->toDateString())
            ->all();
        // Además de los días sin timecard, el excedente aprobado "fuera de
        // checada" (is_unbacked_extra, el split de Elias 2026-08-05) se paga
        // por autorización aunque el día SÍ tenga checada completa: aprobarlo
        // fue la decisión consciente de pagar extra no hecho en el reloj. No se
        // duplica: VeladaCalculatorService lo excluye del mín(detectado,
        // autorizado) del timecard, y el || lo cuenta una sola vez cuando el
        // día tampoco está medido.
        $unbackedOvertimeHours = (float) $approvedAuthorizations
            ->filter(fn (Authorization $a) => $a->type === Authorization::TYPE_OVERTIME
                && ! ($a->compensationType?->hasWeekendPullRule())
                && ($a->is_unbacked_extra
                    || ! in_array(Carbon::parse($a->date)->toDateString(), $measuredOtDates, true)))
            ->sum('hours');
        if ($unbackedOvertimeHours > 0) {
            $veladaMetrics['overtime_authorized_hours'] = round(
                $veladaMetrics['overtime_authorized_hours'] + $unbackedOvertimeHours,
                2,
            );
        }

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
        // Descuentos autorizados (monto único con cantidad negativa): se
        // acumulan aquí y se suman a $conceptDeductions más abajo — mismo
        // trato que un recurrente negativo (salen del efectivo, con tope).
        $authorizedConceptDeductions = 0.0;

        // Night-shift metrics feed both the comp-types velada input and the
        // legacy dinner/night bonus. Se calculan cuando se pagan extras o cuando
        // la ruta de conceptos corre en un periodo base con conceptos semanales.
        if ($payExtras || $runCompTypes) {
            // FASE 3.3: Night shifts and dinner allowances — pagados solo por
            // noche realmente trabajada (velada en checadas) y autorizada.
            $nightShiftMetrics = $this->calculateNightShiftMetrics($approvedAuthorizations, $attendance);
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

            // Unidades de fin de semana por día autorizado (Almacén): cada día con
            // autorización FIN cuenta al menos 1 aunque trabaje < 1 unidad (regla
            // de Dani 2026-06-28); 12 h = 2. Se calcula desde las autorizaciones
            // (no del status) para que un sábado marcado "ausente" pero trabajado
            // y autorizado sí pague.
            $weekendResult = $this->calculateWeekendUnits($attendance, $approvedAuthorizations, $employee);
            $weekendUnits = $weekendResult['units'];
            $weekendNotCounted = $weekendResult['not_counted'];

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
                    'weekend_units' => $weekendUnits,
                ],
                $hourlyRate,
                $dailySalary,
                $approvedAuthorizations,
                $holidayDates,
                $weekendUnitHours,
                $allowedPaymentPeriods,
            );

            $compensationConcepts = $dropSalaryConcepts($compensationPayments['concepts']);
            $unpaidZeroAmountConcepts = $compensationPayments['zero_amount'] ?? [];

            // Route each concept to its stored pay bucket. Overtime/velada
            // match by code; holiday/weekend/special match by the comp
            // type's authorization_type / attendance_pull_rule.
            foreach ($compensationConcepts as $concept) {
                $code = $concept['code'] ?? '';
                $authType = $concept['authorization_type'] ?? null;
                $pullRule = $concept['attendance_pull_rule'] ?? null;

                // Un monto NEGATIVO (descuento de monto único) es una DEDUCCIÓN:
                // no resta de un bucket de percepción; se descuenta del efectivo
                // con tope, igual que los recurrentes negativos. El concepto se
                // queda en el desglose con su signo para el recibo.
                if ((float) $concept['amount'] < 0) {
                    $authorizedConceptDeductions += abs((float) $concept['amount']);

                    continue;
                }

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

        // Incapacidad, prima vacacional y bonos son mensuales (pasada de extras).
        // El SALARIO del día de vacación ya se pagó como base en el periodo
        // semanal (como Contpaq), por eso vacation_pay queda en 0 aquí. El
        // fallback legado (sin conceptos) también es mensual, así que un periodo
        // semanal nunca cobra extras legados.
        if ($payExtras) {
            // Prima vacacional (DECISIONES §3): se paga con cada día de vacación
            // con el % del empleado. El día en sí ya va en el base (semanal).
            $vacationPremiumPay = round($incidentMetrics['vacation_days'] * $dailySalary
                * ((float) ($employee->vacation_premium_percentage ?? 0) / 100), 2);

            // Los FORMALIZADOS (transferencia) cobran su prima vacacional en la
            // nómina SEMANAL por transferencia, como Contpaq (se calcula abajo en
            // el periodo base). Aquí en la mensual se suprime para no pagarla
            // doble; los empleados de EFECTIVO la siguen cobrando en la mensual.
            if (! $baseInCash) {
                $vacationPremiumPay = 0.0;
            }

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

        // ---- Percepciones por TRANSFERENCIA de FORMALIZADOS (como Contpaq) ----
        // Contpaq paga ciertas percepciones junto con el sueldo de la semana, por
        // transferencia (no en efectivo ni en la mensual): la PRIMA VACACIONAL, el
        // bono de CUMPLEAÑOS (1 día de sueldo la semana del cumpleaños) y cualquier
        // concepto marcado pays_via_transfer (aguinaldo, etc.). En el periodo BASE
        // (semanal) se suman a $transferExtras para que el reparto las mande al
        // BANCO; siguen contando en gross/net vía su bucket. Cada percepción de
        // transferencia se marca via_transfer=true en el concepto para que el
        // recibo la muestre en la transferencia y NO en el efectivo.
        $transferExtras = 0.0;
        // Gravado de las percepciones por transferencia (LISR Art. 93): la
        // prima vacacional es exenta hasta 15 UMA, el aguinaldo hasta 30 UMA;
        // el cumpleaños/bonos son 100% gravados. Alimenta la base del ISR —
        // p.ej. un salario mínimo con cumpleaños pierde la exención y paga ISR
        // sobre todo (verificado vs Contpaq FOFH/LOAX/LOLV).
        $taxableTransferExtras = 0.0;
        if ($payBase && ! $baseInCash) {
            $umaDaily = (float) SystemSetting::get('fiscal_uma_daily', 117.31);

            // Prima vacacional (en la mensual se suprimió arriba para no doblar).
            $weeklyVacationPremium = round($incidentMetrics['vacation_days'] * $dailySalary
                * ((float) ($employee->vacation_premium_percentage ?? 0) / 100), 2);
            if ($weeklyVacationPremium > 0) {
                $vacationPremiumPay += $weeklyVacationPremium;
                $transferExtras += $weeklyVacationPremium;
                // Exenta hasta 15 UMA; solo el excedente grava.
                $taxableTransferExtras += max(0.0, $weeklyVacationPremium - 15 * $umaDaily);
            }

            // Bono de cumpleaños: 1 día de sueldo si el cumpleaños del empleado
            // (mes/día) cae dentro del periodo. Inerte hasta que se capture la
            // fecha de nacimiento (birth_date NULL → no paga).
            if ($dailySalary > 0 && $employee->birthdayFallsBetween($startDate, $endDate)) {
                $birthdayBonus = round($dailySalary, 2);
                $otherCompensationPay += $birthdayBonus;
                $transferExtras += $birthdayBonus;
                $taxableTransferExtras += $birthdayBonus; // 100% gravado
                $compensationConcepts[] = [
                    'code' => 'CUMPLE',
                    'name' => 'Cumpleaños',
                    'hours' => 0,
                    'days' => 1,
                    'quantity' => 1,
                    'rate' => ['percentage' => null, 'fixed_amount' => $birthdayBonus],
                    'amount' => $birthdayBonus,
                    'via_transfer' => true,
                    'source' => 'birthday',
                ];
            }

            // AGUINALDO anual automático (LFT Art. 87): el periodo semanal que
            // CONTIENE la fecha configurada (fiscal_aguinaldo_payment_date)
            // paga el proporcional: días de aguinaldo × SD × (días trabajados
            // del año / 365). Exento hasta 30 UMA (LISR Art. 93 XIV). Sin
            // fecha configurada no paga nada; idempotente al recálculo.
            $aguinaldoDate = (string) SystemSetting::get('fiscal_aguinaldo_payment_date', '');
            if ($aguinaldoDate !== '' && $dailySalary > 0) {
                try {
                    $payDay = Carbon::parse($aguinaldoDate);
                } catch (\Throwable) {
                    $payDay = null;
                }
                if ($payDay && $payDay->betweenIncluded($startDate, $endDate)) {
                    $aguinaldoDays = (float) SystemSetting::get('fiscal_aguinaldo_days', 15);
                    $yearStart = $payDay->copy()->startOfYear();
                    $workedFrom = $employee->hire_date && $employee->hire_date->gt($yearStart)
                        ? $employee->hire_date->copy()
                        : $yearStart;
                    // Proporción por días CALENDARIO del año trabajados (año
                    // completo = aguinaldo completo, como la práctica LFT).
                    $daysWorked = min(365, max(0, (int) $workedFrom->diffInDays($payDay->copy()->endOfYear()) + 1));
                    $aguinaldoAmount = round($aguinaldoDays * $dailySalary * ($daysWorked / 365), 2);
                    if ($aguinaldoAmount > 0) {
                        $otherCompensationPay += $aguinaldoAmount;
                        $transferExtras += $aguinaldoAmount;
                        $taxableTransferExtras += max(0.0, $aguinaldoAmount - 30 * $umaDaily);
                        $compensationConcepts[] = [
                            'code' => 'AGUINALDO',
                            'name' => 'Aguinaldo',
                            'hours' => 0,
                            'days' => round($aguinaldoDays * $daysWorked / 365, 2),
                            'quantity' => 1,
                            'rate' => ['percentage' => null, 'fixed_amount' => $aguinaldoAmount],
                            'amount' => $aguinaldoAmount,
                            'via_transfer' => true,
                            'source' => 'aguinaldo',
                        ];
                    }
                }
            }
        }

        // Dinner & night-shift bonus: when the employee is on the
        // CompensationType path, dinner is paid solely from approved CENA
        // authorizations and the velada is paid per hour via VEL, so BOTH
        // legacy fixed concepts are suppressed to avoid double-paying
        // (DECISIONES_NEGOCIO_2026-06-04.md §2).
        $dinnerAllowance = $useCompTypes ? 0.0 : $nightShiftMetrics['dinner_allowance'];
        $nightShiftBonusPay = $useCompTypes ? 0.0 : $nightShiftMetrics['night_shift_bonus'];

        // ---- Desayunos (vendedor) ----
        // El total de desayunos entregados en el kiosco durante el periodo se
        // paga al empleado VENDEDOR configurado, en el periodo que paga BASE
        // (semanal). Se suma desde los snapshots de breakfast_claims — cada
        // claim congeló su precio — así que el recálculo es idempotente y un
        // cambio de precio a mitad de semana no altera lo ya cobrado. Corre
        // fuera de la ruta de conceptos: el vendedor puede no tener conceptos
        // ni sueldo diario y aun así cobrar sus desayunos.
        $breakfastCount = 0;
        $breakfastPay = 0.0;
        $breakfastVendorId = (int) SystemSetting::get('breakfast_vendor_employee_id', 0);
        if ($payBase && $breakfastVendorId > 0 && $employee->id === $breakfastVendorId) {
            $breakfastClaims = BreakfastClaim::whereBetween('claim_date', [$startDateStr, $endDateStr])->get();
            $breakfastCount = $breakfastClaims->count();
            $breakfastPay = round((float) $breakfastClaims->sum('unit_cost'), 2);

            if ($breakfastPay > 0) {
                $otherCompensationPay += $breakfastPay;
                $compensationConcepts[] = [
                    'code' => 'DES',
                    'name' => 'Desayunos',
                    'hours' => 0,
                    'days' => 0,
                    'quantity' => $breakfastCount,
                    'rate' => ['percentage' => null, 'fixed_amount' => 0.0],
                    'amount' => $breakfastPay,
                    'source' => 'breakfast_claims',
                ];
            }
        }

        // ---- Conceptos RECURRENTES (Luis 2026-07-09) ----
        // Cantidades fijas que se dan al empleado CADA periodo (semanal o
        // mensual, según payment_period del concepto) de forma automática, sin
        // autorización ni condición de asistencia. Corre para todo empleado con
        // conceptos, en el periodo cuyo payment_period coincide
        // ($allowedPaymentPeriods): así un concepto semanal cae en la nómina
        // semanal y uno mensual en la mensual, una sola vez por periodo.
        //
        // Un concepto con monto NEGATIVO es una DEDUCCIÓN (Infonavit, préstamo,
        // etc.): en vez de sumar, se acumula en $conceptDeductions y se descuenta
        // del EFECTIVO más abajo, topada al efectivo disponible ("de su sueldo en
        // efectivo se tiene que poder descontar siempre y cuando no rebase el
        // importe" — Luis 2026-07-09). No toca la transferencia (base). Arranca
        // con los descuentos autorizados (monto único con cantidad negativa).
        $conceptDeductions = $authorizedConceptDeductions;
        if ($useCompTypes && $allowedPaymentPeriods !== []) {
            $recurringConcepts = $dropSalaryConcepts($this->resolver->calculateRecurringConcepts(
                $employee,
                $hourlyRate,
                $dailySalary,
                $allowedPaymentPeriods,
            ));
            foreach ($recurringConcepts as $concept) {
                if ($concept['amount'] < 0) {
                    $conceptDeductions += abs($concept['amount']);
                } else {
                    $otherCompensationPay += $concept['amount'];
                }
                $compensationConcepts[] = $concept;
            }
        }

        // Conceptos marcados pays_via_transfer (aguinaldo y demás): para los
        // formalizados se mueven del efectivo a la TRANSFERENCIA. Corre aquí, con
        // TODOS los conceptos ya ensamblados (comp types + recurrentes). El monto
        // ya está en gross/net vía su bucket; sumarlo a $transferExtras solo cambia
        // el reparto (banco). Cada uno se marca via_transfer para el recibo.
        if ($payBase && ! $baseInCash) {
            $conceptCodes = collect($compensationConcepts)->pluck('code')->filter()->unique()->all();
            if (! empty($conceptCodes)) {
                // Catálogo chico y estable: se trae UNA vez la lista completa de
                // códigos pays_via_transfer y se intersecta en memoria (mismo
                // resultado que el whereIn por empleado de antes).
                $this->transferConceptCodes ??= CompensationType::where('pays_via_transfer', true)
                    ->pluck('code')
                    ->all();
                $transferCodes = array_values(array_intersect($conceptCodes, $this->transferConceptCodes));
                if (! empty($transferCodes)) {
                    $umaDailyConcepts = (float) SystemSetting::get('fiscal_uma_daily', 117.31);
                    foreach ($compensationConcepts as &$concept) {
                        if (in_array(($concept['code'] ?? ''), $transferCodes, true) && empty($concept['via_transfer'])) {
                            $amount = (float) $concept['amount'];
                            $transferExtras += $amount;
                            $concept['via_transfer'] = true;
                            // Gravado: el aguinaldo es exento hasta 30 UMA
                            // (LISR Art. 93 XIV); otros conceptos gravan completos.
                            $taxableTransferExtras += ($concept['code'] === 'AGUIN')
                                ? max(0.0, $amount - 30 * $umaDailyConcepts)
                                : $amount;
                        }
                    }
                    unset($concept);
                }
            }
        }

        // Calculate total bonuses (0 on a weekly period)
        $totalBonuses = $punctualityBonus + $weeklyBonus + $monthlyBonus
            + $nightShiftBonusPay
            + $dinnerAllowance;

        $basePay = $payBase ? $regularPay : 0.0;
        $grossPay = $basePay + $overtimePay + $veladaPay + $holidayPay + $weekendPay
            + $otherCompensationPay + $vacationPay + $vacationPremiumPay + $sickLeavePay
            + $totalBonuses;
        // La nómina de un empleado nunca es negativa: si la deducción por faltas
        // supera lo devengado (muchas faltas / jornada con domingo), el neto se
        // topa en 0 en vez de arrastrar un saldo negativo.
        $netPay = max(0.0, round($grossPay - $deductions, 2));

        // ---- Reparto efectivo / banco ----
        // El sueldo BASE se paga en EFECTIVO solo cuando el empleado sigue en
        // periodo de prueba Y aún NO está inscrito al IMSS; en cualquier otro
        // caso el base neto va por TRANSFERENCIA (banco/CONTPAQi). Los EXTRAS
        // (overtime, velada, festivo, finde, conceptos, bonos) salen SIEMPRE en
        // efectivo. La fórmula es única para los tres tipos de periodo: en
        // mensual basePay y deductions son 0, así que bank=0 y cash=net_pay (los
        // extras). NO altera regular_pay/gross_pay/net_pay.
        if ($baseInCash) {
            $cashAmount = round($netPay, 2);
            $bankAmount = 0.0;
        } else {
            // El base neto MÁS los extras de transferencia (prima vacacional de
            // los formalizados, como Contpaq) van al BANCO; los demás extras al
            // efectivo. En la mensual basePay/deductions/transferExtras son 0.
            $bankAmount = max(0.0, round($basePay - $deductions + $transferExtras, 2));
            $cashAmount = round($netPay - $bankAmount, 2);
        }

        // ---- Retenciones fiscales del trabajador (ISR + IMSS + Infonavit − subsidio) ----
        // Solo aplican a empleados FORMALIZADOS (los que cobran base por banco) en el
        // periodo que paga base. Reducen la TRANSFERENCIA y el neto — nunca el
        // efectivo (que son los extras). La base gravable del ISR es el sueldo base
        // del periodo (incluye vacaciones pagadas en base, todas gravables).
        $fiscalDeductions = ['isr' => 0.0, 'imss' => 0.0, 'infonavit' => 0.0, 'subsidy' => 0.0, 'total' => 0.0];
        $isrTaxableBase = 0.0;
        $netAdjustment = 0.0;
        $employerCosts = null;
        if ($payBase && ! $baseInCash) {
            // Base gravable = sueldo base + gravado de las percepciones por
            // transferencia (prima excedente de 15 UMA, cumpleaños, aguinaldo
            // excedente de 30 UMA). Las faltas enteras van por ausentismo IMSS
            // (Art. 31 LSS): reducen los días cotizados de IV+CyV.
            $isrTaxableBase = round($regularPay + $taxableTransferExtras, 2);
            $fiscalDeductions = $this->fiscal->compute($employee, $isrTaxableBase, (float) $weekDays, (int) $absenceDeductionDays);
            if (abs($fiscalDeductions['total']) > 0.001) {
                $bankAmount = max(0.0, round($bankAmount - $fiscalDeductions['total'], 2));
                $netPay = max(0.0, round($netPay - $fiscalDeductions['total'], 2));
            }

            // Costo PATRONAL (informativo/provisión, no toca el pago del
            // trabajador): cuotas IMSS de la empresa por ramo, SAR, Infonavit,
            // RT e ISN sobre las percepciones formales del periodo. Solo con
            // retenciones activas (mismo gate que las deducciones).
            if ((bool) SystemSetting::get('fiscal_retentions_enabled', false)) {
                $sbcForEmployer = (float) ($employee->sbc ?: $employee->sdi ?: 0);
                $employerCosts = $this->employerQuotas->quotas(
                    $sbcForEmployer,
                    (float) $weekDays,
                    (int) $absenceDeductionDays,
                    $dailySalary,
                    round($regularPay + $transferExtras, 2),
                );
            }

            // Ajuste al neto (concepto 99 de Contpaq): el neto transferido se
            // redondea al múltiplo de $0.20 más cercano; los centavos de ajuste
            // se guardan aparte (recibo/CFDI). Solo con retenciones activas.
            if ($bankAmount > 0
                && (bool) SystemSetting::get('fiscal_retentions_enabled', false)
                && (bool) SystemSetting::get('fiscal_net_adjustment_enabled', true)) {
                $roundedBank = round(round($bankAmount * 5) / 5, 2);
                $netAdjustment = round($roundedBank - $bankAmount, 2);
                if (abs($netAdjustment) > 0.001) {
                    $bankAmount = $roundedBank;
                    $netPay = round($netPay + $netAdjustment, 2);
                }
            }
        }

        // Deducciones de concepto (Infonavit, préstamos): salen del EFECTIVO y se
        // topan al efectivo disponible, nunca lo dejan negativo ("siempre y
        // cuando no rebase el importe" — Luis 2026-07-09). No tocan la
        // transferencia. Lo que no alcance a descontarse este periodo (excede el
        // efectivo) simplemente no se aplica; el neto refleja solo lo aplicado.
        $appliedConceptDeduction = round(min($conceptDeductions, max(0.0, $cashAmount)), 2);
        if ($appliedConceptDeduction > 0) {
            $cashAmount = round($cashAmount - $appliedConceptDeduction, 2);
            $netPay = round($netPay - $appliedConceptDeduction, 2);
        }

        // Detalle de la deducción por falta, para el recibo/detalle: qué días y
        // por qué se descuenta. Cada falta descuenta SD × 7/6 (séptimo día
        // incluido); la suma de 'days' = absence_deduction_days. Solo en el
        // periodo que paga base (el mensual no descuenta faltas).
        // Detalle de la(s) falta(s) por acumulación de retardos: por cada FRT que
        // cae en el periodo, qué mes acumuló y CUÁLES fueron los retardos (fechas)
        // que la originaron, para que el recibo lo explique (no solo "por retardos").
        $lateAccumulationDetail = [];
        foreach (($incidentMetrics['late_absence_incidents'] ?? []) as $frt) {
            $month = $frt['month'];
            $lateDates = [];
            if ($month) {
                $mStart = Carbon::parse($month.'-01')->startOfMonth();
                $lateDates = AttendanceRecord::where('employee_id', $employee->id)
                    ->whereBetween('work_date', [$mStart->toDateString(), $mStart->copy()->endOfMonth()->toDateString()])
                    ->where('status', 'late')
                    ->orderBy('work_date')
                    ->pluck('work_date')
                    ->map(fn ($d) => Carbon::parse($d)->toDateString())
                    ->values()
                    ->all();
            }
            $lateAccumulationDetail[] = [
                'month' => $month,
                'days' => $frt['days'],
                'reason' => $frt['reason'],
                'late_dates' => $lateDates,
            ];
        }

        $deductionDetail = [];
        if ($payBase) {
            foreach (array_keys($metrics['days_absent_unjustified_dates']) as $date) {
                $deductionDetail[] = ['date' => $date, 'reason' => 'Falta injustificada', 'days' => 1];
            }
            foreach (array_keys($incidentAbsenceDeductionDates) as $date) {
                $deductionDetail[] = ['date' => $date, 'reason' => 'Falta por incidencia', 'days' => 1];
            }
            usort($deductionDetail, fn ($a, $b) => strcmp((string) $a['date'], (string) $b['date']));
            if ($lateAbsencesGenerated > 0) {
                // Falta(s) por retardos: acumulación mensual, con el detalle de
                // cuáles retardos y de qué mes provienen.
                $deductionDetail[] = [
                    'date' => null,
                    'reason' => 'Falta por acumulación de retardos',
                    'days' => $lateAbsencesGenerated,
                    'late_detail' => $lateAccumulationDetail,
                ];
            }
        }

        // Build calculation breakdown for transparency
        $breakdown = [
            'deduction_detail' => $deductionDetail,
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
                // faltas por retardos (FRT) + faltas capturadas por incidencia
                // (empleados sin checador / correcciones manuales). Los días
                // pagados aparte o no pagados se restan del base, no se
                // descuentan con castigo.
                'absence_deduction_days' => $absenceDeductionDays,
                'absence_incident_deduction_days' => $incidentAbsenceDeductionDays,
            ],
            'late_accumulation' => [
                'late_absences_generated' => $lateAbsencesGenerated,
                'source' => 'frt_incidents_mensuales',
                'detail' => $lateAccumulationDetail,
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
            'weekend' => [
                'units' => $weekendUnits,
                // Días FIN aprobados que no contaron (y por qué).
                'not_counted' => $weekendNotCounted,
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
            // Conceptos "es sueldo" que NO se pagaron porque el periodo ya le
            // pagó el sueldo base (evita el doble sueldo del personal de prueba).
            'suppressed_base_salary_concepts' => $suppressedSalaryConcepts,
            // Conceptos capturados y aprobados que pagaron $0 porque el concepto
            // no tiene monto configurado: hay que corregir el catálogo.
            'unpaid_zero_amount_concepts' => $unpaidZeroAmountConcepts,
            'scope' => [
                'period_type' => $period->type,
                'pays_base' => $payBase,
                'pays_extras' => $payExtras,
                // Días pagados del periodo base (insumo de acumulados anuales).
                'week_days' => $payBase ? (float) $weekDays : 0.0,
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
                // 'deductions' es el total (faltas + deducciones de concepto ya
                // aplicadas del efectivo). El split se expone aparte para el
                // recibo: 'absence_deductions' (faltas) y 'concept_deductions'
                // (Infonavit, préstamos).
                'deductions' => round($deductions + $appliedConceptDeduction, 2),
                'absence_deductions' => $deductions,
                'concept_deductions' => $appliedConceptDeduction,
                'net_pay' => $netPay,
            ],
            // Retenciones fiscales del trabajador (formalizados): ISR + IMSS +
            // Infonavit − subsidio. Reducen la transferencia.
            'fiscal' => [
                'isr' => $fiscalDeductions['isr'],
                'imss' => $fiscalDeductions['imss'],
                'infonavit' => $fiscalDeductions['infonavit'],
                'subsidy' => $fiscalDeductions['subsidy'],
                'total' => $fiscalDeductions['total'],
                // Base gravable del ISR (sueldo base + gravado de percepciones
                // por transferencia) y ajuste del neto a múltiplo de $0.20.
                'taxable_base' => $isrTaxableBase,
                'taxable_transfer_extras' => round($taxableTransferExtras ?? 0.0, 2),
                'transfer_extras_total' => round($transferExtras, 2),
                'net_adjustment' => $netAdjustment,
            ],
            // Costo patronal del periodo (cuotas IMSS empresa por ramo, SAR,
            // Infonavit, RT, ISN): informativo/provisión; null si no aplica.
            'employer_costs' => $employerCosts,
        ];

        // Atributos del recibo (los persiste calculateEmployeePayroll; en el
        // pago unificado se suman antes las dos pasadas).
        return [
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
            'days_absent' => $payBase ? ($metrics['days_absent'] + $lateAbsencesGenerated + $incidentAbsenceDeductionDays) : 0,
            'days_late' => $payBase ? $metrics['days_late'] : 0,
            'punctuality_days' => $payExtras ? $metrics['punctual_days'] : 0,
            'night_shift_days' => $payExtras ? $nightShiftMetrics['night_shift_days'] : 0,
            'late_absences_generated' => $lateAbsencesGenerated,
            'vacation_days_paid' => $payBase ? $incidentMetrics['vacation_days'] : 0,
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
            'deductions' => round($deductions + $appliedConceptDeduction, 2),
            'isr_amount' => $fiscalDeductions['isr'],
            'imss_amount' => $fiscalDeductions['imss'],
            'infonavit_amount' => $fiscalDeductions['infonavit'],
            'subsidy_amount' => $fiscalDeductions['subsidy'],
            'net_adjustment' => $netAdjustment,
            'gross_pay' => $grossPay,
            'net_pay' => $netPay,
            'cash_amount' => $cashAmount,
            'bank_amount' => $bankAmount,
            'calculation_breakdown' => $breakdown,
        ];
    }

    /**
     * Campos del recibo que NO se suman entre las dos pasadas del pago
     * unificado: son tasas/multiplicadores del empleado, idénticos en ambas.
     *
     * @var list<string>
     */
    private const UNIFIED_RATE_FIELDS = [
        'hourly_rate',
        'daily_salary',
        'overtime_multiplier',
        'holiday_multiplier',
        'velada_multiplier',
    ];

    /**
     * Suma las dos pasadas del pago unificado (semana + mes) en un solo recibo.
     *
     * Sumar es exacto porque cada pasada deja en CERO lo que le toca a la otra
     * (la semanal no cobra extras y la mensual no paga base ni retenciones):
     * el resultado es, peso por peso, lo que antes se pagaba en dos nóminas.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $extras
     * @return array<string, mixed>
     */
    private function mergeUnifiedAttributes(array $base, array $extras, PayrollPeriod $period): array
    {
        $merged = $base;

        foreach ($extras as $key => $value) {
            if ($key === 'calculation_breakdown' || in_array($key, self::UNIFIED_RATE_FIELDS, true)) {
                continue;
            }

            $merged[$key] = round((float) ($base[$key] ?? 0) + (float) $value, 2);
        }

        $merged['calculation_breakdown'] = $this->mergeUnifiedBreakdown(
            $base['calculation_breakdown'] ?? [],
            $extras['calculation_breakdown'] ?? [],
            $period,
        );

        return $merged;
    }

    /**
     * Une los desgloses de las dos pasadas conservando, bloque por bloque, el
     * alcance dueño de cada dato: la asistencia/faltas/retenciones vienen de la
     * SEMANA y las horas extra/velada/bonos del MES — exactamente el mismo
     * reparto que hacen las columnas del recibo.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $extras
     * @return array<string, mixed>
     */
    private function mergeUnifiedBreakdown(array $base, array $extras, PayrollPeriod $period): array
    {
        $merged = $base;

        // Bloques que son íntegros de la pasada de EXTRAS.
        foreach (['unauthorized', 'night_shifts', 'velada', 'weekend', 'bonuses'] as $block) {
            if (array_key_exists($block, $extras)) {
                $merged[$block] = $extras[$block];
            }
        }

        // Horas de extras dentro del bloque de asistencia (las horas regulares,
        // los días trabajados y la puntualidad los aporta cada dueño).
        foreach (['overtime_hours', 'holiday_hours', 'weekend_hours', 'punctual_days'] as $key) {
            $merged['attendance'][$key] = $extras['attendance'][$key] ?? ($base['attendance'][$key] ?? 0);
        }
        $merged['attendance']['records_extras'] = $extras['attendance']['records'] ?? 0;

        // Incapacidad: la paga la pasada de extras.
        foreach (['sick_leave_days', 'sick_leave_paid_days'] as $key) {
            $merged['incidents'][$key] = $extras['incidents'][$key] ?? ($base['incidents'][$key] ?? 0);
        }

        $merged['compensation_concepts'] = array_merge(
            $base['compensation_concepts'] ?? [],
            $extras['compensation_concepts'] ?? [],
        );
        $merged['suppressed_base_salary_concepts'] = array_merge(
            $base['suppressed_base_salary_concepts'] ?? [],
            $extras['suppressed_base_salary_concepts'] ?? [],
        );
        $merged['unpaid_zero_amount_concepts'] = array_merge(
            $base['unpaid_zero_amount_concepts'] ?? [],
            $extras['unpaid_zero_amount_concepts'] ?? [],
        );

        // Dinero: las dos pasadas suman.
        $calculations = $base['calculations'] ?? [];
        foreach (($extras['calculations'] ?? []) as $key => $value) {
            $calculations[$key] = round((float) ($calculations[$key] ?? 0) + (float) $value, 2);
        }
        $merged['calculations'] = $calculations;

        $merged['scope'] = [
            'period_type' => $base['scope']['period_type'] ?? $period->type,
            // Pago UNIFICADO: la vista lo anuncia como "semana + extras del mes".
            'unified' => true,
            'pays_base' => true,
            'pays_extras' => true,
            'week_days' => $base['scope']['week_days'] ?? 0.0,
            'base_range' => [
                'start' => $period->start_date?->toDateString(),
                'end' => $period->end_date?->toDateString(),
            ],
            'extras_range' => [
                'start' => $period->extras_start_date?->toDateString(),
                'end' => $period->extras_end_date?->toDateString(),
            ],
        ];

        return $merged;
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
     * @param  Collection  $approvedAuthorizations  Autorizaciones aprobadas/pagadas del periodo (ya cargadas)
     * @param  Collection  $attendance  Attendance records of the period
     * @return array Night shift metrics
     */
    private function calculateNightShiftMetrics(Collection $approvedAuthorizations, Collection $attendance): array
    {
        $nightShiftBonus = (float) SystemSetting::get('night_shift_bonus', 100);
        $dinnerAllowanceAmount = (float) SystemSetting::get('dinner_allowance_amount', 75);

        // Autorizaciones de velada del periodo: mismo universo que la consulta
        // anterior por empleado (aprobadas/pagadas, en rango) filtrado por tipo.
        $approvedNightShifts = $approvedAuthorizations
            ->filter(fn ($authorization) => $authorization->type === Authorization::TYPE_NIGHT_SHIFT);

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
        // Sets por fecha 'Y-m-d' para deduplicar la falta capturada por
        // incidencia contra la falta ya contada por checada, y para que la
        // "evidencia de trabajo gane" (un día trabajado nunca descuenta).
        $daysAbsentUnjustifiedDates = [];
        $workedDates = [];

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
                // Exentos de checador ("No checa"): un absent residual — creado
                // por el sync ANTES de marcar la casilla — no cuenta ni
                // descuenta. Cobran su sueldo completo y sus faltas se capturan
                // por incidencia. Así, marcar la casilla + recalcular limpia
                // las faltas sin borrar registros a mano (caso Adriana/Eloy,
                // Elias 2026-08-07).
                if ($employee->is_attendance_exempt) {
                    continue;
                }
                // Holidays never count as ausencias even if a stale row was
                // synced with status='absent' before the holiday was registered.
                if ($isHoliday) {
                    continue;
                }
                // Los días NO obligatorios nunca cuentan como ausencia: los que no
                // son laborables del horario y, desde 2026-07-08 (Dani), TODO
                // sábado y domingo en cualquier depto (dejaron de ser obligatorios).
                if (! $employee->isObligatoryWorkDay($workDate)) {
                    continue;
                }
                $daysAbsent++;

                // Una falta solo descuenta (séptimo día incluido) cuando NO está
                // justificada por una incidencia aprobada (vacación, permiso con
                // goce, incapacidad, falta justificada).
                if (! isset($justifiedDates[$workDateStr])) {
                    $daysAbsentUnjustified++;
                    $daysAbsentUnjustifiedDates[$workDateStr] = true;
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
                $workedDates[$workDateStr] = true;

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
            'days_absent_unjustified_dates' => $daysAbsentUnjustifiedDates,
            'worked_dates' => $workedDates,
            'days_late' => $daysLate,
            'punctual_days' => $punctualDays,
        ];
    }

    /**
     * Calculate incident-related days for the period.
     */
    /**
     * Unidades de fin de semana de un depto que cuenta por horas (Almacén PT).
     * Por cada día con autorización FIN aprobada cuenta AL MENOS 1 (regla de Dani
     * 2026-06-28: "aunque se presenten 1 hora es un fin de semana"), más 1 por
     * cada bloque completo de weekend_unit_hours horas CORRIDAS de entrada a
     * salida ese día (12 h ÷ 6 = 2). Corridas, sin descontar comida (Dani
     * 2026-08-19, caso Elizabeth: 6:55–19:00 son 12 h aunque el neto quede en
     * 11.58) — la misma base que ya usa el umbral de los deptos sin unidades.
     * Dos reglas más (Dani 2026-08-24):
     *   - Las horas de VELADA se restan de la base (caso Miguel: 15:01–05:01 son
     *     14 h pero 4.5 son velada pagada aparte → 9.5 ÷ 6 = 1 unidad, no 2 —
     *     una hora nunca paga doble).
     *   - Sin checada completa, la autorización aprobada es la evidencia: valen
     *     las unidades que capturó el encargado (hours del FIN, mínimo 1) —
     *     caso Elsa Laura: sábado sin salida checada con FIN de 2 → 2 unidades.
     * Se basa en las autorizaciones, no en el status, para que un día trabajado
     * y autorizado pero marcado "ausente" también pague.
     */
    private function calculateWeekendUnits(Collection $attendance, Collection $approvedAuthorizations, Employee $employee): array
    {
        // Días FIN autorizados que NO generaron fin de semana (no llegaron al
        // umbral de horas corridas). Se reportan en el recibo: un fin aprobado
        // que no aparece siempre termina en pregunta (Elias 2026-08-26, caso
        // Orlando: sábado de 6 h 41 min contra el mínimo de 7 h).
        $notCounted = [];
        $recordsByDate = $attendance->keyBy(fn ($r) => Carbon::parse($r->work_date)->toDateString());

        $finAuths = $approvedAuthorizations
            ->filter(fn (Authorization $a) => $a->compensationType?->hasWeekendPullRule());

        $finDates = $finAuths
            ->map(fn (Authorization $a) => Carbon::parse($a->date)->toDateString())
            ->unique();

        $finHoursByDate = $finAuths
            ->groupBy(fn (Authorization $a) => Carbon::parse($a->date)->toDateString())
            ->map(fn (Collection $auths) => (float) $auths->max('hours'));

        $weekendUnitHours = $employee->department?->weekend_unit_hours;

        // Almacén PT (paga por unidades de horas): por cada día FIN autorizado,
        // AL MENOS 1 aunque trabaje < 1 unidad (Dani 2026-06-28); 12 h ÷ 6 = 2.
        if ($weekendUnitHours && $weekendUnitHours > 0) {
            $units = 0;
            foreach ($finDates as $date) {
                $record = $recordsByDate->get($date);
                $gross = $record?->grossSpanHours();
                if ($gross === null) {
                    $units += max(1, (int) round((float) ($finHoursByDate->get($date) ?? 1)));

                    continue;
                }
                $base = max(0.0, (float) $gross - (float) ($record->velada_hours ?? 0));
                $units += max(1, (int) floor($base / $weekendUnitHours));
            }

            return ['units' => $units, 'not_counted' => $notCounted];
        }

        // Los demás deptos (Dani 2026-07-07, ampliada 2026-08-25 caso Angelica/
        // Saldos): por cada día FIN autorizado con al menos T horas (7 por
        // omisión) hay 1 fin de semana, y al llegar a 12 h corridas el fin se
        // paga DOBLE — el excedente sobre T se sigue pagando como tiempo extra
        // aparte (a diferencia de Almacén PT, donde las unidades absorben todo).
        // Por debajo de T no hay fin de semana (esas horas van como tiempo
        // extra). Se reconfirma el umbral aquí aunque el pull ya lo filtra.
        $threshold = $employee->weekendUnitThreshold();
        if ($threshold === null) {
            return ['units' => 0, 'not_counted' => $notCounted];
        }

        // El umbral se compara contra las horas CORRIDAS de entrada a salida,
        // sin descontar comida (Dani 2026-07-08) pero MENOS la velada — la
        // velada se paga aparte y no genera fines (misma regla que Almacén).
        // Un día FIN autorizado sin checada completa (empleado exento, salida
        // no marcada, día sincronizado como ausente) vale lo CAPTURADO en la
        // autorización (mínimo 1): la autorización aprobada es la evidencia
        // cuando no hay horas que medir.
        $units = 0;
        foreach ($finDates as $date) {
            $record = $recordsByDate->get($date);
            $gross = $record?->grossSpanHours();
            if ($gross === null) {
                $units += max(1, (int) round((float) ($finHoursByDate->get($date) ?? 1)));

                continue;
            }
            $base = max(0.0, (float) $gross - (float) ($record->velada_hours ?? 0));
            $dayUnits = (int) ($employee->weekendUnitsForGrossHours($base) ?? 0);
            $units += $dayUnits;

            if ($dayUnits === 0) {
                $notCounted[] = [
                    'date' => $date,
                    'gross_hours' => round($base, 2),
                    'threshold' => round((float) $threshold, 2),
                    'reason' => 'No llego al minimo de horas corridas; esas horas se pagan como tiempo extra',
                ];
            }
        }

        return ['units' => $units, 'not_counted' => $notCounted];
    }

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
        $lateAbsenceIncidents = [];

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
                    // Guardamos el mes acumulado para poder detallar en el
                    // recibo CUÁLES retardos y CUÁNDO originaron la falta.
                    $lateAbsenceIncidents[] = [
                        'month' => $incident->late_month,
                        'days' => $frtDays,
                        'reason' => $incident->reason,
                    ];
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

            // Conteo por categoría para REPORTE. Permisos sin goce se restan del
            // base plano (sin castigo del séptimo día). Las faltas injustificadas
            // ('absence' con is_paid=false) SÍ descuentan SD×7/6, pero ese
            // descuento se calcula por FECHA en incidentAbsenceDeductionDates()
            // para deduplicar contra las faltas de asistencia y los días
            // trabajados; aquí 'absence_days' queda solo como métrica de reporte
            // (incluye también las faltas justificadas y honra el count_mode).
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
            'late_absence_incidents' => $lateAbsenceIncidents,
        ];
    }

    /**
     * Fechas 'Y-m-d' de faltas injustificadas capturadas por INCIDENCIA (no por
     * checada) que descuentan SD×7/6. Es el único canal de falta para empleados
     * sin checador (is_attendance_exempt) y corrige el bug de que una "Falta
     * injustificada"/"Suspensión" aprobada no descontaba a NADIE en el modelo de
     * sueldo diario.
     *
     * Filtra ESTRICTAMENTE categoría 'absence' con is_paid=false: excluye FRT
     * (categoría 'late_accumulation', ya contada en late_absence_days) y "Falta
     * justificada" (is_paid=true). NO se gatea por is_paid solo, para no
     * doble-penalizar un permiso sin goce (ese ya se resta del base plano).
     *
     * Enumera día por día (nunca por count_mode/overlapDays: una falta jamás
     * cae en día de descanso) y descarta: festivo, día no laborable del
     * empleado, día justificado por otra incidencia, día ya contado como falta
     * en asistencia (dedup) y día con checada trabajada ("la evidencia de
     * trabajo gana", protege al empleado con checador). Devuelve un set por
     * fecha ⇒ máximo un descuento por día aunque se solapen incidencias.
     *
     * @param  array<string,true>  $justifiedDates
     * @param  array<string,true>  $attendanceAbsentDates  Fechas ya contadas como falta injustificada por checada.
     * @param  array<string,true>  $workedDates  Fechas con checada present/late/partial.
     * @return array<string,true>
     */
    private function incidentAbsenceDeductionDates(
        Collection $incidents,
        Carbon $startDate,
        Carbon $endDate,
        Employee $employee,
        array $holidayDates,
        array $justifiedDates,
        array $attendanceAbsentDates,
        array $workedDates,
    ): array {
        $dates = [];

        foreach ($incidents as $incident) {
            $type = $incident->incidentType;
            if (! $type || $type->category !== 'absence' || $type->is_paid) {
                continue;
            }

            $from = Carbon::parse($incident->start_date)->startOfDay()->max($startDate->copy()->startOfDay());
            $to = Carbon::parse($incident->end_date)->startOfDay()->min($endDate->copy()->startOfDay());

            // Acota al empleo (igual que paidCalendarDays) para no descontar
            // faltas fuera del alta/baja.
            if ($employee->hire_date) {
                $from = $from->max(Carbon::parse($employee->hire_date)->startOfDay());
            }
            if ($employee->termination_date) {
                $to = $to->min(Carbon::parse($employee->termination_date)->startOfDay());
            }
            if ($from->gt($to)) {
                continue;
            }

            for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
                $dateStr = $day->toDateString();

                if (in_array($dateStr, $holidayDates, true)) {
                    continue; // festivo nunca es falta
                }
                if (! $employee->isObligatoryWorkDay($day)) {
                    continue; // descanso del empleado o fin de semana (no obligatorio) no descuenta
                }
                if (isset($justifiedDates[$dateStr])) {
                    continue; // justificada por otra incidencia (vac/incap/permiso/FJU)
                }
                if (isset($attendanceAbsentDates[$dateStr])) {
                    continue; // ya contada como falta injustificada en asistencia (dedup)
                }
                if (isset($workedDates[$dateStr])) {
                    continue; // trabajó ese día: la evidencia de trabajo gana
                }

                $dates[$dateStr] = true;
            }
        }

        return $dates;
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
        // Reparto del neto: efectivo (extras + base de quien cobra en efectivo)
        // vs transferencia (base por banco/CONTPAQi de quien está en IMSS).
        $totalCash = $entries->sum('cash_amount');
        $totalTransfer = $entries->sum('bank_amount');
        // Retenciones fiscales del trabajador (formalizados): reducen la
        // transferencia. El BRUTO de la transferencia = neto transferido + lo
        // retenido (ISR + IMSS + Infonavit − subsidio) — para comparar contra el
        // sueldo base de Contpaq antes de retenciones.
        $totalIsr = $entries->sum('isr_amount');
        $totalImss = $entries->sum('imss_amount');
        $totalInfonavit = $entries->sum('infonavit_amount');
        $totalSubsidy = $entries->sum('subsidy_amount');
        $totalRetentions = round($totalIsr + $totalImss + $totalInfonavit - $totalSubsidy, 2);
        $totalTransferGross = round($totalTransfer + $totalRetentions, 2);
        $employeeCount = $entries->count();

        // Costo patronal del periodo (suma del bloque employer_costs de cada
        // entry): lo que la empresa provisiona por IMSS/SAR/Infonavit/ISN.
        $totalEmployerCost = 0.0;
        $employerByRubro = [];
        foreach ($entries as $entry) {
            $costs = $entry->calculation_breakdown['employer_costs'] ?? null;
            if (! is_array($costs)) {
                continue;
            }
            foreach ($costs as $rubro => $amount) {
                if ($rubro === 'total') {
                    $totalEmployerCost += (float) $amount;
                } else {
                    $employerByRubro[$rubro] = round(($employerByRubro[$rubro] ?? 0.0) + (float) $amount, 2);
                }
            }
        }
        $totalEmployerCost = round($totalEmployerCost, 2);

        $byDepartment = $entries->groupBy('employee.department.name')->map(function ($group) {
            return [
                'count' => $group->count(),
                'total_gross' => $group->sum('gross_pay'),
                'total_net' => $group->sum('net_pay'),
                'total_cash' => $group->sum('cash_amount'),
                'total_transfer' => $group->sum('bank_amount'),
            ];
        });

        return [
            'employee_count' => $employeeCount,
            'total_gross' => $totalGross,
            'total_net' => $totalNet,
            'total_deductions' => $totalDeductions,
            'total_overtime' => $totalOvertime,
            'total_cash' => $totalCash,
            'total_transfer' => $totalTransfer,
            'total_transfer_gross' => $totalTransferGross,
            'total_isr' => $totalIsr,
            'total_imss' => $totalImss,
            'total_infonavit' => $totalInfonavit,
            'total_subsidy' => $totalSubsidy,
            'total_retentions' => $totalRetentions,
            'total_employer_cost' => $totalEmployerCost,
            'employer_cost_by_rubro' => $employerByRubro,
            'average_pay' => $employeeCount > 0 ? $totalNet / $employeeCount : 0,
            'by_department' => $byDepartment,
        ];
    }
}
