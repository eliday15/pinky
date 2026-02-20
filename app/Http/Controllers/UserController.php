<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * Controller for managing system users.
 */
class UserController extends Controller
{
    /**
     * Display a listing of users.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $query = User::with(['roles', 'employee:id,user_id,full_name']);

        // Apply search filter
        $query->when($request->search, function ($q, $search) {
            $q->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        });

        // Apply role filter
        $query->when($request->role, function ($q, $role) {
            $q->whereHas('roles', function ($query) use ($role) {
                $query->where('name', $role);
            });
        });

        // Apply 2FA status filter
        $query->when($request->has('two_factor') && $request->two_factor !== '', function ($q) use ($request) {
            if ($request->two_factor === 'enabled') {
                $q->whereNotNull('two_factor_secret')->whereNotNull('two_factor_confirmed_at');
            } else {
                $q->where(function ($query) {
                    $query->whereNull('two_factor_secret')->orWhereNull('two_factor_confirmed_at');
                });
            }
        });

        // Apply password status filter
        $query->when($request->has('password_status') && $request->password_status !== '', function ($q) use ($request) {
            if ($request->password_status === 'must_change') {
                $q->where('must_change_password', true);
            } else {
                $q->where('must_change_password', false);
            }
        });

        $users = $query->orderBy('name')->paginate(15)->withQueryString();

        // Append computed attributes for the frontend
        $users->getCollection()->transform(function ($user) {
            $user->two_factor_enabled = $user->hasTwoFactorEnabled();
            $user->requires_two_factor = $user->requiresTwoFactor();

            return $user;
        });

        $currentUser = Auth::user();

        return Inertia::render('Users/Index', [
            'users' => $users,
            'roles' => Role::orderBy('name')->pluck('name'),
            'filters' => $request->only(['search', 'role', 'two_factor', 'password_status']),
            'can' => [
                'create' => $currentUser->can('create', User::class),
            ],
        ]);
    }

    /**
     * Show the form for creating a new user.
     */
    public function create(): Response
    {
        $this->authorize('create', User::class);

        return Inertia::render('Users/Create', [
            'roles' => Role::orderBy('name')->pluck('name'),
            'employees' => Employee::active()
                ->whereNull('user_id')
                ->get(['id', 'full_name']),
        ]);
    }

    /**
     * Store a newly created user.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:app_users'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string', Rule::in(Role::pluck('name'))],
            'employee_id' => ['nullable', 'exists:employees,id'],
        ], [
            'name.required' => 'El nombre es obligatorio.',
            'email.required' => 'El correo electronico es obligatorio.',
            'email.unique' => 'Este correo electronico ya esta registrado.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'role.required' => 'El rol es obligatorio.',
        ]);

        // Validate employee is not already linked to another user
        if ($validated['employee_id']) {
            $employee = Employee::find($validated['employee_id']);
            if ($employee && $employee->user_id !== null) {
                return back()->withErrors(['employee_id' => 'Este empleado ya esta vinculado a otro usuario.']);
            }
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'must_change_password' => true,
            'email_verified_at' => now(),
        ]);

        $user->assignRole($validated['role']);

        // Link employee if provided
        if ($validated['employee_id']) {
            Employee::where('id', $validated['employee_id'])->update(['user_id' => $user->id]);
        }

        return redirect()->route('users.index')
            ->with('success', 'Usuario creado exitosamente.');
    }

    /**
     * Show the form for editing the specified user.
     */
    public function edit(User $user): Response
    {
        $this->authorize('update', $user);

        $user->load(['roles', 'employee:id,user_id,full_name']);

        // Add computed 2FA attributes (two_factor_secret is hidden from serialization)
        $user->two_factor_enabled = $user->hasTwoFactorEnabled();
        $user->requires_two_factor = $user->requiresTwoFactor();

        // Available employees: those without a user + the currently linked one
        $availableEmployees = Employee::active()
            ->where(function ($q) use ($user) {
                $q->whereNull('user_id')
                    ->orWhere('user_id', $user->id);
            })
            ->get(['id', 'full_name', 'user_id']);

        $currentUser = Auth::user();

        return Inertia::render('Users/Edit', [
            'editUser' => $user,
            'roles' => Role::orderBy('name')->pluck('name'),
            'employees' => $availableEmployees,
            'can' => [
                'delete' => $currentUser->can('delete', $user),
                'resetPassword' => $currentUser->can('resetPassword', $user),
            ],
        ]);
    }

    /**
     * Update the specified user.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('app_users')->ignore($user->id)],
            'role' => ['required', 'string', Rule::in(Role::pluck('name'))],
            'employee_id' => ['nullable', 'exists:employees,id'],
        ], [
            'name.required' => 'El nombre es obligatorio.',
            'email.required' => 'El correo electronico es obligatorio.',
            'email.unique' => 'Este correo electronico ya esta registrado.',
            'role.required' => 'El rol es obligatorio.',
        ]);

        // Validate employee is not already linked to another user
        if ($validated['employee_id']) {
            $employee = Employee::find($validated['employee_id']);
            if ($employee && $employee->user_id !== null && $employee->user_id !== $user->id) {
                return back()->withErrors(['employee_id' => 'Este empleado ya esta vinculado a otro usuario.']);
            }
        }

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
        ]);

        // Sync role
        $user->syncRoles([$validated['role']]);

        // Handle employee link change
        $currentEmployeeId = $user->employee?->id;
        $newEmployeeId = $validated['employee_id'] ? (int) $validated['employee_id'] : null;

        if ($currentEmployeeId !== $newEmployeeId) {
            // Unlink previous employee
            if ($currentEmployeeId) {
                Employee::where('id', $currentEmployeeId)->update(['user_id' => null]);
            }
            // Link new employee
            if ($newEmployeeId) {
                Employee::where('id', $newEmployeeId)->update(['user_id' => $user->id]);
            }
        }

        return redirect()->route('users.index')
            ->with('success', 'Usuario actualizado exitosamente.');
    }

    /**
     * Remove the specified user.
     */
    public function destroy(User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        // Unlink employee before deleting
        if ($user->employee) {
            Employee::where('user_id', $user->id)->update(['user_id' => null]);
        }

        $user->delete();

        return redirect()->route('users.index')
            ->with('success', 'Usuario eliminado exitosamente.');
    }

    /**
     * Reset a user's password and force them to change it on next login.
     */
    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $this->authorize('resetPassword', $user);

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ], [
            'password.required' => 'La contraseña es obligatoria.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
        ]);

        $user->update([
            'password' => $validated['password'],
            'must_change_password' => true,
        ]);

        return back()->with('success', 'Contraseña reseteada exitosamente. El usuario debera cambiarla en su proximo inicio de sesion.');
    }
}
