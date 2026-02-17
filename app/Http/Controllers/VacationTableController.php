<?php

namespace App\Http\Controllers;

use App\Models\VacationTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing the vacation entitlement table.
 */
class VacationTableController extends Controller
{
    /**
     * Display the vacation table.
     */
    public function index(): Response
    {
        $user = Auth::user();
        if (! $user->hasPermissionTo('settings.edit')) {
            abort(403);
        }

        $vacationTable = VacationTable::orderBy('years_of_service')->get();

        return Inertia::render('Settings/VacationTable', [
            'vacationTable' => $vacationTable,
        ]);
    }

    /**
     * Update the vacation table entries.
     */
    public function update(Request $request): RedirectResponse
    {
        $user = Auth::user();
        if (! $user->hasPermissionTo('settings.edit')) {
            abort(403);
        }

        $validated = $request->validate([
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.years_of_service' => ['required', 'integer', 'min:1'],
            'entries.*.vacation_days' => ['required', 'integer', 'min:1'],
        ]);

        // Replace all entries within a transaction for safety
        DB::transaction(function () use ($validated) {
            VacationTable::query()->delete();

            foreach ($validated['entries'] as $entry) {
                VacationTable::create($entry);
            }
        });

        return redirect()->route('settings.vacation-table')
            ->with('success', 'Tabla de vacaciones actualizada exitosamente.');
    }
}
