<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\SystemSetting;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing system settings.
 */
class SettingsController extends Controller
{
    /**
     * Display the settings index page.
     */
    public function index(TwoFactorService $twoFactorService): Response
    {
        $user = Auth::user();

        if (! $user->hasPermissionTo('settings.view')) {
            abort(403, 'No tienes permiso para ver la configuracion.');
        }

        $settings = SystemSetting::all()->groupBy('group');

        return Inertia::render('Settings/Index', [
            'settings' => $settings,
            'groups' => [
                ['key' => 'attendance', 'label' => 'Asistencia'],
                ['key' => 'payroll', 'label' => 'Nomina'],
                ['key' => 'fiscal', 'label' => 'Fiscal (ISR/IMSS)'],
                ['key' => 'empresa', 'label' => 'Empresa (CFDI)'],
                ['key' => 'breakfast', 'label' => 'Desayunos'],
                ['key' => 'general', 'label' => 'General'],
                ['key' => 'seguridad', 'label' => 'Seguridad'],
            ],
            // Para el selector del empleado VENDEDOR de desayunos
            // (breakfast_vendor_employee_id) en la pestaña Desayunos.
            'employees' => Employee::active()
                ->orderBy('full_name')
                ->get(['id', 'full_name', 'employee_number']),
            'can' => [
                'edit' => $user->hasPermissionTo('settings.edit'),
            ],
            'security' => [
                'twoFactorEnabled' => $user->hasTwoFactorEnabled(),
                'requiresTwoFactor' => $user->requiresTwoFactor(),
                'recoveryCodesCount' => $twoFactorService->remainingRecoveryCodesCount($user),
                'devices' => $user->twoFactorDevices()
                    ->whereNotNull('confirmed_at')
                    ->get(['id', 'name', 'confirmed_at', 'last_used_at']),
            ],
        ]);
    }

    /**
     * Display attendance settings.
     */
    public function attendance(): Response
    {
        $user = Auth::user();

        if (! $user->hasPermissionTo('settings.view')) {
            abort(403, 'No tienes permiso para ver la configuracion.');
        }

        $settings = SystemSetting::where('group', 'attendance')->get();

        return Inertia::render('Settings/Attendance', [
            'settings' => $settings,
            'can' => [
                'edit' => $user->hasPermissionTo('settings.edit'),
            ],
        ]);
    }

    /**
     * Display payroll settings.
     */
    public function payroll(): Response
    {
        $user = Auth::user();

        if (! $user->hasPermissionTo('settings.view')) {
            abort(403, 'No tienes permiso para ver la configuracion.');
        }

        $settings = SystemSetting::where('group', 'payroll')->get();

        return Inertia::render('Settings/Payroll', [
            'settings' => $settings,
            'can' => [
                'edit' => $user->hasPermissionTo('settings.edit'),
            ],
        ]);
    }

    /**
     * Display general settings.
     */
    public function general(): Response
    {
        $user = Auth::user();

        if (! $user->hasPermissionTo('settings.view')) {
            abort(403, 'No tienes permiso para ver la configuracion.');
        }

        $settings = SystemSetting::where('group', 'general')->get();

        return Inertia::render('Settings/General', [
            'settings' => $settings,
            'can' => [
                'edit' => $user->hasPermissionTo('settings.edit'),
            ],
        ]);
    }

    /**
     * Update settings.
     */
    public function update(Request $request): RedirectResponse
    {
        $user = Auth::user();

        if (! $user->hasPermissionTo('settings.edit')) {
            abort(403, 'No tienes permiso para editar la configuracion.');
        }

        $validated = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*.key' => ['required', 'string', 'exists:system_settings,key'],
            'settings.*.value' => ['required'],
        ]);

        $oldValues = [];
        $newValues = [];

        foreach ($validated['settings'] as $setting) {
            $oldValues[$setting['key']] = SystemSetting::get($setting['key']);
            SystemSetting::set($setting['key'], $setting['value']);
            $newValues[$setting['key']] = $setting['value'];
        }

        // Clear cache after updating
        SystemSetting::clearCache();

        AuditLog::record(
            module: AuditLog::MODULE_SETTINGS,
            action: AuditLog::ACTION_UPDATE,
            description: 'Actualizo la configuracion: ' . implode(', ', array_keys($newValues)),
            oldValues: $oldValues,
            newValues: $newValues,
        );

        return redirect()->back()->with('success', 'Configuracion actualizada exitosamente.');
    }

    /**
     * Update a single setting.
     */
    public function updateSingle(Request $request): RedirectResponse
    {
        $user = Auth::user();

        if (! $user->hasPermissionTo('settings.edit')) {
            abort(403, 'No tienes permiso para editar la configuracion.');
        }

        $validated = $request->validate([
            'key' => ['required', 'string', 'exists:system_settings,key'],
            'value' => ['required'],
        ]);

        $oldValue = SystemSetting::get($validated['key']);
        SystemSetting::set($validated['key'], $validated['value']);

        AuditLog::record(
            module: AuditLog::MODULE_SETTINGS,
            action: AuditLog::ACTION_UPDATE,
            description: "Actualizo la configuracion: {$validated['key']}",
            oldValues: [$validated['key'] => $oldValue],
            newValues: [$validated['key'] => $validated['value']],
        );

        return redirect()->back()->with('success', 'Configuracion actualizada.');
    }
}
