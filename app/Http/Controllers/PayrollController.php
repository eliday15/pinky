<?php

namespace App\Http\Controllers;

use App\Exports\Contpaqi\ContpaqiImportExport;
use App\Exports\ContpaqiPrenominaExport;
use App\Http\Traits\VerifiesTwoFactor;
use App\Models\AuditLog;
use App\Models\CashPayout;
use App\Models\Department;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Services\CashDenominationService;
use App\Services\PayrollCalculatorService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PayrollController extends Controller
{
    use VerifiesTwoFactor;

    public function __construct(
        private PayrollCalculatorService $calculator,
        private CashDenominationService $denominations,
    ) {}

    /**
     * Display list of payroll periods.
     */
    public function index(Request $request): Response
    {
        $user = auth()->user();
        if (! $user->hasPermissionTo('payroll.view_basic') && ! $user->hasPermissionTo('payroll.view_complete')) {
            abort(403);
        }

        $periods = PayrollPeriod::with(['createdBy', 'approvedBy', 'department:id,name'])
            ->withCount('entries')
            ->withSum('entries', 'net_pay')
            // Lo más NUEVO arriba (Elias 2026-08-26): manda la fecha de fin (la
            // mensual del mes en curso va antes que la semana que ya cerró) y,
            // a igualdad de fechas, la última capturada.
            ->orderByDesc('end_date')
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->paginate(15);

        return Inertia::render('Payroll/Index', [
            'periods' => $periods,
        ]);
    }

    /**
     * Show form to create a new payroll period.
     */
    public function create(): Response
    {
        if (! auth()->user()->hasPermissionTo('payroll.create')) {
            abort(403);
        }

        // Suggest dates for next period
        $lastPeriod = PayrollPeriod::orderBy('end_date', 'desc')->first();

        if ($lastPeriod) {
            $suggestedStart = Carbon::parse($lastPeriod->end_date)->addDay();
        } else {
            // Start from beginning of current month
            $suggestedStart = Carbon::now()->startOfMonth();
        }

        // Default to biweekly (14 days)
        $suggestedEnd = $suggestedStart->copy()->addDays(13);
        $suggestedPayment = $suggestedEnd->copy()->addDays(3);

        // Departamentos con nómina propia (p. ej. Taller): solo para informar en
        // el formulario que, además de la General, se generará una por cada uno.
        $separatePayrollDepartments = Department::separatePayroll()
            ->orderBy('name')
            ->pluck('name');

        // Arranque de la semana que sigue: el día después del último periodo
        // BASE de la nómina general. Alimenta el rango de semana que se paga
        // junto con el mes en un alta MENSUAL.
        $lastWeek = PayrollPeriod::whereNull('department_id')
            ->where('type', '!=', 'monthly')
            ->orderByDesc('end_date')
            ->first();

        return Inertia::render('Payroll/Create', [
            'nextWeekStart' => $lastWeek
                ? Carbon::parse($lastWeek->end_date)->addDay()->toDateString()
                : null,
            'suggestedDates' => [
                'start_date' => $suggestedStart->toDateString(),
                'end_date' => $suggestedEnd->toDateString(),
                'payment_date' => $suggestedPayment->toDateString(),
            ],
            'separatePayrollDepartments' => $separatePayrollDepartments,
        ]);
    }

    /**
     * Nombre de una nómina semanal con el formato de siempre ("Semana 19 ago -
     * 23 ago"), para las semanas que nacen de un alta MENSUAL.
     */
    private function weekPeriodName(string $start, string $end): string
    {
        $months = [1 => 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
        $from = Carbon::parse($start);
        $to = Carbon::parse($end);

        return 'Semana '.$from->day.' '.$months[$from->month].' - '.$to->day.' '.$months[$to->month];
    }

    /**
     * Store a new payroll period and trigger calculation.
     *
     * PAGO UNIFICADO (Elias 2026-08-25): un alta MENSUAL ya no crea un periodo
     * aparte que se pagaría por separado el mismo día. Sus extras se pagan
     * junto con la SEMANA que se paga ese día:
     *
     *  - Si esa semana ya existe (y todavía se puede tocar), se le pegan.
     *  - Si no existe, el alta la crea ya unificada — para eso el formulario
     *    manda el rango de la semana (week_start_date / week_end_date).
     *
     * Los departamentos con nómina propia (Taller) no llevan extras del mes:
     * de un alta mensual salen con SU SEMANA nada más.
     */
    public function store(Request $request): RedirectResponse
    {
        if (! auth()->user()->hasPermissionTo('payroll.create')) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:weekly,biweekly,monthly'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'payment_date' => ['required', 'date', 'after_or_equal:end_date'],
            // Solo en un alta MENSUAL: la semana que se paga junto con el mes.
            'week_start_date' => ['nullable', 'date'],
            'week_end_date' => ['nullable', 'date', 'after_or_equal:week_start_date'],
        ]);

        $isMonthly = $validated['type'] === 'monthly';
        $weekStart = $isMonthly ? ($validated['week_start_date'] ?? null) : null;
        $weekEnd = $weekStart ? ($validated['week_end_date'] ?? $validated['end_date']) : null;
        $weekName = $weekStart ? $this->weekPeriodName($weekStart, $weekEnd) : null;
        unset($validated['week_start_date'], $validated['week_end_date']);

        // "De un jalón": un solo alta genera la nómina GENERAL más una por cada
        // departamento con nómina propia (p. ej. Taller). Los alcances que ya
        // existan para esas fechas se saltan (así se puede completar los que
        // falten sin duplicar).
        $separateDepartments = Department::separatePayroll()->orderBy('name')->get(['id', 'name']);
        $scopes = collect([['id' => null, 'name' => 'General', 'separate' => false]])
            ->merge($separateDepartments->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'separate' => true]));

        // Traslape de intervalos real (cubre también el periodo que ENGLOBA a
        // otro). Cuenta DENTRO de la misma categoría: la nómina BASE (sueldo −
        // faltas) y la de EXTRAS del mismo periodo son independientes por
        // diseño y deben poder convivir; dos base que se traslapan sí es error
        // (doble sueldo), igual dos que pagan extras. Una semana UNIFICADA
        // cuenta como nómina de extras por su rango de extras.
        $baseOverlaps = fn (?int $departmentId, string $from, string $to) => PayrollPeriod::where('department_id', $departmentId)
            ->where('type', '!=', 'monthly')
            ->where('start_date', '<=', $to)
            ->where('end_date', '>=', $from)
            ->exists();

        $extrasOverlaps = fn (?int $departmentId, string $from, string $to) => PayrollPeriod::where('department_id', $departmentId)
            ->where(function ($q) use ($from, $to) {
                $q->where(fn ($q2) => $q2->where('type', 'monthly')
                    ->where('start_date', '<=', $to)
                    ->where('end_date', '>=', $from))
                    ->orWhere(fn ($q2) => $q2->whereNotNull('extras_start_date')
                        ->where('extras_start_date', '<=', $to)
                        ->where('extras_end_date', '>=', $from));
            })
            ->exists();

        $created = collect();
        $unified = collect();
        $absorbed = collect();
        $skipped = collect();
        $toCalculate = collect();

        $audit = function (PayrollPeriod $period, string $description, array $metadata) {
            AuditLog::record(
                module: AuditLog::MODULE_PAYROLL,
                action: AuditLog::ACTION_CREATE,
                model: $period,
                description: $description,
                subjectLabel: $period->name,
                metadata: $metadata,
            );
        };

        foreach ($scopes as $scope) {
            // ---- Alta MENSUAL ----
            if ($isMonthly) {
                // Taller (nómina propia): no lleva extras del mes. De este alta
                // sale únicamente SU SEMANA, la que se paga ese mismo día.
                if ($scope['separate']) {
                    if (! $weekStart || $baseOverlaps($scope['id'], $weekStart, $weekEnd)) {
                        $skipped->push($scope['name']);

                        continue;
                    }

                    $period = PayrollPeriod::create([
                        'name' => $weekName.' - '.$scope['name'],
                        'type' => 'weekly',
                        'department_id' => $scope['id'],
                        'start_date' => $weekStart,
                        'end_date' => $weekEnd,
                        'payment_date' => $validated['payment_date'],
                        'status' => 'draft',
                        'created_by' => auth()->id(),
                    ]);
                    $created->push($period);
                    $toCalculate->push($period);
                    $audit($period, "Creo la nomina {$period->name} (semanal, {$weekStart} al {$weekEnd}; su departamento no lleva extras del mes)", [
                        'type' => 'weekly',
                        'start_date' => $weekStart,
                        'end_date' => $weekEnd,
                        'scope' => $scope['name'],
                    ]);

                    continue;
                }

                // General: la semana que se paga junto con este mes (misma fecha
                // de fin y todavía editable) se lleva los extras.
                $week = PayrollPeriod::where('department_id', $scope['id'])
                    ->where('type', 'weekly')
                    ->whereDate('end_date', $validated['end_date'])
                    ->orderByDesc('id')
                    ->get()
                    ->first(fn (PayrollPeriod $p) => $p->canAbsorbExtras());

                if ($week) {
                    $week->update([
                        'extras_start_date' => $validated['start_date'],
                        'extras_end_date' => $validated['end_date'],
                    ]);
                    $unified->push($week);
                    $toCalculate->push($week);

                    AuditLog::record(
                        module: AuditLog::MODULE_PAYROLL,
                        action: AuditLog::ACTION_UPDATE,
                        model: $week,
                        description: "Unifico los extras del {$validated['start_date']} al {$validated['end_date']} en la nomina {$week->name} (un solo pago)",
                        subjectLabel: $week->name,
                        metadata: [
                            'extras_start_date' => $validated['start_date'],
                            'extras_end_date' => $validated['end_date'],
                            'scope' => $scope['name'],
                        ],
                    );

                    continue;
                }

                // Esa semana todavía no existe: se crea YA UNIFICADA (sueldo de
                // la semana + extras del mes en un solo pago).
                if ($weekStart
                    && ! $baseOverlaps($scope['id'], $weekStart, $weekEnd)
                    && ! $extrasOverlaps($scope['id'], $validated['start_date'], $validated['end_date'])) {
                    $period = PayrollPeriod::create([
                        'name' => $weekName,
                        'type' => 'weekly',
                        'department_id' => $scope['id'],
                        'start_date' => $weekStart,
                        'end_date' => $weekEnd,
                        'extras_start_date' => $validated['start_date'],
                        'extras_end_date' => $validated['end_date'],
                        'payment_date' => $validated['payment_date'],
                        'status' => 'draft',
                        'created_by' => auth()->id(),
                    ]);
                    $created->push($period);
                    $unified->push($period);
                    $toCalculate->push($period);
                    $audit($period, "Creo la nomina {$period->name} unificada: semana {$weekStart} al {$weekEnd} + extras del {$validated['start_date']} al {$validated['end_date']}", [
                        'type' => 'weekly',
                        'start_date' => $weekStart,
                        'end_date' => $weekEnd,
                        'extras_start_date' => $validated['start_date'],
                        'extras_end_date' => $validated['end_date'],
                        'scope' => $scope['name'],
                    ]);

                    continue;
                }

                // Sin semana con la cual unificar (o ya existe una nómina de
                // extras que se traslapa): se genera la mensual suelta, como
                // antes, para que los extras no se queden sin pagar.
                if ($extrasOverlaps($scope['id'], $validated['start_date'], $validated['end_date'])) {
                    $skipped->push($scope['name']);

                    continue;
                }

                $period = PayrollPeriod::create([
                    ...$validated,
                    'department_id' => $scope['id'],
                    'status' => 'draft',
                    'created_by' => auth()->id(),
                ]);
                $created->push($period);
                $toCalculate->push($period);
                $audit($period, "Creo la nomina {$period->name} (mensual, {$validated['start_date']} al {$validated['end_date']})", [
                    'type' => 'monthly',
                    'start_date' => $validated['start_date'],
                    'end_date' => $validated['end_date'],
                    'scope' => $scope['name'],
                ]);

                continue;
            }

            // ---- Alta SEMANAL / QUINCENAL ----
            if ($baseOverlaps($scope['id'], $validated['start_date'], $validated['end_date'])) {
                $skipped->push($scope['name']);

                continue;
            }

            $period = PayrollPeriod::create([
                ...$validated,
                'name' => $scope['id'] ? $validated['name'].' - '.$scope['name'] : $validated['name'],
                'department_id' => $scope['id'],
                'status' => 'draft',
                'created_by' => auth()->id(),
            ]);

            // Al dar de alta la SEMANA, si ya existe un mensual suelto que se
            // paga con ella (mismo fin), se absorbe: sus extras pasan a la
            // semana y el periodo mensual desaparece, para que sea UN solo pago.
            // Los departamentos con nómina propia no absorben nada: no llevan
            // extras del mes.
            if ($validated['type'] === 'weekly' && ! $scope['separate']) {
                $pendingMonthly = PayrollPeriod::where('department_id', $scope['id'])
                    ->where('type', 'monthly')
                    ->whereDate('end_date', $validated['end_date'])
                    ->orderByDesc('id')
                    ->get()
                    ->first(fn (PayrollPeriod $m) => $m->canEdit() && ! $m->isCashClosed());

                if ($pendingMonthly) {
                    $period->update([
                        'extras_start_date' => $pendingMonthly->start_date,
                        'extras_end_date' => $pendingMonthly->end_date,
                    ]);

                    $absorbedName = $pendingMonthly->name;
                    $pendingMonthly->entries()->delete();
                    $pendingMonthly->delete();
                    $absorbed->push($absorbedName);

                    AuditLog::record(
                        module: AuditLog::MODULE_PAYROLL,
                        action: AuditLog::ACTION_DELETE,
                        model: null,
                        description: "Absorbio la nomina {$absorbedName} en {$period->name}: los extras del mes se pagan junto con la semana",
                        subjectLabel: $absorbedName,
                        metadata: [
                            'absorbed_into' => $period->id,
                            'extras_start_date' => $period->extras_start_date?->toDateString(),
                            'extras_end_date' => $period->extras_end_date?->toDateString(),
                        ],
                    );
                }
            }

            $created->push($period);
            $toCalculate->push($period);
            $audit($period, "Creo la nomina {$period->name} ({$validated['type']}, {$validated['start_date']} al {$validated['end_date']})", [
                'type' => $validated['type'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'scope' => $scope['name'],
            ]);
        }

        if ($toCalculate->isEmpty()) {
            return redirect()->back()
                ->withErrors(['start_date' => 'Ya existe(n) la(s) nomina(s) '.$skipped->implode(', ').' que se traslapan con estas fechas.'])
                ->withInput();
        }

        // Calcular cada una de inmediato (best-effort): si una falla queda en
        // draft para recalcular a mano y no tumba a las demás.
        foreach ($toCalculate as $period) {
            try {
                $this->calculator->calculatePeriod($period);
            } catch (\Throwable $e) {
                report($e);
                $period->update(['status' => 'draft']);
            }
        }

        $parts = [];
        if ($created->isNotEmpty()) {
            $parts[] = 'Se generaron '.$created->count().' nómina(s): '.$created->pluck('name')->implode(', ').'.';
        }
        if ($unified->isNotEmpty()) {
            $parts[] = 'Los extras del mes se pagan junto con '.$unified->pluck('name')->implode(', ').', en un solo pago.';
        }
        if ($absorbed->isNotEmpty()) {
            $parts[] = 'Se absorbió '.$absorbed->implode(', ').' para que sea un solo pago.';
        }
        if ($skipped->isNotEmpty()) {
            $parts[] = 'Se saltó: '.$skipped->implode(', ').' (ya existe una nómina que se traslapa).';
        }
        $msg = implode(' ', $parts);

        // Una sola → a su detalle; varias → a la lista para verlas todas.
        if ($toCalculate->count() === 1) {
            return redirect()->route('payroll.show', $toCalculate->first())->with('success', $msg);
        }

        return redirect()->route('payroll.index')->with('success', $msg);
    }

    /**
     * La nómina SEMANAL que puede pagar los extras de este periodo mensual: la
     * del mismo alcance que termina el mismo día y todavía se puede tocar.
     *
     * Es lo que permite unificar una mensual que ya se generó por separado, sin
     * borrarla y volverla a capturar.
     */
    private function weekThatCanAbsorb(PayrollPeriod $monthly): ?PayrollPeriod
    {
        if ($monthly->type !== 'monthly' || ! $monthly->canEdit() || $monthly->isCashClosed()) {
            return null;
        }

        // Los departamentos con nómina propia (Taller) no llevan extras del mes.
        if ($monthly->department_id !== null && $monthly->department?->has_separate_payroll) {
            return null;
        }

        return PayrollPeriod::where('department_id', $monthly->department_id)
            ->where('type', 'weekly')
            ->whereDate('end_date', $monthly->end_date->toDateString())
            ->orderByDesc('id')
            ->get()
            ->first(fn (PayrollPeriod $p) => $p->canAbsorbExtras());
    }

    /**
     * Unifica una nómina MENSUAL ya generada con la semana que se paga el mismo
     * día: sus extras pasan a la semana (un solo pago, un solo recibo) y el
     * periodo mensual desaparece.
     */
    public function unifyWithWeek(PayrollPeriod $payroll): RedirectResponse
    {
        if (! auth()->user()->hasPermissionTo('payroll.create')) {
            abort(403);
        }

        $week = $this->weekThatCanAbsorb($payroll);

        if (! $week) {
            return redirect()->back()->with(
                'error',
                'No hay una nómina semanal que termine el '.$payroll->end_date->format('d/m/Y')
                .' y que todavía se pueda modificar (sin aprobar, sin pagar y con el efectivo abierto).',
            );
        }

        $monthlyName = $payroll->name;

        $week->update([
            'extras_start_date' => $payroll->start_date,
            'extras_end_date' => $payroll->end_date,
        ]);

        $payroll->entries()->delete();
        $payroll->delete();

        $this->calculator->calculatePeriod($week);

        AuditLog::record(
            module: AuditLog::MODULE_PAYROLL,
            action: AuditLog::ACTION_UPDATE,
            model: $week,
            description: "Unifico la nomina {$monthlyName} en {$week->name}: los extras del mes se pagan junto con la semana",
            subjectLabel: $week->name,
            metadata: [
                'absorbed' => $monthlyName,
                'extras_start_date' => $week->extras_start_date?->toDateString(),
                'extras_end_date' => $week->extras_end_date?->toDateString(),
            ],
        );

        return redirect()->route('payroll.show', $week)->with(
            'success',
            "Listo: los extras de {$monthlyName} ahora se pagan junto con {$week->name}, en un solo pago.",
        );
    }

    /**
     * Display a payroll period with its entries.
     *
     * Visibility is controlled by permissions:
     * - payroll.view_complete: Shows all columns (overtime, bonuses, night shifts, etc.)
     * - payroll.view_basic: Shows only basic columns (attendance, absences, vacations)
     */
    public function show(PayrollPeriod $payroll): Response
    {
        $user = auth()->user();
        if (! $user->hasPermissionTo('payroll.view_basic') && ! $user->hasPermissionTo('payroll.view_complete')) {
            abort(403);
        }

        $payroll->load(['createdBy', 'approvedBy', 'department']);

        $entries = PayrollEntry::where('payroll_period_id', $payroll->id)
            ->with(['employee.department', 'employee.position'])
            ->orderBy('net_pay', 'desc')
            ->get();

        $summary = $this->calculator->getPeriodSummary($payroll);

        // Estatus del timbrado CFDI (solo informativo; el botón aparece para
        // quien puede aprobar y con nómina aprobada).
        $cfdiStatus = app(\App\Services\Cfdi\PayrollCfdiService::class)->periodStatus($payroll);

        // Nómina mensual que todavía se puede unificar con su semana: se ofrece
        // el botón para pagarlas juntas sin borrar y recapturar.
        $unifiableWeek = $this->weekThatCanAbsorb($payroll);

        return Inertia::render('Payroll/Show', [
            'period' => $payroll,
            'entries' => $entries,
            'summary' => $summary,
            'cfdi' => $cfdiStatus,
            'unifiableWeek' => $unifiableWeek?->only(['id', 'name']),
            'can' => [
                'viewComplete' => $user->hasPermissionTo('payroll.view_complete'),
                'calculate' => $user->hasPermissionTo('payroll.calculate'),
                'approve' => $user->hasPermissionTo('payroll.approve'),
                'export' => $user->hasPermissionTo('payroll.export'),
                'unify' => $user->hasPermissionTo('payroll.create'),
                'payCash' => $user->hasPermissionTo('payroll.pay_cash'),
                // Preparar/entregar el efectivo es exclusivo del custodio (superadmin).
                'deliverCash' => $user->hasPermissionTo('payroll.cash.deliver'),
            ],
        ]);
    }

    /**
     * Calculate/recalculate payroll for a period.
     */
    public function calculate(PayrollPeriod $payroll): RedirectResponse
    {
        if (! auth()->user()->hasPermissionTo('payroll.calculate')) {
            abort(403);
        }

        // Un periodo aprobado solo se puede recalcular cuando está marcado
        // "requiere recálculo" (DECISIONES §7) — y al hacerlo vuelve a
        // 'review' para re-aprobación. Pagado nunca se recalcula.
        $recalculable = in_array($payroll->status, ['draft', 'review'], true)
            || ($payroll->status === 'approved' && $payroll->requires_recalculation);

        if (! $recalculable) {
            return redirect()->back()
                ->with('error', 'No se puede recalcular una nomina aprobada o pagada.');
        }

        // CANDADO CFDI: un periodo con recibos TIMBRADOS ante el SAT no se
        // recalcula sin cancelarlos primero (si no, los montos del CFDI dejan
        // de coincidir con lo pagado y habría que re-timbrar duplicado).
        $stamped = \App\Models\PayrollCfdi::whereHas('payrollEntry', fn ($q) => $q->where('payroll_period_id', $payroll->id))
            ->where('status', \App\Models\PayrollCfdi::STATUS_STAMPED)
            ->count();
        if ($stamped > 0) {
            return redirect()->back()
                ->with('error', "Esta nómina tiene {$stamped} recibo(s) CFDI timbrado(s). Cancela el timbrado antes de recalcular.");
        }

        $this->calculator->calculatePeriod($payroll);

        $entryCount = $payroll->entries()->count();
        AuditLog::record(
            module: AuditLog::MODULE_PAYROLL,
            action: AuditLog::ACTION_RECALCULATE,
            model: $payroll,
            description: "Recalculo la nomina {$payroll->name} ({$entryCount} recibos)",
            subjectLabel: $payroll->name,
            metadata: ['entry_count' => $entryCount],
        );

        return redirect()->route('payroll.show', $payroll)
            ->with('success', 'Nomina calculada exitosamente.');
    }

    /**
     * Approve a payroll period.
     */
    public function approve(Request $request, PayrollPeriod $payroll): RedirectResponse
    {
        if (! auth()->user()->hasPermissionTo('payroll.approve')) {
            abort(403);
        }

        $this->verifyTwoFactorCode($request);

        if ($payroll->status !== 'review') {
            return redirect()->back()
                ->with('error', 'Solo se pueden aprobar nominas en revision.');
        }

        $payroll->update([
            'status' => 'approved',
            'approved_by' => auth()->id(),
        ]);

        $totalEmpleados = $payroll->entries()->count();
        $totalNeto = (float) $payroll->entries()->sum('net_pay');
        AuditLog::record(
            module: AuditLog::MODULE_PAYROLL,
            action: AuditLog::ACTION_APPROVE,
            model: $payroll,
            description: "Aprobo la nomina {$payroll->name} ({$totalEmpleados} empleados, neto $".number_format($totalNeto, 2).')',
            subjectLabel: $payroll->name,
            metadata: ['total_neto' => round($totalNeto, 2), 'empleados' => $totalEmpleados],
        );

        // Acumulados anuales (ISR retenido, gravado, días): se reconstruyen
        // desde cero para el año del periodo — idempotente, re-aprobar no
        // duplica. Solo los periodos base (semanales) alimentan el acumulado.
        if ($payroll->paysBase() && $payroll->type === 'weekly') {
            app(\App\Services\Fiscal\EmployeeAnnualTotalService::class)
                ->rebuildYear((int) $payroll->start_date->year);
        }

        return redirect()->route('payroll.show', $payroll)
            ->with('success', 'Nomina aprobada.');
    }

    /**
     * Mark payroll as paid.
     */
    public function markPaid(Request $request, PayrollPeriod $payroll): RedirectResponse
    {
        if (! auth()->user()->hasPermissionTo('payroll.approve')) {
            abort(403);
        }

        $this->verifyTwoFactorCode($request);

        if ($payroll->status !== 'approved') {
            return redirect()->back()
                ->with('error', 'Solo se pueden marcar como pagadas nominas aprobadas.');
        }

        // No se puede pagar una nómina sin haber cerrado/preparado el efectivo:
        // el pago solo se finaliza cuando está aprobada Y con el efectivo cerrado.
        if (! $payroll->isCashClosed()) {
            return redirect()->back()
                ->with('error', 'Primero cierra y prepara el efectivo antes de marcar la nomina como pagada.');
        }

        $payroll->update(['status' => 'paid']);

        AuditLog::record(
            module: AuditLog::MODULE_PAYROLL,
            action: AuditLog::ACTION_PAY,
            model: $payroll,
            description: "Marco como pagada la nomina {$payroll->name}",
            subjectLabel: $payroll->name,
        );

        return redirect()->route('payroll.show', $payroll)
            ->with('success', 'Nomina marcada como pagada.');
    }

    /**
     * Cerrar y preparar el efectivo de un periodo aprobado.
     *
     * Crea/recalcula un CashPayout por empleado: monto del periodo (cash_amount
     * redondeado al peso) + acumulado de lo no cobrado en periodos previos, con
     * el desglose mínimo de billetes/monedas. Reabrible mientras el periodo no
     * esté pagado; nunca pisa un payout ya cobrado.
     */
    public function closeCash(Request $request, PayrollPeriod $payroll): RedirectResponse
    {
        // Preparar/entregar el efectivo es exclusivo del custodio (superadmin).
        if (! auth()->user()->hasPermissionTo('payroll.cash.deliver')) {
            abort(403);
        }

        if ($payroll->status !== 'approved') {
            return redirect()->back()
                ->with('error', 'Solo se puede preparar el efectivo de una nomina aprobada.');
        }

        // Con el cobro ya cerrado el efectivo se devolvió: re-prepararlo
        // reactivaría el cobro por la puerta de atrás. Hay que reabrir primero.
        if ($payroll->isCashCollectionClosed()) {
            return redirect()->back()
                ->with('error', 'El cobro de este periodo ya se cerró. Reabre el cobro antes de volver a preparar el efectivo.');
        }

        DB::transaction(function () use ($payroll) {
            $entries = $payroll->entries()->with('employee')->get();

            foreach ($entries as $entry) {
                $existing = CashPayout::where('payroll_period_id', $payroll->id)
                    ->where('employee_id', $entry->employee_id)
                    ->first();

                // El reparto efectivo/banco AUTORITATIVO lo escribe el cálculo
                // de nómina (incluye retenciones ISR/IMSS/Infonavit, extras por
                // transferencia y ajuste al neto). Aquí SOLO se re-aplica el
                // caso "ahora cobra base en efectivo" (flag de prueba/IMSS
                // cambiado después de calcular): todo su neto pasa a efectivo.
                // NUNCA re-derivar el reparto del formalizado con una fórmula
                // local: la versión anterior (banco = base − faltas, sin
                // retenciones) infló el banco y dejó efectivos NEGATIVOS en
                // toda la nómina (bug 2026-07-15). Si un empleado se formalizó
                // después de calcular, eso requiere recalcular la nómina (el
                // banco necesita sus retenciones), no un re-parte aquí.
                if ($entry->employee && $entry->employee->paysBaseInCash() && (float) $entry->bank_amount > 0) {
                    $entry->update([
                        'cash_amount' => round((float) $entry->net_pay, 2),
                        'bank_amount' => 0.0,
                    ]);
                }

                // Lo ya cobrado no se descobra. Si un recálculo SUBIÓ el efectivo
                // después de que el empleado ya cobró una parte, la diferencia
                // vuelve a quedar pendiente (pago parcial): se conserva
                // amount_paid y el saldo (outstanding) se recalcula. Si el monto
                // bajó o quedó igual, el cobro pagado no se toca.
                $alreadyPaid = $existing ? (float) $existing->amount_paid : 0.0;

                // Acumulado de periodos previos: solo aplica a cobros que aún no
                // se han pagado (los pagados ya saldaron su acumulado al cobrar).
                $openingBalance = 0.0;
                if (! $existing || $existing->status !== CashPayout::STATUS_PAID) {
                    $priorPending = CashPayout::where('employee_id', $entry->employee_id)
                        ->where('payroll_period_id', '!=', $payroll->id)
                        ->where('status', CashPayout::STATUS_PENDING)
                        ->whereHas('payrollPeriod', fn ($q) => $q->where('start_date', '<', $payroll->start_date))
                        ->with('payrollPeriod')
                        ->get()
                        ->sortByDesc(fn (CashPayout $p) => $p->payrollPeriod->start_date)
                        ->first();

                    $openingBalance = $priorPending ? $priorPending->outstanding() : 0.0;
                }

                // Nunca guardar montos negativos en el ledger (defensa: el
                // cálculo ya no produce cash negativo, pero un dato corrupto
                // no debe propagarse a los cobros).
                // Monto EXACTO con centavos (Luis 2026-07-24): antes se redondeaba
                // al peso y el total salía inflado (20,399.50 → 20,400.00). El
                // desglose de billetes sigue en pesos enteros (no hay moneda <$1),
                // pero la cuenta refleja el neto real.
                $periodAmount = max(0.0, round((float) $entry->cash_amount, 2));
                $totalDue = $periodAmount + $openingBalance;
                $outstanding = round($totalDue - $alreadyPaid, 2);

                // Ya pagado y sin diferencia a favor del empleado: se deja tal
                // cual (nunca genera saldo negativo / "descobro"). SOLO protege
                // cobros con dinero realmente entregado (amount_paid > 0): un
                // payout "paid" con $0 cobrado es un cierre sin nada que cobrar
                // o un residuo corrupto (bug del re-parte 2026-07-15) y debe
                // reescribirse con los montos vigentes.
                if ($existing
                    && $existing->status === CashPayout::STATUS_PAID
                    && (float) $existing->amount_paid > 0
                    && $outstanding <= 0.005) {
                    continue;
                }

                CashPayout::updateOrCreate(
                    ['payroll_period_id' => $payroll->id, 'employee_id' => $entry->employee_id],
                    [
                        'period_amount' => $periodAmount,
                        'opening_balance' => $openingBalance,
                        'total_due' => $totalDue,
                        'amount_paid' => $alreadyPaid,
                        'status' => $outstanding > 0.005 ? CashPayout::STATUS_PENDING : CashPayout::STATUS_PAID,
                        'denomination_breakdown' => $this->denominations->breakdown((int) round(max($outstanding, 0.0))),
                    ]
                );
            }

            // Re-preparar el efectivo cambia montos/billetes: hay que volver a
            // confirmar la entrega (paso 1) antes de poder cobrar (paso 2).
            $payroll->update([
                'cash_closed_at' => now(),
                'cash_delivery_confirmed_at' => null,
            ]);

            AuditLog::record(
                module: AuditLog::MODULE_CASH,
                action: AuditLog::ACTION_CLOSE,
                model: $payroll,
                description: "Preparo el efectivo de la nomina {$payroll->name} ({$entries->count()} empleados)",
                subjectLabel: $payroll->name,
                metadata: ['empleados' => $entries->count()],
            );
        });

        return redirect()->route('payroll.cash', $payroll)
            ->with('success', 'Efectivo preparado. Revisa el desglose de billetes.');
    }

    /**
     * Confirmar la entrega del efectivo (paso 1): el cajero preparó/retiró los
     * billetes del desglose. Habilita el cobro (paso 2). Requiere el efectivo ya
     * cerrado; se reinicia si se vuelve a cerrar/preparar el efectivo.
     */
    public function confirmCashDelivery(Request $request, PayrollPeriod $payroll): RedirectResponse
    {
        // Confirmar la entrega física del efectivo es del custodio (superadmin).
        if (! auth()->user()->hasPermissionTo('payroll.cash.deliver')) {
            abort(403);
        }

        if (! $payroll->isCashClosed()) {
            return redirect()->back()
                ->with('error', 'Primero cierra y prepara el efectivo de este periodo.');
        }

        $payroll->update(['cash_delivery_confirmed_at' => now()]);

        AuditLog::record(
            module: AuditLog::MODULE_CASH,
            action: AuditLog::ACTION_APPROVE,
            model: $payroll,
            description: "Confirmo la entrega del efectivo de la nomina {$payroll->name}",
            subjectLabel: $payroll->name,
        );

        return redirect()->route('payroll.cash', $payroll)
            ->with('success', 'Entrega del efectivo confirmada. Ya puedes cobrar.');
    }

    /**
     * Guardar las denominaciones habilitadas del periodo (Paso 1).
     *
     * El custodio desmarca las denominaciones que no tenga; la elección se
     * persiste por periodo para que el desglose de billetes sea idéntico en el
     * Paso 1 (custodio) y en el Paso 2 (cobrador), aunque estén en máquinas
     * distintas. Antes vivía sólo en el localStorage del custodio (Luis
     * 2026-07-16).
     */
    public function saveCashDenominations(Request $request, PayrollPeriod $payroll): RedirectResponse
    {
        // Sólo el custodio prepara la entrega (define las denominaciones).
        if (! auth()->user()->hasPermissionTo('payroll.cash.deliver')) {
            abort(403);
        }

        $validated = $request->validate([
            'denominations' => ['required', 'array', 'min:1'],
            'denominations.*' => ['integer', Rule::in(CashDenominationService::DENOMINATIONS)],
        ]);

        // Normaliza: enteros únicos, de mayor a menor, en el orden del catálogo.
        $picked = array_values(array_filter(
            CashDenominationService::DENOMINATIONS,
            fn (int $d) => in_array($d, array_map('intval', $validated['denominations']), true),
        ));

        $payroll->update(['cash_enabled_denominations' => $picked]);

        return redirect()->back()->with('success', 'Denominaciones actualizadas.');
    }

    /**
     * Desglose del efectivo de un empleado, concepto por concepto y solo lo que
     * tuvo (montos > 0). Reemplaza el agrupado "Otros conceptos": itemiza cada
     * concepto de compensación (Cena, Puntualidad, etc.) y los extras estándar.
     *
     * El efectivo = todo lo devengado menos lo que va por transferencia: para
     * quien cobra base en efectivo incluye el sueldo base y resta las faltas;
     * para quien va por banco (IMSS) son solo los extras. Así los renglones
     * suman el efectivo del periodo (salvo el redondeo al peso del total).
     */
    private function cashItemsForEntry(?PayrollEntry $entry): array
    {
        if (! $entry) {
            return [];
        }

        $baseInCash = $entry->employee?->paysBaseInCash() ?? false;
        $breakdown = $entry->calculation_breakdown ?? [];
        $items = [];

        // Formatea una cantidad sin ceros de más: 600.00→"600", 3.50→"3.5".
        $num = fn (float $n): string => rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');

        // detail = "cuántos hubo" (ej. "600 × $1.00", "5 h", "1 día"), para que
        // se entienda sin deducirlo del monto.
        $push = function (string $label, float $amount, string $detail = '') use (&$items) {
            if (abs($amount) > 0.005) {
                $items[] = ['label' => $label, 'amount' => round($amount, 2), 'detail' => $detail];
            }
        };

        if ($baseInCash) {
            $baseDays = (float) ($breakdown['base']['base_paid_days'] ?? $entry->days_worked);
            $daily = (float) ($breakdown['rates']['daily_salary'] ?? 0);
            $push('Sueldo base', (float) $entry->regular_pay,
                $daily > 0 ? $num($baseDays).' días × $'.number_format($daily, 2) : '');
        }
        $push('Horas extra', (float) $entry->overtime_pay,
            (float) $entry->overtime_hours > 0 ? $num((float) $entry->overtime_hours).' h' : '');
        $push('Dias festivos', (float) $entry->holiday_pay,
            (float) $entry->holiday_hours > 0 ? $num((float) $entry->holiday_hours).' h' : '');
        $push('Fin de semana', (float) $entry->weekend_pay,
            (float) $entry->weekend_hours > 0 ? $num((float) $entry->weekend_hours).' h' : '');
        $push('Velada', (float) $entry->velada_pay,
            (int) $entry->velada_days > 0 ? $num((float) $entry->velada_days).' noche(s)' : '');

        // Conceptos itemizados (Cena, Puntualidad, etc.) con su cantidad — desglosa
        // el agrupado "Otros conceptos".
        foreach ($breakdown['compensation_concepts'] ?? [] as $concept) {
            $amount = (float) ($concept['amount'] ?? 0);
            $qty = (float) ($concept['quantity'] ?? 0);
            $hours = (float) ($concept['hours'] ?? 0);
            $days = (float) ($concept['days'] ?? 0);
            $fixed = (float) ($concept['rate']['fixed_amount'] ?? 0);

            $detail = '';
            if (abs($qty) > 1) {
                $unit = $fixed > 0 ? $fixed : ($qty != 0.0 ? $amount / $qty : 0.0);
                $detail = $num($qty).' × $'.number_format($unit, 2);
            } elseif ($hours > 0) {
                $detail = $num($hours).' h';
            } elseif ($days > 0) {
                $detail = $num($days).' día(s)';
            }

            $push($concept['name'] ?? $concept['code'] ?? 'Concepto', $amount, $detail);
        }

        $push('Vacaciones', (float) $entry->vacation_pay,
            (int) $entry->vacation_days_paid > 0 ? $num((float) $entry->vacation_days_paid).' día(s)' : '');
        $push('Prima vacacional', (float) $entry->vacation_premium_pay);
        $push('Incapacidad', (float) $entry->sick_leave_pay);
        $push('Bonos', (float) $entry->bonuses);

        if ($baseInCash) {
            $push('Deducciones (faltas)', -(float) $entry->deductions);
        }

        return $items;
    }

    /**
     * Página de pago en efectivo: desglose global de billetes y tabla por
     * empleado con su monto, acumulado, total a cobrar y estado.
     */
    public function cash(PayrollPeriod $payroll): Response
    {
        $user = auth()->user();
        if (! $user->hasPermissionTo('payroll.pay_cash') && ! $user->hasPermissionTo('payroll.cash.collect')) {
            abort(403);
        }

        // El cobrador solo ve SU nómina (general o taller).
        abort_unless($user->allowsCashPeriod($payroll), 403);

        // Asientos por empleado: fuente del desglose concepto-por-concepto del
        // efectivo en el modal de cobro, y del chequeo de "desactualizado".
        $entriesByEmployee = $payroll->entries()->with('employee')->get()->keyBy('employee_id');

        $payouts = $payroll->cashPayouts()
            ->with(['employee:id,full_name,employee_number,cash_pin,department_id', 'employee.department:id,name'])
            ->get()
            ->sortBy(fn (CashPayout $p) => $p->employee?->full_name)
            ->values()
            ->map(fn (CashPayout $p) => [
                'id' => $p->id,
                'employee_id' => $p->employee_id,
                'employee_name' => $p->employee?->full_name,
                'employee_number' => $p->employee?->employee_number,
                'employee_department' => $p->employee?->department?->name,
                'has_cash_pin' => (bool) $p->employee?->hasCashPin(),
                'period_amount' => (float) $p->period_amount,
                'opening_balance' => (float) $p->opening_balance,
                'total_due' => (float) $p->total_due,
                'amount_paid' => (float) $p->amount_paid,
                'status' => $p->status,
                'collected_at' => $p->collected_at,
                'denomination_breakdown' => $p->denomination_breakdown ?? [],
                'cash_items' => $this->cashItemsForEntry($entriesByEmployee->get($p->employee_id)),
            ]);

        // El efectivo a retirar del banco es el desglose mínimo del saldo aún
        // pendiente (total a cobrar menos lo ya cobrado en pagos parciales).
        $pendingAmounts = $payouts
            ->where('status', CashPayout::STATUS_PENDING)
            ->map(fn (array $p) => $p['total_due'] - $p['amount_paid'])
            ->filter(fn (float $v) => $v > 0)
            ->values();

        $totalCash = (float) $payouts->sum('total_due');

        // Los cobros (cash_payouts) se congelan al "Cerrar y preparar efectivo".
        // Si la nómina se recalculó después, quedan viejos: se detecta comparando
        // el efectivo del periodo ya congelado (period_amount) contra el actual
        // de los asientos. Si no cuadran, hay que aprobar y re-cerrar el efectivo.
        // Las transferencias (banco) viven en su propia pantalla (payroll.transfers).
        $entriesCashCurrent = $entriesByEmployee->sum(
            fn (PayrollEntry $e) => round((float) $e->cash_amount, 2)
        );
        $cashStale = abs($entriesCashCurrent - (float) $payouts->sum('period_amount')) > 0.01;

        $collectionClosed = $payroll->isCashCollectionClosed();
        $payroll->loadMissing(['cashCollectionClosedBy:id,name', 'cashReturnReceivedBy:id,name']);

        return Inertia::render('Payroll/Cash', [
            'period' => $payroll,
            'payouts' => $payouts,
            'cashStale' => $cashStale,
            'globalBreakdown' => $this->denominations->breakdownGlobal($pendingAmounts),
            'denominations' => CashDenominationService::DENOMINATIONS,
            // Denominaciones habilitadas del periodo (custodio desmarcó las que no
            // tiene). NULL = todas. Persistido por periodo para que el custodio y
            // el cobrador —en distintas máquinas— vean el MISMO desglose.
            'enabledDenominations' => $payroll->cash_enabled_denominations,
            'summary' => [
                'total_due' => $totalCash,
                'total_paid' => (float) $payouts->sum('amount_paid'),
                'total_pending' => (float) $payouts->where('status', CashPayout::STATUS_PENDING)->sum(fn (array $p) => max(0.0, $p['total_due'] - $p['amount_paid'])),
                'pending_count' => $payouts->where('status', CashPayout::STATUS_PENDING)->filter(fn (array $p) => ($p['total_due'] - $p['amount_paid']) > 0)->count(),
                'paid_count' => $payouts->where('status', CashPayout::STATUS_PAID)->count(),
                'total_cash' => $totalCash,
            ],
            // Estado del cierre del cobro y devolución del sobrante.
            'collection' => [
                'closed_at' => $payroll->cash_collection_closed_at,
                'closed_by_name' => $payroll->cashCollectionClosedBy?->name,
                'return_amount' => $payroll->cash_return_amount !== null ? (float) $payroll->cash_return_amount : null,
                'received_at' => $payroll->cash_return_received_at,
                'received_by_name' => $payroll->cashReturnReceivedBy?->name,
            ],
            // Billetes a regresar tras el cierre (snapshot del monto congelado).
            'returnBreakdown' => $collectionClosed
                ? $this->denominations->breakdown((int) round((float) $payroll->cash_return_amount))
                : [],
            'can' => [
                'payCash' => $user->hasPermissionTo('payroll.pay_cash'),
                'deliverCash' => $user->hasPermissionTo('payroll.cash.deliver'),
                'collectCash' => ($user->hasPermissionTo('payroll.pay_cash') || $user->hasPermissionTo('payroll.cash.collect'))
                    && $payroll->isCashDeliveryConfirmed()
                    && ! $collectionClosed,
                'closeCollection' => ($user->hasPermissionTo('payroll.pay_cash') || $user->hasPermissionTo('payroll.cash.close'))
                    && $payroll->isCashDeliveryConfirmed()
                    && ! $collectionClosed,
                'receiveReturn' => $user->hasPermissionTo('payroll.cash.receive')
                    && $collectionClosed
                    && ! $payroll->isCashReturnReceived(),
                'reopenCollection' => $user->hasPermissionTo('payroll.cash.receive')
                    && $collectionClosed
                    && ! $payroll->isCashReturnReceived(),
            ],
        ]);
    }

    /**
     * Pantalla independiente de transferencias (banco/CONTPAQi): el sueldo base
     * de quien NO cobra en efectivo (IMSS/banco). Solo informativa para hacer
     * las dispersiones; no requiere PIN ni forma parte del cobro en efectivo.
     */
    public function transfers(PayrollPeriod $payroll): Response
    {
        $user = auth()->user();
        if (! $user->hasPermissionTo('payroll.pay_cash')) {
            abort(403);
        }

        $entries = $payroll->entries()->with('employee:id,full_name,employee_number')->get();
        $transfers = $entries
            ->filter(fn (PayrollEntry $e) => (float) $e->bank_amount > 0)
            ->sortBy(fn (PayrollEntry $e) => $e->employee?->full_name)
            ->values()
            ->map(fn (PayrollEntry $e) => [
                'employee_name' => $e->employee?->full_name,
                'employee_number' => $e->employee?->employee_number,
                'amount' => (float) $e->bank_amount,
            ]);

        return Inertia::render('Payroll/Transfers', [
            'period' => $payroll,
            'transfers' => $transfers,
            'summary' => [
                'total_transfer' => (float) $entries->sum('bank_amount'),
                'transfer_count' => $transfers->count(),
            ],
        ]);
    }

    /**
     * Marcar un cobro como realizado validando el PIN personal del empleado.
     *
     * Liquida el total a cobrar (que ya incluye el acumulado) y salda de paso
     * los cobros previos pendientes que ese total arrastraba.
     */
    public function collectCash(Request $request, PayrollPeriod $payroll, CashPayout $payout): RedirectResponse
    {
        $user = auth()->user();
        if (! $user->hasPermissionTo('payroll.pay_cash') && ! $user->hasPermissionTo('payroll.cash.collect')) {
            abort(403);
        }

        // El cobrador solo cobra en SU nómina (general o taller).
        abort_unless($user->allowsCashPeriod($payroll), 403);

        if ($payout->payroll_period_id !== $payroll->id) {
            abort(404);
        }

        if (! $payroll->isCashClosed()) {
            return redirect()->back()
                ->with('error', 'Primero cierra y prepara el efectivo de este periodo.');
        }

        // No se puede cobrar (paso 2) sin haber preparado/confirmado la entrega
        // del efectivo (paso 1).
        if (! $payroll->isCashDeliveryConfirmed()) {
            return redirect()->back()
                ->with('error', 'Primero confirma la preparación del efectivo (Paso 1) antes de cobrar.');
        }

        // Con el cobro cerrado el efectivo ya se regresó a la empresa: el saldo
        // del empleado se acumula y se cobra la siguiente semana.
        if ($payroll->isCashCollectionClosed()) {
            return redirect()->back()
                ->with('error', 'La nomina ya esta cerrada; el efectivo se regreso y el saldo se acumula a la siguiente semana.');
        }

        if ($payout->status === CashPayout::STATUS_PAID) {
            return redirect()->back()
                ->with('error', 'Este cobro ya fue registrado.');
        }

        // El efectivo del empleado se cobra por su cobro MÁS RECIENTE, que ya
        // arrastra el acumulado de las semanas previas. Si existe uno posterior
        // pendiente, este ya quedó incluido ahí: cobrarlo por separado sería doble
        // pago. Se bloquea y se cobra desde el periodo más reciente.
        $rolledIntoLater = CashPayout::where('employee_id', $payout->employee_id)
            ->where('status', CashPayout::STATUS_PENDING)
            ->where('id', '!=', $payout->id)
            ->whereHas('payrollPeriod', fn ($q) => $q->where('start_date', '>', $payroll->start_date))
            ->exists();
        if ($rolledIntoLater) {
            return redirect()->back()
                ->with('error', 'Este efectivo ya se acumuló a una semana posterior. Cóbralo desde ese periodo (arrastra el total).');
        }

        $request->validate(['pin' => ['required', 'string']]);

        $payout->loadMissing('employee');

        if (! $payout->employee || ! $payout->employee->verifyCashPin($request->input('pin'))) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'pin' => 'Contraseña de cobro incorrecta.',
            ]);
        }

        DB::transaction(function () use ($payout, $payroll) {
            $payout->update([
                'status' => CashPayout::STATUS_PAID,
                'amount_paid' => $payout->total_due,
                'collected_at' => now(),
                'pin_verified' => true,
                'collected_by' => auth()->id(),
            ]);

            AuditLog::record(
                module: AuditLog::MODULE_CASH,
                action: AuditLog::ACTION_PAY,
                model: $payroll,
                description: "Registro el cobro en efectivo de {$payout->employee->full_name} en la nomina {$payroll->name} (\$".number_format((float) $payout->total_due, 2).')',
                employeeId: $payout->employee_id,
                subjectLabel: $payroll->name,
                metadata: ['amount_paid' => (float) $payout->total_due, 'employee_name' => $payout->employee->full_name],
            );

            // El total cobrado ya incluía el acumulado de periodos previos: saldar
            // esos cobros pendientes para que no reaparezcan en el siguiente cierre.
            CashPayout::where('employee_id', $payout->employee_id)
                ->where('payroll_period_id', '!=', $payout->payroll_period_id)
                ->where('status', CashPayout::STATUS_PENDING)
                ->whereHas('payrollPeriod', fn ($q) => $q->where('start_date', '<', $payroll->start_date))
                ->get()
                ->each(function (CashPayout $prior) {
                    $prior->update([
                        'status' => CashPayout::STATUS_PAID,
                        'amount_paid' => $prior->total_due,
                        'collected_at' => now(),
                    ]);
                });
        });

        return redirect()->route('payroll.cash', $payroll)
            ->with('success', "Cobro registrado para {$payout->employee->full_name}.");
    }

    /**
     * Cerrar el cobro del periodo (fin del paso 2), hecho por el cobrador.
     *
     * Congela cuánto efectivo debe regresar a la empresa (la suma de lo no
     * cobrado) y bloquea nuevos cobros en este periodo. Los payouts pendientes
     * NO se tocan: siguen pendientes y se acumulan al empleado en el cierre de
     * efectivo de la siguiente semana (opening_balance).
     */
    public function closeCashCollection(Request $request, PayrollPeriod $payroll): RedirectResponse
    {
        $user = auth()->user();
        if (! $user->hasPermissionTo('payroll.pay_cash') && ! $user->hasPermissionTo('payroll.cash.close')) {
            abort(403);
        }

        abort_unless($user->allowsCashPeriod($payroll), 403);

        if (! $payroll->isCashDeliveryConfirmed()) {
            return redirect()->back()
                ->with('error', 'No se puede cerrar el cobro: aun no se confirma la entrega del efectivo (Paso 1).');
        }

        if ($payroll->isCashCollectionClosed()) {
            return redirect()->back()
                ->with('error', 'El cobro de este periodo ya esta cerrado.');
        }

        // Efectivo a regresar = suma de los saldos aún no cobrados (incluye
        // pagos parciales por su outstanding). Se guarda EXACTO con centavos para
        // que cuadre con los montos por empleado (que ya no se redondean al peso);
        // el desglose físico de billetes se redondea aparte al mostrarse.
        $returnAmount = round(
            (float) $payroll->cashPayouts()->pending()->get()
                ->sum(fn (CashPayout $p) => max(0.0, $p->outstanding())),
            2
        );

        $payroll->update([
            'cash_collection_closed_at' => now(),
            'cash_collection_closed_by' => $user->id,
            'cash_return_amount' => $returnAmount,
        ]);

        AuditLog::record(
            module: AuditLog::MODULE_CASH,
            action: AuditLog::ACTION_CLOSE,
            model: $payroll,
            description: "Cerro el cobro en efectivo de la nomina {$payroll->name}. Efectivo devuelto: $".number_format($returnAmount, 2),
            subjectLabel: $payroll->name,
            metadata: ['cash_return_amount' => $returnAmount],
        );

        $formatted = number_format($returnAmount, 2);

        return redirect()->route('payroll.cash', $payroll)
            ->with('success', $returnAmount > 0
                ? "Nomina cerrada. Debes regresar \${$formatted} en efectivo a la empresa."
                : 'Nomina cerrada. Todos cobraron: no hay efectivo por regresar.');
    }

    /**
     * Confirmar que la empresa recibió el efectivo devuelto tras el cierre.
     * Exclusivo del custodio (superadmin). Con la recepción confirmada el
     * cierre queda definitivo (ya no se puede reabrir).
     */
    public function receiveCashReturn(Request $request, PayrollPeriod $payroll): RedirectResponse
    {
        if (! auth()->user()->hasPermissionTo('payroll.cash.receive')) {
            abort(403);
        }

        if (! $payroll->isCashCollectionClosed()) {
            return redirect()->back()
                ->with('error', 'El cobro de este periodo aun no se cierra.');
        }

        if ($payroll->isCashReturnReceived()) {
            return redirect()->back()
                ->with('error', 'El efectivo devuelto de este periodo ya se habia recibido.');
        }

        $payroll->update([
            'cash_return_received_at' => now(),
            'cash_return_received_by' => auth()->id(),
        ]);

        $returnAmount = (float) $payroll->cash_return_amount;
        AuditLog::record(
            module: AuditLog::MODULE_CASH,
            action: AuditLog::ACTION_CLOSE,
            model: $payroll,
            description: "Confirmo la recepcion del efectivo devuelto de la nomina {$payroll->name}. Monto: $".number_format($returnAmount, 2),
            subjectLabel: $payroll->name,
            metadata: ['cash_return_amount' => $returnAmount],
        );

        return redirect()->route('payroll.cash', $payroll)
            ->with('success', 'Efectivo devuelto recibido. Ciclo de efectivo del periodo completado.');
    }

    /**
     * Reabrir un cierre de cobro hecho por error. Exclusivo del custodio
     * (superadmin) y solo mientras NO haya confirmado la recepción del
     * efectivo devuelto.
     */
    public function reopenCashCollection(Request $request, PayrollPeriod $payroll): RedirectResponse
    {
        if (! auth()->user()->hasPermissionTo('payroll.cash.receive')) {
            abort(403);
        }

        if (! $payroll->isCashCollectionClosed()) {
            return redirect()->back()
                ->with('error', 'El cobro de este periodo no esta cerrado.');
        }

        if ($payroll->isCashReturnReceived()) {
            return redirect()->back()
                ->with('error', 'No se puede reabrir: el efectivo devuelto ya se recibio.');
        }

        $payroll->update([
            'cash_collection_closed_at' => null,
            'cash_collection_closed_by' => null,
            'cash_return_amount' => null,
        ]);

        AuditLog::record(
            module: AuditLog::MODULE_CASH,
            action: AuditLog::ACTION_REOPEN,
            model: $payroll,
            description: "Reabrio el cobro en efectivo de la nomina {$payroll->name}",
            subjectLabel: $payroll->name,
        );

        return redirect()->route('payroll.cash', $payroll)
            ->with('success', 'Cobro reabierto. Ya se puede volver a cobrar en este periodo.');
    }

    /**
     * Landing del cobrador: lista de periodos de SU nómina con el efectivo ya
     * preparado, para entrar a cobrar. También accesible para admin/superadmin
     * (ven todas).
     */
    public function cashCollectionIndex(): Response
    {
        $user = auth()->user();
        if (! $user->hasPermissionTo('payroll.pay_cash') && ! $user->hasPermissionTo('payroll.cash.collect')) {
            abort(403);
        }

        // has_separate_payroll viaja en el eager-load porque allowsCashPeriod()
        // decide el scope del cobrador_taller con esa bandera.
        $periods = PayrollPeriod::with('department:id,name,has_separate_payroll')
            ->whereNotNull('cash_closed_at')
            ->orderBy('start_date', 'desc')
            ->get()
            ->filter(fn (PayrollPeriod $p) => $user->allowsCashPeriod($p))
            ->values()
            ->take(26); // ~medio año de semanas; sin paginación para mantenerlo simple

        $pendingByPeriod = CashPayout::whereIn('payroll_period_id', $periods->pluck('id'))
            ->pending()
            ->get()
            ->groupBy('payroll_period_id')
            ->map(fn ($group) => (float) $group->sum(fn (CashPayout $p) => max(0.0, $p->outstanding())));

        return Inertia::render('Payroll/CashCollectionIndex', [
            'periods' => $periods->map(fn (PayrollPeriod $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'start_date' => $p->start_date?->toDateString(),
                'end_date' => $p->end_date?->toDateString(),
                'department_name' => $p->department?->name,
                'delivery_confirmed' => $p->isCashDeliveryConfirmed(),
                'collection_closed' => $p->isCashCollectionClosed(),
                'return_received' => $p->isCashReturnReceived(),
                'return_amount' => $p->cash_return_amount !== null ? (float) $p->cash_return_amount : null,
                'total_pending' => $pendingByPeriod->get($p->id, 0.0),
            ]),
        ]);
    }

    /**
     * Show detail for a single employee's payroll entry.
     */
    public function entryDetail(Request $request, PayrollEntry $entry): Response
    {
        $user = auth()->user();
        if (! $user->hasPermissionTo('payroll.view_basic') && ! $user->hasPermissionTo('payroll.view_complete')) {
            abort(403);
        }

        $entry->load(['employee.department', 'employee.position', 'employee.schedule', 'payrollPeriod']);

        // Canal a resaltar: al llegar desde "solo efectivo" / "solo transferencia"
        // el detalle muestra únicamente ese canal.
        $canal = $request->query('canal');
        if (! in_array($canal, ['efectivo', 'transfer'], true)) {
            $canal = null;
        }

        // CFDI timbrado del entry (si existe): uuid + disponibilidad de
        // descargas XML/PDF para el recibo.
        $stampedCfdi = $entry->cfdis()
            ->where('status', \App\Models\PayrollCfdi::STATUS_STAMPED)
            ->latest('stamped_at')
            ->first();

        return Inertia::render('Payroll/EntryDetail', [
            'entry' => $entry,
            'cashSplit' => $this->cashSplitForEntry($entry),
            'canal' => $canal,
            'cfdi' => $stampedCfdi ? [
                'uuid' => $stampedCfdi->uuid,
                'stamped_at' => $stampedCfdi->stamped_at?->toDateTimeString(),
                'has_xml' => (bool) $stampedCfdi->xml_path,
                'has_pdf' => (bool) $stampedCfdi->pdf_path,
            ] : null,
        ]);
    }

    /**
     * Reparto efectivo/transferencia del asiento para el detalle individual:
     * cuánto va por banco (base) y cuánto en efectivo, separando el efectivo de
     * ESTE periodo del acumulado que se recorrió sin cobrar de semanas previas.
     *
     * Si el efectivo del periodo ya se cerró usa el CashPayout congelado (la
     * fuente de verdad del cobro). Si aún no, calcula un estimado en vivo con la
     * misma regla del cierre (closeCash), para que el dato se vea desde antes.
     */
    private function cashSplitForEntry(PayrollEntry $entry): array
    {
        $payout = CashPayout::where('payroll_period_id', $entry->payroll_period_id)
            ->where('employee_id', $entry->employee_id)
            ->first();

        $paysBaseInCash = $entry->employee?->paysBaseInCash() ?? false;

        if ($payout) {
            return [
                'is_closed' => true,
                'pays_base_in_cash' => $paysBaseInCash,
                'bank_amount' => (float) $entry->bank_amount,
                'period_amount' => (float) $payout->period_amount,
                'opening_balance' => (float) $payout->opening_balance,
                'total_due' => (float) $payout->total_due,
                'amount_paid' => (float) $payout->amount_paid,
                'outstanding' => round($payout->outstanding(), 2),
                'status' => $payout->status,
            ];
        }

        // Estimado en vivo: el acumulado es el saldo del cobro pendiente más
        // reciente de un periodo anterior (mismo criterio que closeCash()).
        $priorPending = CashPayout::where('employee_id', $entry->employee_id)
            ->where('status', CashPayout::STATUS_PENDING)
            ->whereHas('payrollPeriod', fn ($q) => $q->where('start_date', '<', $entry->payrollPeriod?->start_date))
            ->with('payrollPeriod')
            ->get()
            ->sortByDesc(fn (CashPayout $p) => $p->payrollPeriod->start_date)
            ->first();

        $openingBalance = $priorPending ? round($priorPending->outstanding(), 2) : 0.0;
        $periodAmount = round((float) $entry->cash_amount, 2);
        $totalDue = round($periodAmount + $openingBalance, 2);

        return [
            'is_closed' => false,
            'pays_base_in_cash' => $paysBaseInCash,
            'bank_amount' => (float) $entry->bank_amount,
            'period_amount' => $periodAmount,
            'opening_balance' => $openingBalance,
            'total_due' => $totalDue,
            'amount_paid' => 0.0,
            'outstanding' => $totalDue,
            'status' => null,
        ];
    }

    /**
     * Delete a payroll period. Drafts can be deleted by anyone with payroll
     * permissions; admins may also delete calculated/approved/paid periods so
     * they can wipe and re-run test calculations.
     */
    public function destroy(PayrollPeriod $payroll): RedirectResponse
    {
        if (! auth()->user()->hasPermissionTo('payroll.create')) {
            abort(403);
        }

        if ($payroll->status !== 'draft' && ! auth()->user()->hasAnyRole(['superadmin', 'admin'])) {
            return redirect()->back()
                ->with('error', 'Solo se pueden eliminar periodos en borrador.');
        }

        $periodName = $payroll->name;
        $periodStatus = $payroll->status;

        $payroll->entries()->delete();
        $payroll->delete();

        AuditLog::record(
            module: AuditLog::MODULE_PAYROLL,
            action: AuditLog::ACTION_DELETE,
            model: null,
            description: "Elimino la nomina {$periodName} (estatus: {$periodStatus})",
            subjectLabel: $periodName,
            metadata: ['status' => $periodStatus],
        );

        return redirect()->route('payroll.index')
            ->with('success', 'Periodo de nomina eliminado.');
    }

    /**
     * Export payroll period to CONTPAQi format.
     */
    public function exportContpaqi(Request $request, PayrollPeriod $payroll): BinaryFileResponse|RedirectResponse
    {
        if (! auth()->user()->hasPermissionTo('payroll.export')) {
            abort(403);
        }

        // Validate period has entries
        if ($payroll->entries()->count() === 0) {
            return redirect()->back()
                ->with('error', 'No hay registros de nomina para exportar. Calcula la nomina primero.');
        }

        $format = $request->get('format', 'xlsx');
        $writerType = $format === 'csv' ? Excel::CSV : Excel::XLSX;

        // Sanitize period name for filename, tagging the period type so a
        // weekly (base) export is not confused with a monthly (extras) one.
        $periodName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $payroll->name);
        $typeTag = $payroll->isUnified()
            ? 'unificada'
            : (['weekly' => 'semanal', 'monthly' => 'mensual', 'biweekly' => 'quincenal'][$payroll->type] ?? $payroll->type);
        $filename = "prenomina_{$typeTag}_{$periodName}_{$payroll->start_date->format('Y-m-d')}.{$format}";

        AuditLog::record(
            module: AuditLog::MODULE_PAYROLL,
            action: AuditLog::ACTION_EXPORT,
            model: $payroll,
            description: "Exporto la nomina {$payroll->name} a CONTPAQi (formato: {$format})",
            subjectLabel: $payroll->name,
            metadata: ['format' => $format, 'filename' => $filename],
        );

        return (new ContpaqiPrenominaExport($payroll))->download($filename, $writerType);
    }

    /**
     * Genera el archivo de importación a CONTPAQi Nóminas (plantilla de Luis):
     * una hoja "Movimientos" con los movimientos variables de la semana por
     * empleado, más las hojas Catalogo e Instrucciones. Luis lo carga con el
     * Importador de movimientos de CONTPAQi.
     */
    public function exportContpaqiImport(Request $request, PayrollPeriod $payroll): BinaryFileResponse|RedirectResponse
    {
        if (! auth()->user()->hasPermissionTo('payroll.export')) {
            abort(403);
        }

        if ($payroll->entries()->count() === 0) {
            return redirect()->back()
                ->with('error', 'No hay registros de nomina para exportar. Calcula la nomina primero.');
        }

        $periodName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $payroll->name);
        $filename = "importacion_contpaqi_{$periodName}_{$payroll->start_date->format('Y-m-d')}.xlsx";

        AuditLog::record(
            module: AuditLog::MODULE_PAYROLL,
            action: AuditLog::ACTION_EXPORT,
            model: $payroll,
            description: "Genero el archivo de importacion a CONTPAQi de la nomina {$payroll->name}",
            subjectLabel: $payroll->name,
            metadata: ['filename' => $filename],
        );

        return (new ContpaqiImportExport($payroll))->download($filename);
    }
}
