<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for handling forced password changes on first login.
 */
class ForcePasswordChangeController extends Controller
{
    /**
     * Show the force password change form.
     */
    public function show(): Response
    {
        return Inertia::render('Auth/ForcePasswordChange');
    }

    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [
            'password.required' => 'La contraseña es obligatoria.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ]);

        $user = Auth::user();
        $user->update([
            'password' => $request->password,
            'must_change_password' => false,
        ]);

        return redirect()->route('dashboard')
            ->with('success', 'Contraseña actualizada exitosamente.');
    }
}
