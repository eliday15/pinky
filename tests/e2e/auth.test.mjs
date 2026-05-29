/**
 * E2E Tests — Authentication journey.
 *
 * Covers the login gate end-to-end:
 *  - wrong password shows an error and stays on /login
 *  - correct credentials reach the app (dashboard), past the mandatory-2FA gate
 *  - logout returns to a guest page
 *
 * The E2E admin (admin@test.com / admin role) has a seeded *confirmed* 2FA
 * device, so EnsureTwoFactorSetup lets it through and the (currently disabled)
 * per-login TOTP challenge is not triggered.
 */

import { describe, it, before, after } from 'node:test';
import assert from 'node:assert/strict';
import {
    launchBrowser,
    login,
    submitLoginForm,
    goto,
    fillField,
    waitForInertia,
    getBodyText,
    getPath,
    clickByText,
    screenshot,
} from './helpers.mjs';

describe('Authentication', () => {
    let browser;
    let page;

    before(async () => {
        browser = await launchBrowser();
        page = await browser.newPage();
        await page.setViewport({ width: 1280, height: 800 });
    });

    after(async () => {
        if (browser) await browser.close();
    });

    it('1. Wrong password shows an error and stays on /login', async () => {
        await goto(page, '/login');
        await fillField(page, '#email', 'admin@test.com');
        await fillField(page, '#password', 'wrong-password');
        await submitLoginForm(page);
        await waitForInertia(page);

        await screenshot(page, 'auth-01-bad-login');

        assert.equal(getPath(page), '/login', 'Should remain on /login after a bad password');

        const body = await getBodyText(page);
        // Laravel's auth failure message is "These credentials do not match our records."
        // (locale en in .env.testing). Assert an error surfaced rather than the exact string.
        const hasError = /credentials|match|incorrect|inválid|invalid/i.test(body);
        assert.ok(hasError, `Login page should show a credentials error. Body was:\n${body.slice(0, 400)}`);
    });

    it('2. Correct credentials reach the app (past the 2FA gate)', async () => {
        await login(page, 'admin@test.com', 'password');

        await screenshot(page, 'auth-02-after-login');

        const path = getPath(page);
        assert.ok(
            path !== '/login' && !path.startsWith('/two-factor'),
            `Should leave /login and not be bounced to 2FA setup; landed on ${path}`
        );

        // Admin lands on the dashboard.
        assert.equal(path, '/dashboard', `Admin should land on /dashboard, got ${path}`);

        const body = await getBodyText(page);
        assert.ok(body.includes('Dashboard'), 'Dashboard heading should render');
    });

    it('3. Can reach a protected page (/employees) while authenticated', async () => {
        await goto(page, '/employees');
        assert.equal(getPath(page), '/employees', 'Authenticated admin should reach /employees');

        const body = await getBodyText(page);
        assert.ok(
            body.includes('empleados') || body.includes('Empleados'),
            'Employees index should render'
        );
    });

    it('4. Logout returns to a guest page', async () => {
        // The logout control lives in the user dropdown in the app header.
        // Post directly to the logout route via an in-page form-equivalent:
        // Inertia exposes it as a POST link, so we trigger it programmatically.
        await goto(page, '/dashboard');

        await page.evaluate(() => {
            const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '/logout';
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = '_token';
            input.value = token || '';
            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
        });
        await page.waitForNavigation({ waitUntil: 'networkidle0', timeout: 10000 }).catch(() => {});

        await screenshot(page, 'auth-04-after-logout');

        // After logout, visiting a protected route must bounce to /login.
        await goto(page, '/employees');
        assert.equal(getPath(page), '/login', 'After logout, protected routes redirect to /login');
    });
});
