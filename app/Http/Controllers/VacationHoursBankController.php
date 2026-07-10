<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bolsa de "horas a cuenta de vacaciones" (Dani 2026-07-09).
 *
 * RRHH convierte N días de vacaciones de un colaborador en una bolsa de N×8
 * horas. El colaborador gasta esas horas de forma parcial en distintas fechas
 * (permisos de entrada tarde / salida temprano) hasta agotarlas. El descuento
 * del saldo de vacaciones es proporcional a las horas gastadas.
 */
class VacationHoursBankController extends Controller
{
    /** Página de gestión de la bolsa de horas. */
    public function index(Request $request): Response
    {
        $user = Auth::user();

        if (! $user->hasPermissionTo('vacation_hours.manage')) {
            abort(403);
        }

        $employees = Employee::active()
            ->with('department:id,name')
            ->when($request->search, fn ($q, $s) => $q->where('full_name', 'like', "%{$s}%"))
            ->when($request->boolean('enrolled_only'), fn ($q) => $q->where('vacation_hours_credited', '>', 0))
            ->orderBy('full_name')
            ->get()
            ->map(fn (Employee $e) => [
                'id' => $e->id,
                'full_name' => $e->full_name,
                'employee_number' => $e->employee_number,
                'department' => $e->department?->name,
                'vacation_days_remaining' => $e->vacation_days_remaining,
                'vacation_days_available' => round($e->vacation_days_available_for_request, 2),
                'hours_credited' => (float) $e->vacation_hours_credited,
                'hours_used' => (float) $e->vacation_hours_used,
                'hours_remaining' => round($e->vacation_hours_bank_remaining, 2),
                'enrolled' => $e->usesVacationHoursBank(),
            ]);

        return Inertia::render('VacationHours/Index', [
            'employees' => $employees,
            'filters' => $request->only(['search', 'enrolled_only']),
            'hoursPerDay' => Employee::VACATION_HOURS_PER_DAY,
        ]);
    }

    /**
     * Convertir N días de vacaciones en horas (credited += días × 8).
     * No consume el día ahora: el descuento del saldo es proporcional al gasto.
     */
    public function convert(Request $request): RedirectResponse
    {
        $user = Auth::user();

        if (! $user->hasPermissionTo('vacation_hours.manage')) {
            abort(403);
        }

        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'days' => ['required', 'integer', 'min:1', 'max:30'],
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);

        // Solo se pueden convertir días completos que el saldo respalde (contando
        // proporcionalmente las horas ya gastadas y las ya comprometidas en la
        // bolsa sin gastar).
        $committedUnspentDays = max(
            0.0,
            ((float) $employee->vacation_hours_credited - (float) $employee->vacation_hours_used) / Employee::VACATION_HOURS_PER_DAY,
        );
        $convertibleDays = (int) floor($employee->vacation_days_available_for_request - $committedUnspentDays);

        if ($validated['days'] > $convertibleDays) {
            return back()->withErrors([
                'days' => "No hay suficientes días de vacaciones para convertir. Disponibles: {$convertibleDays}, solicitados: {$validated['days']}.",
            ]);
        }

        $employee->increment('vacation_hours_credited', $validated['days'] * Employee::VACATION_HOURS_PER_DAY);

        $hours = $validated['days'] * Employee::VACATION_HOURS_PER_DAY;

        return back()->with('success', "Se convirtieron {$validated['days']} día(s) en {$hours} horas para {$employee->full_name}.");
    }

    /**
     * Revertir horas NO gastadas de la bolsa (reduce credited, nunca por debajo
     * de lo ya usado). Devuelve esas horas al saldo de días.
     */
    public function revert(Request $request): RedirectResponse
    {
        $user = Auth::user();

        if (! $user->hasPermissionTo('vacation_hours.manage')) {
            abort(403);
        }

        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'hours' => ['required', 'numeric', 'min:0.5'],
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);

        $unspent = (float) $employee->vacation_hours_credited - (float) $employee->vacation_hours_used;

        if ($validated['hours'] > $unspent + 0.001) {
            $unspentLabel = rtrim(rtrim(number_format(max(0, $unspent), 2), '0'), '.');

            return back()->withErrors([
                'hours' => "Solo puedes revertir horas no gastadas. Disponibles para revertir: {$unspentLabel} h.",
            ]);
        }

        $employee->decrement('vacation_hours_credited', $validated['hours']);

        return back()->with('success', "Se revirtieron {$validated['hours']} horas de la bolsa de {$employee->full_name}.");
    }
}
