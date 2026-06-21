<?php

namespace App\Http\Controllers;

use App\Exports\ContpaqiPrenominaExport;
use App\Http\Traits\VerifiesTwoFactor;
use App\Models\CashPayout;
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

        $periods = PayrollPeriod::with(['createdBy', 'approvedBy'])
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

        return Inertia::render('Payroll/Create', [
            'suggestedDates' => [
                'start_date' => $suggestedStart->toDateString(),
                'end_date' => $suggestedEnd->toDateString(),
                'payment_date' => $suggestedPayment->toDateString(),
            ],
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

        // Check for overlapping periods
        $overlap = PayrollPeriod::where(function ($q) use ($validated) {
            $q->whereBetween('start_date', [$validated['start_date'], $validated['end_date']])
                ->orWhereBetween('end_date', [$validated['start_date'], $validated['end_date']]);
        })->exists();

        if ($overlap) {
            return redirect()->back()
                ->withErrors(['start_date' => 'Ya existe un periodo de nomina que se traslapa con estas fechas.'])
                ->withInput();
        }

        $period = PayrollPeriod::create([
            ...$validated,
            'status' => 'draft',
            'created_by' => auth()->id(),
        ]);

        return redirect()->route('payroll.show', $period)
            ->with('success', 'Periodo de nomina creado. Presiona "Calcular" para generar la nomina.');
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

        $payroll->load(['createdBy', 'approvedBy']);

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

                // Nunca recalcular un cobro ya realizado.
                if ($existing && $existing->status === CashPayout::STATUS_PAID) {
                    continue;
                }

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

                // Acumulado: el cobro previo más reciente aún pendiente ya
                // arrastra (en su total_due) todo lo anterior, así que su saldo
                // pendiente es el acumulado completo a la fecha.
                $priorPending = CashPayout::where('employee_id', $entry->employee_id)
                    ->where('payroll_period_id', '!=', $payroll->id)
                    ->where('status', CashPayout::STATUS_PENDING)
                    ->whereHas('payrollPeriod', fn ($q) => $q->where('start_date', '<', $payroll->start_date))
                    ->with('payrollPeriod')
                    ->get()
                    ->sortByDesc(fn (CashPayout $p) => $p->payrollPeriod->start_date)
                    ->first();

                $openingBalance = $priorPending ? $priorPending->outstanding() : 0.0;
                $periodAmount = $this->denominations->roundToPeso((float) $entry->cash_amount);
                $totalDue = $periodAmount + $openingBalance;

                CashPayout::updateOrCreate(
                    ['payroll_period_id' => $payroll->id, 'employee_id' => $entry->employee_id],
                    [
                        'period_amount' => $periodAmount,
                        'opening_balance' => $openingBalance,
                        'total_due' => $totalDue,
                        'amount_paid' => 0,
                        'status' => CashPayout::STATUS_PENDING,
                        'denomination_breakdown' => $this->denominations->breakdown((int) round($totalDue)),
                    ]
                );
            }

            $payroll->update(['cash_closed_at' => now()]);
        });

        return redirect()->route('payroll.cash', $payroll)
            ->with('success', 'Efectivo preparado. Revisa el desglose de billetes.');
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
            ]);

        // El efectivo a retirar del banco es el desglose mínimo de lo que aún
        // está pendiente de cobro (solo los cobros con monto > 0).
        $pendingAmounts = $payouts
            ->where('status', CashPayout::STATUS_PENDING)
            ->where('total_due', '>', 0)
            ->pluck('total_due');

        // Transferencias: lo que va por banco/CONTPAQi (sueldo base de quien NO
        // cobra base en efectivo). Es solo informativo para hacer las
        // dispersiones; no requiere PIN. Se toma directo de cada asiento.
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

        $totalTransfer = (float) $entries->sum('bank_amount');
        $totalCash = (float) $payouts->sum('total_due');

        return Inertia::render('Payroll/Cash', [
            'period' => $payroll,
            'payouts' => $payouts,
            'transfers' => $transfers,
            'globalBreakdown' => $this->denominations->breakdownGlobal($pendingAmounts),
            'denominations' => CashDenominationService::DENOMINATIONS,
            'summary' => [
                'total_due' => $totalCash,
                'total_paid' => (float) $payouts->sum('amount_paid'),
                'total_pending' => (float) $payouts->where('status', CashPayout::STATUS_PENDING)->sum('total_due'),
                'pending_count' => $payouts->where('status', CashPayout::STATUS_PENDING)->where('total_due', '>', 0)->count(),
                'paid_count' => $payouts->where('status', CashPayout::STATUS_PAID)->count(),
                'total_transfer' => $totalTransfer,
                'total_cash' => $totalCash,
                'total_global' => $totalTransfer + $totalCash,
                'transfer_count' => $transfers->count(),
            ],
            'can' => [
                'payCash' => $user->hasPermissionTo('payroll.pay_cash'),
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

        if ($payout->status === CashPayout::STATUS_PAID) {
            return redirect()->back()
                ->with('error', 'Este cobro ya fue registrado.');
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
    public function entryDetail(PayrollEntry $entry): Response
    {
        $user = auth()->user();
        if (! $user->hasPermissionTo('payroll.view_basic') && ! $user->hasPermissionTo('payroll.view_complete')) {
            abort(403);
        }

        $entry->load(['employee.department', 'employee.position', 'employee.schedule', 'payrollPeriod']);

        return Inertia::render('Payroll/EntryDetail', [
            'entry' => $entry,
        ]);
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
