<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\FeatureTestCase;

/**
 * Front-to-back coverage of the forced password-change flow:
 * the EnsurePasswordChanged middleware redirect, the GET form render,
 * and the POST that clears the must_change_password flag.
 */
class ForcePasswordChangeTest extends FeatureTestCase
{
    /**
     * A user flagged must_change_password is redirected to the force-change
     * route by the EnsurePasswordChanged middleware when hitting the app.
     */
    public function test_must_change_password_user_is_redirected_by_middleware(): void
    {
        $user = $this->createUser('admin', ['must_change_password' => true]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route('password.force-change'))
            ->assertSessionHas('warning');
    }

    /**
     * GET force-password-change renders the Auth/ForcePasswordChange page.
     * The Vue page declares NO props, so we only assert the component.
     */
    public function test_force_change_form_renders_for_flagged_user(): void
    {
        $user = $this->createUser('employee', ['must_change_password' => true]);

        $this->actingAs($user)
            ->get(route('password.force-change'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/ForcePasswordChange'));
    }

    /**
     * The force-change route is reachable even by a user who is NOT flagged,
     * because the middleware whitelists password.force-change* unconditionally.
     */
    public function test_force_change_form_renders_for_unflagged_user(): void
    {
        $user = $this->createUser('employee', ['must_change_password' => false]);

        $this->actingAs($user)
            ->get(route('password.force-change'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/ForcePasswordChange'));
    }

    /**
     * Submitting a valid, confirmed new password clears the flag, persists the
     * new hash, and redirects to the dashboard with a success flash.
     */
    public function test_submitting_new_password_clears_flag_and_redirects(): void
    {
        $user = $this->createUser('employee', ['must_change_password' => true]);

        $response = $this->actingAs($user)->post(route('password.force-change.update'), [
            'password' => 'new-secret-password',
            'password_confirmation' => 'new-secret-password',
        ]);

        $response->assertRedirect(route('dashboard'))
            ->assertSessionHas('success', 'Contraseña actualizada exitosamente.');

        $fresh = User::find($user->id);
        $this->assertFalse((bool) $fresh->must_change_password);
        $this->assertTrue(Hash::check('new-secret-password', $fresh->password));
    }

    /**
     * After clearing the flag, the user is no longer bounced by the middleware
     * (employees have no 2FA requirement, so they reach the dashboard directly).
     */
    public function test_user_reaches_app_after_changing_password(): void
    {
        $user = $this->createUser('employee', ['must_change_password' => true]);

        $this->actingAs($user)->post(route('password.force-change.update'), [
            'password' => 'new-secret-password',
            'password_confirmation' => 'new-secret-password',
        ]);

        $this->actingAs(User::find($user->id))
            ->get('/dashboard')
            ->assertOk();
    }

    /**
     * Password is required.
     */
    public function test_password_is_required(): void
    {
        $user = $this->createUser('employee', ['must_change_password' => true]);

        $this->actingAs($user)
            ->from(route('password.force-change'))
            ->post(route('password.force-change.update'), [])
            ->assertSessionHasErrors(['password'])
            ->assertRedirect(route('password.force-change'));

        $this->assertTrue((bool) User::find($user->id)->must_change_password);
    }

    /**
     * Password must be confirmed (confirmation must match).
     */
    public function test_password_must_be_confirmed(): void
    {
        $user = $this->createUser('employee', ['must_change_password' => true]);

        $this->actingAs($user)
            ->from(route('password.force-change'))
            ->post(route('password.force-change.update'), [
                'password' => 'new-secret-password',
                'password_confirmation' => 'different-password',
            ])
            ->assertSessionHasErrors(['password'])
            ->assertRedirect(route('password.force-change'));

        $this->assertTrue((bool) User::find($user->id)->must_change_password);
    }

    /**
     * Password must satisfy Password::defaults() (min 8 chars by default).
     */
    public function test_password_must_meet_minimum_length(): void
    {
        $user = $this->createUser('employee', ['must_change_password' => true]);

        $this->actingAs($user)
            ->from(route('password.force-change'))
            ->post(route('password.force-change.update'), [
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertSessionHasErrors(['password']);

        $this->assertTrue((bool) User::find($user->id)->must_change_password);
    }

    /**
     * Guest cannot view the force-change form (it lives behind auth).
     */
    public function test_guest_cannot_view_force_change_form(): void
    {
        $this->get(route('password.force-change'))
            ->assertRedirect(route('login'));
    }

    /**
     * Guest cannot submit the force-change update.
     */
    public function test_guest_cannot_submit_force_change(): void
    {
        $this->post(route('password.force-change.update'), [
            'password' => 'new-secret-password',
            'password_confirmation' => 'new-secret-password',
        ])->assertRedirect(route('login'));
    }
}
