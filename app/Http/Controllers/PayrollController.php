<?php

namespace App\Http\Controllers;

use App\Exports\ContpaqiPrenominaExport;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Services\PayrollCalculatorService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PayrollController extends Controller
{
    public function __construct(
        private PayrollCalculatorService $calculator
    ) {}

    /**
     * Display list of payroll periods.
     */
    public function index(Request $request): Response
    {
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
        $payroll->load(['createdBy', 'approvedBy']);
        $user = auth()->user();

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
            ],
        ]);
    }

    /**
     * Calculate/recalculate payroll for a period.
     */
    public function calculate(PayrollPeriod $payroll): RedirectResponse
    {
        if (!in_array($payroll->status, ['draft', 'review'])) {
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
    public function approve(PayrollPeriod $payroll): RedirectResponse
    {
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
    public function markPaid(PayrollPeriod $payroll): RedirectResponse
    {
        if ($payroll->status !== 'approved') {
            return redirect()->back()
                ->with('error', 'Solo se pueden marcar como pagadas nominas aprobadas.');
        }

        $payroll->update(['status' => 'paid']);

        return redirect()->route('payroll.show', $payroll)
            ->with('success', 'Nomina marcada como pagada.');
    }

    /**
     * Show detail for a single employee's payroll entry.
     */
    public function entryDetail(PayrollEntry $entry): Response
    {
        $entry->load(['employee.department', 'employee.position', 'employee.schedule', 'payrollPeriod']);

        return Inertia::render('Payroll/EntryDetail', [
            'entry' => $entry,
        ]);
    }

    /**
     * Delete a payroll period (only if draft).
     */
    public function destroy(PayrollPeriod $payroll): RedirectResponse
    {
        if ($payroll->status !== 'draft') {
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
        // Validate period has entries
        if ($payroll->entries()->count() === 0) {
            return redirect()->back()
                ->with('error', 'No hay registros de nomina para exportar. Calcula la nomina primero.');
        }

        $format = $request->get('format', 'xlsx');
        $writerType = $format === 'csv' ? Excel::CSV : Excel::XLSX;

        // Sanitize period name for filename
        $periodName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $payroll->name);
        $filename = "prenomina_{$periodName}_{$payroll->start_date->format('Y-m-d')}.{$format}";

        return (new ContpaqiPrenominaExport($payroll))->download($filename, $writerType);
    }
}
