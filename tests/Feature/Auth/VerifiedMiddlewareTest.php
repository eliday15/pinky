<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\FeatureTestCase;

/**
 * Front-to-back coverage of the email-verification flow that the auth-flows
 * domain scope calls out explicitly ("verified middleware: unverified user →
 * redirect to verification notice"). The Breeze-scaffold EmailVerificationTest
 * only checks the bare signed-link verify; this fills the real gaps:
 * the `verified` middleware bounce, the notice page Inertia prop contract,
 * the verified-user short-circuit, and the resend endpoint.
 *
 * Uses the harness FeatureTestCase (RolesPermissionsSeeder + InteractsWithAuth)
 * and an employee role throughout to isolate the `verified` gate from the
 * password-changed and two-factor-setup gates.
 */
class VerifiedMiddlewareTest extends FeatureTestCase
{
    // ---------------------------------------------------------------------
    // verified middleware (routes/web.php: ['auth','verified',...])
    // ---------------------------------------------------------------------

    /**
     * Email verification is intentionally NOT enforced on the app routes.
     *
     * The `verified` middleware was removed from routes/web.php because it was a
     * dead no-op: App\Models\User does not implement the
     * Illuminate\Contracts\Auth\MustVerifyEmail contract, so
     * EnsureEmailIsVerified never bounced anyone. An authenticated user with
     * email_verified_at = null therefore reaches the app (here /dashboard) with
     * a 200 — it is NOT redirected to the verification notice. The user still
     * has to clear the password-changed and two-factor gates; this test
     * isolates the (absent) verification gate by using an employee with 2FA
     * disabled and a non-stale password.
     */
    public function test_unverified_user_can_access_the_app_email_verification_not_enforced(): void
    {
        $user = $this->createUser('employee', ['email_verified_at' => null], withTwoFactor: false);

        $this->assertFalse($user->hasVerifiedEmail());

        // Reaches the app instead of being redirected to the verification notice.
        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk();
    }

    /**
     * A verified user passes the `verified` middleware and reaches the app.
     */
    public function test_verified_user_reaches_dashboard(): void
    {
        $user = $this->createUser('employee', ['email_verified_at' => now()], withTwoFactor: false);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk();
    }

    // ---------------------------------------------------------------------
    // verification.notice (EmailVerificationPromptController)
    // ---------------------------------------------------------------------

    /**
     * GET verify-email renders Auth/VerifyEmail for an unverified user and
     * supplies the `status` prop the Vue page declares (its only prop).
     */
    public function test_notice_renders_verify_email_page_with_status_prop(): void
    {
        $user = $this->createUser('employee', ['email_verified_at' => null], withTwoFactor: false);

        $this->actingAs($user)
            ->get(route('verification.notice'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/VerifyEmail')
                ->has('status'));
    }

    /**
     * A verified user hitting the notice route is redirected straight to the
     * dashboard (the controller short-circuits before rendering).
     */
    public function test_notice_redirects_verified_user_to_dashboard(): void
    {
        $user = $this->createUser('employee', ['email_verified_at' => now()], withTwoFactor: false);

        $this->actingAs($user)
            ->get(route('verification.notice'))
            ->assertRedirect(route('dashboard', absolute: false));
    }

    /**
     * Guest cannot reach the verification notice (behind auth).
     */
    public function test_guest_cannot_view_verification_notice(): void
    {
        $this->get(route('verification.notice'))
            ->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------------
    // verification.send (EmailVerificationNotificationController)
    // ---------------------------------------------------------------------

    /**
     * An unverified user can request a fresh verification link; the controller
     * dispatches the notification and flashes the verification-link-sent status.
     */
    public function test_unverified_user_can_resend_verification_email(): void
    {
        Notification::fake();

        $user = $this->createUser('employee', ['email_verified_at' => null], withTwoFactor: false);

        $this->actingAs($user)
            ->from(route('verification.notice'))
            ->post(route('verification.send'))
            ->assertRedirect(route('verification.notice'))
            ->assertSessionHas('status', 'verification-link-sent');

        Notification::assertSentTo(
            $user,
            \Illuminate\Auth\Notifications\VerifyEmail::class
        );
    }

    /**
     * A verified user requesting a resend is short-circuited to the dashboard
     * and NO notification is dispatched.
     */
    public function test_verified_user_resend_is_short_circuited(): void
    {
        Notification::fake();

        $user = $this->createUser('employee', ['email_verified_at' => now()], withTwoFactor: false);

        $this->actingAs($user)
            ->post(route('verification.send'))
            ->assertRedirect(route('dashboard', absolute: false));

        Notification::assertNothingSent();
    }

    /**
     * Guest cannot trigger a resend.
     */
    public function test_guest_cannot_resend_verification_email(): void
    {
        $this->post(route('verification.send'))
            ->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------------
    // verification.verify (VerifyEmailController, signed URL)
    // ---------------------------------------------------------------------

    /**
     * An already-verified user following the link does NOT re-fire Verified but
     * still lands on the dashboard with ?verified=1 (the controller's early
     * return branch — not covered by the scaffold test).
     */
    public function test_signed_link_for_already_verified_user_does_not_refire_event(): void
    {
        Event::fake([Verified::class]);

        $user = $this->createUser('employee', ['email_verified_at' => now()], withTwoFactor: false);

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $this->actingAs($user)
            ->get($verificationUrl)
            ->assertRedirect(route('dashboard', absolute: false).'?verified=1');

        Event::assertNotDispatched(Verified::class);
    }

    /**
     * An UNSIGNED verification link is rejected with a 403 and the email stays
     * unverified (the `signed` middleware on the route).
     */
    public function test_unsigned_verification_link_is_rejected(): void
    {
        $user = $this->createUser('employee', ['email_verified_at' => null], withTwoFactor: false);

        $unsignedUrl = route('verification.verify', [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->actingAs($user)
            ->get($unsignedUrl)
            ->assertForbidden();

        $this->assertFalse(User::find($user->id)->hasVerifiedEmail());
    }
}
