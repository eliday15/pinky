/**
 * E2E Tests — Authorizations journey.
 *
 * Covers the critical authorization flow:
 *  - index renders the seeded pending authorization
 *  - approve a pending authorization through the approve modal (which requires
 *    a 2FA code because the admin has a confirmed device) and verify the status
 *    transitions to "Aprobado"
 *
 * The seeded pending authorization is a "special" type (compensation: Bono
 * Transporte), which never auto-approves, so it is reliably actionable.
 */

import { describe, it, before, after } from 'node:test';
import assert from 'node:assert/strict';
import {
    launchBrowser,
    login,
    goto,
    waitForInertia,
    getBodyText,
    clickByText,
    totp,
    screenshot,
} from './helpers.mjs';

describe('Authorizations', () => {
    let browser;
    let page;

    before(async () => {
        browser = await launchBrowser();
        page = await browser.newPage();
        await page.setViewport({ width: 1280, height: 800 });
        await login(page);
    });

    after(async () => {
        if (browser) await browser.close();
    });

    it('1. Index renders with the seeded pending authorization', async () => {
        await goto(page, '/authorizations');
        await screenshot(page, 'auth-01-index');

        const body = await getBodyText(page);
        assert.ok(body.includes('Gestion de Autorizaciones'), 'Authorizations index heading should render');
        // Seeded pending authorization is for Carlos Ramirez Torres (supervisor).
        assert.ok(body.includes('Carlos Ramirez'), 'Seeded authorization employee should appear');
        assert.ok(body.includes('Pendiente'), 'A pending authorization should be listed');
    });

    it('2. Approve a pending authorization (via 2FA) -> status becomes Aprobado', async () => {
        await goto(page, '/authorizations');

        // Find the pending row for Carlos and click its "Aprobar" button.
        const opened = await page.evaluate(() => {
            const rows = [...document.querySelectorAll('tbody tr')];
            const row = rows.find(r => r.textContent.includes('Carlos Ramirez') && r.textContent.includes('Pendiente'));
            if (!row) return false;
            const btn = [...row.querySelectorAll('button')].find(b => b.textContent.trim() === 'Aprobar');
            if (!btn) return false;
            btn.click();
            return true;
        });
        assert.ok(opened, 'Should find and click Aprobar for the pending authorization');

        // The approve modal opens. Leave "hours" as "Sin cambio" and fill the 2FA
        // code (admin has_two_factor = true). Target the 6-digit input inside the
        // currently-open modal.
        // The approve modal renders a form with the 6-digit code input and a
        // submit button. It's the only <form> on the page once the modal opens.
        await page.waitForSelector('input[placeholder="000000"]', { timeout: 5000 });
        await screenshot(page, 'auth-02-approve-modal');

        const code = totp();
        await page.evaluate((c) => {
            // The open approve modal renders exactly one 000000 input.
            const input = document.querySelector('input[placeholder="000000"]');
            const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
            setter.call(input, c);
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }, code);
        await page.evaluate(() => new Promise(r => setTimeout(r, 200)));

        // Submit the modal's form directly (its submit button is labelled "Aprobar").
        // Note: a fixed-position modal has offsetParent === null, so don't filter on it.
        const submitted = await page.evaluate(() => {
            const input = document.querySelector('input[placeholder="000000"]');
            const form = input?.closest('form');
            if (!form) return false;
            const btn = form.querySelector('button[type="submit"]')
                || [...form.querySelectorAll('button')].find(b => /Aprobar|Guardando/.test(b.textContent));
            if (!btn) return false;
            btn.click();
            return true;
        });
        assert.ok(submitted, 'Should submit the approve modal');
        await waitForInertia(page);
        await page.evaluate(() => new Promise(r => setTimeout(r, 400)));

        await screenshot(page, 'auth-02-approve-result');

        // The Carlos row should now show "Aprobado".
        const approved = await page.evaluate(() => {
            const rows = [...document.querySelectorAll('tbody tr')];
            const row = rows.find(r => r.textContent.includes('Carlos Ramirez'));
            return row ? row.textContent.includes('Aprobado') : false;
        });
        const body = await getBodyText(page);
        assert.ok(
            approved || body.includes('aprobada'),
            `Authorization should be Aprobado after approval. Body:\n${body.slice(0, 500)}`
        );
    });
});
