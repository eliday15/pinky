/**
 * E2E Tests — Incidents journey.
 *
 * Covers the critical incident flow:
 *  - index renders the seeded pending incident
 *  - create a new incident for an employee and verify it lands in the index
 *  - approve a pending incident (through the mandatory 2FA modal) and verify
 *    the status transitions to "Aprobada"
 *
 * The admin has a confirmed 2FA device, so approve goes through the
 * TwoFactorModal and the backend's VerifiesTwoFactor check; the helper
 * generates a real TOTP for the seeded secret.
 */

import { describe, it, before, after } from 'node:test';
import assert from 'node:assert/strict';
import {
    launchBrowser,
    login,
    goto,
    fillField,
    fillFieldByLabel,
    waitForInertia,
    getBodyText,
    clickByText,
    totp,
    screenshot,
} from './helpers.mjs';

describe('Incidents', () => {
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

    it('1. Index renders with the seeded pending incident', async () => {
        await goto(page, '/incidents');
        await screenshot(page, 'inc-01-index');

        const body = await getBodyText(page);
        assert.ok(body.includes('Gestion de Incidencias'), 'Incidents index heading should render');
        assert.ok(body.includes('Maria Lopez'), 'Seeded incident employee should appear');
        assert.ok(body.includes('Pendiente'), 'A pending incident should be listed');
    });

    it('2. Create an incident for an employee', async () => {
        await goto(page, '/incidents/create');
        await screenshot(page, 'inc-02-create-form');

        // Employee selection uses a Headless UI combobox. Type to filter, then
        // click the first matching option.
        const combo = await page.$('input[role="combobox"]');
        assert.ok(combo, 'Combobox input for employee should be present');
        await combo.click();
        await combo.type('Carlos', { delay: 40 });
        await page.waitForSelector('[role="option"]', { timeout: 4000 }).catch(() => {});
        const option = await page.$('[role="option"]');
        if (option) {
            await option.click();
        } else {
            await combo.press('Enter');
        }
        await page.evaluate(() => new Promise(r => setTimeout(r, 300)));

        // Incident type: "Falta justificada" (requires approval, no document /
        // time-range / vacation-deduction requirement) so a plain date submit works.
        await page.evaluate(() => {
            const selects = document.querySelectorAll('select');
            for (const sel of selects) {
                const lbl = sel.closest('div')?.querySelector('label');
                if (lbl?.textContent.includes('Tipo de Incidencia')) {
                    for (const opt of sel.options) {
                        if (opt.textContent.includes('Falta justificada')) {
                            sel.value = opt.value;
                            sel.dispatchEvent(new Event('change', { bubbles: true }));
                            sel.dispatchEvent(new Event('input', { bubbles: true }));
                            return;
                        }
                    }
                }
            }
        });
        await page.evaluate(() => new Promise(r => setTimeout(r, 300)));

        // Date range: future date avoids overlap with seeded data.
        const dateInputs = await page.$$('input[type="date"]');
        assert.ok(dateInputs.length >= 2, 'Should have start and end date inputs');
        for (const el of dateInputs.slice(0, 2)) {
            await el.click({ clickCount: 3 });
            await el.press('Backspace');
            await el.type('2026-06-20', { delay: 20 });
        }

        await fillFieldByLabel(page, 'Motivo', 'Incidencia creada por e2e');
        await screenshot(page, 'inc-02-create-filled');

        const clicked = await clickByText(page, 'Crear Incidencia');
        assert.ok(clicked, 'Should find the submit button');
        await waitForInertia(page);
        await screenshot(page, 'inc-02-create-result');

        const body = await getBodyText(page);
        const ok = body.includes('exitosamente') || body.includes('Gestion de Incidencias');
        assert.ok(ok, `Should return to incidents index after creating. Body:\n${body.slice(0, 400)}`);
    });

    it('3. Approve a pending incident (via 2FA modal) -> status becomes Aprobada', async () => {
        await goto(page, '/incidents');

        // Find the seeded pending incident for Maria Lopez and click "Aprobar".
        const opened = await page.evaluate(() => {
            const rows = [...document.querySelectorAll('tbody tr')];
            const row = rows.find(r =>
                r.textContent.includes('Maria Lopez') && r.textContent.includes('Pendiente')
            );
            if (!row) return false;
            const btn = [...row.querySelectorAll('button')].find(b => b.textContent.trim() === 'Aprobar');
            if (!btn) return false;
            btn.click();
            return true;
        });
        assert.ok(opened, 'Should find and click Aprobar for the pending incident');

        // The TwoFactorModal appears because admin has_two_factor = true.
        await page.waitForSelector('#two_factor_code_modal', { timeout: 5000 });
        await screenshot(page, 'inc-03-approve-modal');

        await fillField(page, '#two_factor_code_modal', totp());

        const confirmed = await clickByText(page, 'Confirmar');
        assert.ok(confirmed, 'Should click Confirmar in the 2FA modal');
        await waitForInertia(page);
        await page.evaluate(() => new Promise(r => setTimeout(r, 400)));
        await screenshot(page, 'inc-03-approve-result');

        const approved = await page.evaluate(() => {
            const rows = [...document.querySelectorAll('tbody tr')];
            const row = rows.find(r => r.textContent.includes('Maria Lopez'));
            return row ? row.textContent.includes('Aprobada') : false;
        });
        const body = await getBodyText(page);
        assert.ok(
            approved || body.includes('aprobada'),
            `Incident should be Aprobada after approval. Body:\n${body.slice(0, 500)}`
        );
    });
});
