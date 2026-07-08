<?php

namespace App\Http\Controllers;

use App\Models\BreakfastClaim;
use App\Models\Employee;
use App\Models\SystemSetting;
use App\Services\BreakfastClaimService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kiosco y consulta del módulo de desayunos.
 *
 * El kiosco corre en una sesión autenticada (usuario del vendedor o de RRHH)
 * con el permiso breakfasts.register; el empleado que desayuna se identifica
 * con su número, su rostro y su NIP — no necesita cuenta de usuario.
 */
class BreakfastController extends Controller
{
    public function __construct(
        private readonly BreakfastClaimService $service,
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
     * Look up an employee by number and report kiosk eligibility.
     */
    public function lookup(Request $request): JsonResponse
    {
        if (! auth()->user()->hasPermissionTo('breakfasts.register')) {
            abort(403);
        }

        $validated = $request->validate([
            'employee_number' => ['required', 'string'],
        ]);

        $employee = Employee::where('employee_number', $validated['employee_number'])->first();

        if (! $employee) {
            throw ValidationException::withMessages([
                'employee_number' => 'No existe un empleado con ese número.',
            ]);
        }

        $status = $this->service->statusFor($employee, Carbon::now());

        return response()->json([
            'employee' => [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
                'employee_number' => $employee->employee_number,
                'photo_url' => $employee->photo_path ? Storage::url($employee->photo_path) : null,
                'department' => $employee->department?->name,
            ],
            'status' => $status,
        ]);
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
            'pin' => ['required', 'string'],
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
     * List claimed breakfasts for a date range with vendor totals.
     */
    public function index(Request $request): Response
    {
        if (! auth()->user()->hasPermissionTo('breakfasts.view')) {
            abort(403);
        }

        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        // Rango por defecto: la semana en curso (lunes a domingo), igual que
        // el periodo semanal de nómina que le paga al vendedor.
        $startDate = $validated['start_date'] ?? Carbon::now()->startOfWeek()->toDateString();
        $endDate = $validated['end_date'] ?? Carbon::now()->endOfWeek()->toDateString();

        $claims = BreakfastClaim::with([
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
            ]);

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
            ],
            'vendor' => $vendor ? [
                'full_name' => $vendor->full_name,
                'employee_number' => $vendor->employee_number,
            ] : null,
            'canRegister' => auth()->user()->hasPermissionTo('breakfasts.register'),
        ]);
    }
}
