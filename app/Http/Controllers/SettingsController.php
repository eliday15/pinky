<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
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
    public function index(): Response
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
                ['key' => 'general', 'label' => 'General'],
            ],
            'can' => [
                'edit' => $user->hasPermissionTo('settings.edit'),
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

        foreach ($validated['settings'] as $setting) {
            SystemSetting::set($setting['key'], $setting['value']);
        }

        // Clear cache after updating
        SystemSetting::clearCache();

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

        SystemSetting::set($validated['key'], $validated['value']);

        return redirect()->back()->with('success', 'Configuracion actualizada.');
    }
}
