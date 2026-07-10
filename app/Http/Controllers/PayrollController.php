<?php

namespace App\Http\Controllers;

use App\Exports\ContpaqiPrenominaExport;
use App\Http\Traits\VerifiesTwoFactor;
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
            ->orderBy('start_date', 'desc')
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

        return Inertia::render('Payroll/Create', [
            'suggestedDates' => [
                'start_date' => $suggestedStart->toDateString(),
                'end_date' => $suggestedEnd->toDateString(),
                'payment_date' => $suggestedPayment->toDateString(),
            ],
            'separatePayrollDepartments' => $separatePayrollDepartments,
        ]);
    }

    /**
     * Store a new payroll period and trigger calculation.
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
        ]);

        // "De un jalón": un solo alta genera la nómina GENERAL más una por cada
        // departamento con nómina propia (p. ej. Taller), todas con las mismas
        // fechas/tipo. Los alcances que ya existan para esas fechas se saltan
        // (así se puede completar los que falten sin duplicar).
        $scopes = collect([['id' => null, 'name' => 'General']])
            ->merge(
                Department::separatePayroll()->orderBy('name')->get(['id', 'name'])
                    ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])
            );

        $created = collect();
        $skipped = collect();

        foreach ($scopes as $scope) {
            $overlap = PayrollPeriod::where('department_id', $scope['id'])
                ->where(function ($q) use ($validated) {
                    $q->whereBetween('start_date', [$validated['start_date'], $validated['end_date']])
                        ->orWhereBetween('end_date', [$validated['start_date'], $validated['end_date']]);
                })->exists();

            if ($overlap) {
                $skipped->push($scope['name']);

                continue;
            }

            $created->push(PayrollPeriod::create([
                ...$validated,
                'name' => $scope['id'] ? $validated['name'].' - '.$scope['name'] : $validated['name'],
                'department_id' => $scope['id'],
                'status' => 'draft',
                'created_by' => auth()->id(),
            ]));
        }

        if ($created->isEmpty()) {
            return redirect()->back()
                ->withErrors(['start_date' => 'Ya existe(n) la(s) nomina(s) '.$skipped->implode(', ').' que se traslapan con estas fechas.'])
                ->withInput();
        }

        // Calcular cada una de inmediato (best-effort): si una falla queda en
        // draft para recalcular a mano y no tumba a las demás.
        foreach ($created as $period) {
            try {
                $this->calculator->calculatePeriod($period);
            } catch (\Throwable $e) {
                report($e);
                $period->update(['status' => 'draft']);
            }
        }

        $msg = 'Se generaron '.$created->count().' nómina(s): '.$created->pluck('name')->implode(', ').'.';
        if ($skipped->isNotEmpty()) {
            $msg .= ' Ya existían: '.$skipped->implode(', ').'.';
        }

        // Una sola → a su detalle; varias → a la lista para verlas todas.
        if ($created->count() === 1) {
            return redirect()->route('payroll.show', $created->first())->with('success', $msg);
        }

        return redirect()->route('payroll.index')->with('success', $msg);
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

        return Inertia::render('Payroll/Show', [
            'period' => $payroll,
            'entries' => $entries,
            'summary' => $summary,
            'can' => [
                'viewComplete' => $user->hasPermissionTo('payroll.view_complete'),
                'calculate' => $user->hasPermissionTo('payroll.calculate'),
                'approve' => $user->hasPermissionTo('payroll.approve'),
                'export' => $user->hasPermissionTo('payroll.export'),
                'payCash' => $user->hasPermissionTo('payroll.pay_cash'),
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

        $this->calculator->calculatePeriod($payroll);

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
        if (! auth()->user()->hasPermissionTo('payroll.pay_cash')) {
            abort(403);
        }

        if ($payroll->status !== 'approved') {
            return redirect()->back()
                ->with('error', 'Solo se puede preparar el efectivo de una nomina aprobada.');
        }

        DB::transaction(function () use ($payroll) {
            $entries = $payroll->entries()->with('employee')->get();

            foreach ($entries as $entry) {
                $existing = CashPayout::where('payroll_period_id', $payroll->id)
                    ->where('employee_id', $entry->employee_id)
                    ->first();

                // Re-sincroniza el reparto efectivo/transferencia con la regla
                // vigente y los flags ACTUALES del empleado (periodo de prueba /
                // IMSS), sin recalcular la nómina: usa el neto ya aprobado y solo
                // re-parte base→banco vs efectivo. Así "Recalcular efectivo"
                // aplica cambios de flags sin reabrir la nómina aprobada.
                if ($entry->employee) {
                    $netPay = (float) $entry->net_pay;
                    if ($entry->employee->paysBaseInCash()) {
                        $cashAmount = round($netPay, 2);
                        $bankAmount = 0.0;
                    } else {
                        $bankAmount = max(0.0, round((float) $entry->regular_pay - (float) $entry->deductions, 2));
                        $cashAmount = round($netPay - $bankAmount, 2);
                    }
                    $entry->update(['cash_amount' => $cashAmount, 'bank_amount' => $bankAmount]);
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

                $periodAmount = $this->denominations->roundToPeso((float) $entry->cash_amount);
                $totalDue = $periodAmount + $openingBalance;
                $outstanding = round($totalDue - $alreadyPaid, 2);

                // Ya pagado y sin diferencia a favor del empleado: se deja tal
                // cual (nunca genera saldo negativo / "descobro").
                if ($existing && $existing->status === CashPayout::STATUS_PAID && $outstanding <= 0.005) {
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
        if (! auth()->user()->hasPermissionTo('payroll.pay_cash')) {
            abort(403);
        }

        if (! $payroll->isCashClosed()) {
            return redirect()->back()
                ->with('error', 'Primero cierra y prepara el efectivo de este periodo.');
        }

        $payroll->update(['cash_delivery_confirmed_at' => now()]);

        return redirect()->route('payroll.cash', $payroll)
            ->with('success', 'Entrega del efectivo confirmada. Ya puedes cobrar.');
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
            if ($qty > 1) {
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
        if (! $user->hasPermissionTo('payroll.pay_cash')) {
            abort(403);
        }

        // Asientos por empleado: fuente del desglose concepto-por-concepto del
        // efectivo en el modal de cobro, y del chequeo de "desactualizado".
        $entriesByEmployee = $payroll->entries()->with('employee')->get()->keyBy('employee_id');

        $payouts = $payroll->cashPayouts()
            ->with('employee:id,full_name,employee_number,cash_pin')
            ->get()
            ->sortBy(fn (CashPayout $p) => $p->employee?->full_name)
            ->values()
            ->map(fn (CashPayout $p) => [
                'id' => $p->id,
                'employee_name' => $p->employee?->full_name,
                'employee_number' => $p->employee?->employee_number,
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
        $entriesCashRounded = $entriesByEmployee->sum(
            fn (PayrollEntry $e) => $this->denominations->roundToPeso((float) $e->cash_amount)
        );
        $cashStale = abs($entriesCashRounded - (float) $payouts->sum('period_amount')) > 0.5;

        return Inertia::render('Payroll/Cash', [
            'period' => $payroll,
            'payouts' => $payouts,
            'cashStale' => $cashStale,
            'globalBreakdown' => $this->denominations->breakdownGlobal($pendingAmounts),
            'denominations' => CashDenominationService::DENOMINATIONS,
            'summary' => [
                'total_due' => $totalCash,
                'total_paid' => (float) $payouts->sum('amount_paid'),
                'total_pending' => (float) $payouts->where('status', CashPayout::STATUS_PENDING)->sum(fn (array $p) => max(0.0, $p['total_due'] - $p['amount_paid'])),
                'pending_count' => $payouts->where('status', CashPayout::STATUS_PENDING)->filter(fn (array $p) => ($p['total_due'] - $p['amount_paid']) > 0)->count(),
                'paid_count' => $payouts->where('status', CashPayout::STATUS_PAID)->count(),
                'total_cash' => $totalCash,
            ],
            'can' => [
                'payCash' => $user->hasPermissionTo('payroll.pay_cash'),
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
        if (! auth()->user()->hasPermissionTo('payroll.pay_cash')) {
            abort(403);
        }

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

        return Inertia::render('Payroll/EntryDetail', [
            'entry' => $entry,
            'cashSplit' => $this->cashSplitForEntry($entry),
            'canal' => $canal,
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
        $periodAmount = $this->denominations->roundToPeso((float) $entry->cash_amount);
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

        if ($payroll->status !== 'draft' && ! auth()->user()->hasRole('admin')) {
            return redirect()->back()
                ->with('error', 'Solo se pueden eliminar periodos en borrador.');
        }

        $payroll->entries()->delete();
        $payroll->delete();

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
        $typeTag = ['weekly' => 'semanal', 'monthly' => 'mensual', 'biweekly' => 'quincenal'][$payroll->type] ?? $payroll->type;
        $filename = "prenomina_{$typeTag}_{$periodName}_{$payroll->start_date->format('Y-m-d')}.{$format}";

        return (new ContpaqiPrenominaExport($payroll))->download($filename, $writerType);
    }
}
