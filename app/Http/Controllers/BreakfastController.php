<?php

namespace App\Http\Controllers;

use App\Exports\BreakfastClaimsExport;
use App\Models\BreakfastClaim;
use App\Models\Employee;
use App\Models\SystemSetting;
use App\Models\BreakfastPayout;
use App\Services\BreakfastClaimService;
use App\Services\BreakfastPayoutService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Kiosco y consulta del módulo de desayunos.
 *
 * El kiosco corre en una sesión autenticada (usuario del vendedor o de RRHH)
 * con el permiso breakfasts.register; el empleado que desayuna se identifica
 * con su número, su rostro y su contraseña de cobro — no necesita cuenta.
 */
class BreakfastController extends Controller
{
    public function __construct(
        private readonly BreakfastClaimService $service,
        private readonly BreakfastPayoutService $payouts,
    ) {}

    /**
     * Kiosk screen where employees claim their breakfast.
     */
    public function kiosk(): Response
    {
        if (! auth()->user()->hasPermissionTo('breakfasts.register')) {
            abort(403);
        }

        return Inertia::render('Breakfasts/Kiosk', [
            'faceMaxDistance' => (float) SystemSetting::get('breakfast_face_max_distance', 0.5),
            'breakfastCost' => (float) SystemSetting::get('breakfast_cost', 0),
        ]);
    }

    /**
     * Search active employees by name (or exact number) for the kiosk.
     *
     * Nadie se sabe su número: el empleado teclea su nombre y toca su
     * tarjeta (con foto) entre las coincidencias. También acepta el número
     * exacto para quien sí lo conozca.
     */
    public function lookup(Request $request): JsonResponse
    {
        if (! auth()->user()->hasPermissionTo('breakfasts.register')) {
            abort(403);
        }

        $validated = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $query = trim($validated['query']);

        $matches = Employee::active()
            ->with('department:id,name')
            ->where(function ($builder) use ($query) {
                $builder->where('full_name', 'like', "%{$query}%")
                    ->orWhere('employee_number', $query);
            })
            ->orderBy('full_name')
            ->limit(8)
            ->get()
            // Autocomplete must stay light on the old kiosk PC. Original
            // employee photos are commonly 3–4 MB and decoding up to eight of
            // them can exhaust memory before facial recognition starts. The
            // selected employee's photo is returned by status() only.
            ->map(fn (Employee $employee) => $this->employeePayload($employee, includePhoto: false));

        return response()->json(['matches' => $matches]);
    }

    /**
     * Report kiosk eligibility for a selected employee.
     */
    public function status(Request $request): JsonResponse
    {
        if (! auth()->user()->hasPermissionTo('breakfasts.register')) {
            abort(403);
        }

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);

        return response()->json([
            'employee' => $this->employeePayload($employee),
            'status' => $this->service->statusFor($employee, Carbon::now()),
        ]);
    }

    /**
     * Public-safe employee payload for the kiosk screens.
     *
     * @return array<string, mixed>
     */
    private function employeePayload(Employee $employee, bool $includePhoto = true): array
    {
        return [
            'id' => $employee->id,
            'full_name' => $employee->full_name,
            'employee_number' => $employee->employee_number,
            'photo_url' => $includePhoto && $employee->photo_path
                ? Storage::url($employee->photo_path)
                : null,
            'department' => $employee->department?->name,
        ];
    }

    /**
     * Register a breakfast claim after face + PIN validation.
     */
    public function store(Request $request): JsonResponse
    {
        if (! auth()->user()->hasPermissionTo('breakfasts.register')) {
            abort(403);
        }

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'pin' => ['required', 'string', 'min:4', 'max:255'],
            'face_distance' => ['required', 'numeric', 'min:0'],
            'evidence' => ['nullable', 'string'],
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);

        $claim = $this->service->validateAndCreate(
            $employee,
            $validated['pin'],
            (float) $validated['face_distance'],
            $validated['evidence'] ?? null,
            $request->user(),
        );

        return response()->json([
            'claim' => [
                'id' => $claim->id,
                'employee_name' => $employee->full_name,
                'claimed_at' => $claim->claimed_at->format('H:i'),
                'unit_cost' => (float) $claim->unit_cost,
            ],
        ], 201);
    }

    /**
     * Descarga a Excel los desayunos del rango filtrado (Luis 2026-10-21),
     * con el total a pagar al vendedor al pie.
     */
    public function export(Request $request): BinaryFileResponse
    {
        if (! auth()->user()->hasPermissionTo('breakfasts.view')) {
            abort(403);
        }

        [$startDate, $endDate] = $this->resolveRange($request);

        return Excel::download(
            new BreakfastClaimsExport($this->claimsBetween($startDate, $endDate)),
            "desayunos_{$startDate}_{$endDate}.xlsx"
        );
    }

    /**
     * Rango Desde/Hasta validado. Por defecto, lo pendiente de pagar al
     * vendedor: del día siguiente al último corte hasta hoy. Sin cortes
     * todavía, la semana en curso (lunes a domingo).
     *
     * @return array{0: string, 1: string}
     */
    private function resolveRange(Request $request): array
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $nextStart = $this->payouts->nextStartDate();

        return [
            $validated['start_date'] ?? $nextStart ?? Carbon::now()->startOfWeek()->toDateString(),
            // Pagado hasta hoy: el rango queda en el día siguiente (vacío).
            $validated['end_date'] ?? ($nextStart ? max($nextStart, Carbon::today()->toDateString()) : Carbon::now()->endOfWeek()->toDateString()),
        ];
    }

    /**
     * Desayunos entregados en el rango, del más reciente al más antiguo.
     */
    private function claimsBetween(string $startDate, string $endDate): Collection
    {
        return BreakfastClaim::with([
            'employee:id,full_name,employee_number,department_id',
            'employee.department:id,name',
            'registeredBy:id,name',
        ])
            ->dateBetween($startDate, $endDate)
            ->orderByDesc('claimed_at')
            ->get()
            ->map(fn (BreakfastClaim $claim) => [
                'id' => $claim->id,
                'claim_date' => $claim->claim_date->format('Y-m-d'),
                'claimed_at' => $claim->claimed_at->format('Y-m-d H:i'),
                'employee_name' => $claim->employee?->full_name,
                'employee_number' => $claim->employee?->employee_number,
                'department' => $claim->employee?->department?->name,
                'unit_cost' => (float) $claim->unit_cost,
                'face_match_distance' => $claim->face_match_distance !== null
                    ? (float) $claim->face_match_distance
                    : null,
                'evidence_url' => $claim->evidence_photo_path
                    ? Storage::url($claim->evidence_photo_path)
                    : null,
                'registered_by' => $claim->registeredBy?->name,
                'paid' => $claim->breakfast_payout_id !== null,
            ]);
    }

    /**
     * List claimed breakfasts for a date range with vendor totals.
     */
    public function index(Request $request): Response
    {
        if (! auth()->user()->hasPermissionTo('breakfasts.view')) {
            abort(403);
        }

        [$startDate, $endDate] = $this->resolveRange($request);
        $claims = $this->claimsBetween($startDate, $endDate);

        $vendorId = (int) SystemSetting::get('breakfast_vendor_employee_id', 0);
        $vendor = $vendorId > 0 ? Employee::find($vendorId) : null;

        return Inertia::render('Breakfasts/Index', [
            'claims' => $claims,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'totals' => [
                'count' => $claims->count(),
                'amount' => round($claims->sum('unit_cost'), 2),
                'pending_count' => $claims->where('paid', false)->count(),
                'pending_amount' => round($claims->where('paid', false)->sum('unit_cost'), 2),
            ],
            'nextPayoutStart' => $this->payouts->nextStartDate(),
            'payouts' => BreakfastPayout::with(['paidBy:id,name', 'payrollPeriod:id,name,status,cash_closed_at'])
                ->orderByDesc('end_date')
                ->limit(10)
                ->get()
                ->map(fn (BreakfastPayout $payout) => [
                    'id' => $payout->id,
                    'start_date' => $payout->start_date->format('Y-m-d'),
                    'end_date' => $payout->end_date->format('Y-m-d'),
                    'claims_count' => $payout->claims_count,
                    'amount' => (float) $payout->amount,
                    'paid_by' => $payout->paidBy?->name,
                    'paid_at' => $payout->created_at?->format('Y-m-d H:i'),
                    'payroll' => $payout->payrollPeriod?->name,
                    'locked' => $payout->isLockedByPayroll(),
                ]),
            'canPay' => auth()->user()->hasPermissionTo('breakfasts.pay'),
            'vendor' => $vendor ? [
                'full_name' => $vendor->full_name,
                'employee_number' => $vendor->employee_number,
            ] : null,
            'canRegister' => auth()->user()->hasPermissionTo('breakfasts.register'),
        ]);
    }
}
