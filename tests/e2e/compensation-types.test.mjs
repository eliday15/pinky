/**
 * E2E Tests — Block 1: Compensation Types (percentage / fixed).
 *
 * Validates the full CRUD lifecycle for compensation types
 * including percentage and fixed amount configurations.
 */

import { describe, it, before, after } from 'node:test';
import assert from 'node:assert/strict';
import {
    launchBrowser,
    login,
    goto,
    fillField,
    clickRadio,
    submitForm,
    waitForText,
    getBodyText,
    screenshot,
    countElements,
    waitForInertia,
} from './helpers.mjs';

describe('Compensation Types (Block 1)', () => {
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

    it('1. Index loads with seeded compensation types', async () => {
        await goto(page, '/compensation-types');
        await screenshot(page, 'ct-01-index');

        const body = await getBodyText(page);

        // Should show the 3 seeded types
        assert.ok(body.includes('Bono Transporte'), 'Should show Bono Transporte');
        assert.ok(body.includes('Hora Extra Doble'), 'Should show Hora Extra Doble');
        assert.ok(body.includes('Dia Festivo'), 'Should show Dia Festivo');

        // Should show correct codes
        assert.ok(body.includes('TRANS'), 'Should show TRANS code');
        assert.ok(body.includes('HE-DBL'), 'Should show HE-DBL code');
        assert.ok(body.includes('FEST'), 'Should show FEST code');

        // Table rows: 3 seeded types
        const rows = await countElements(page, 'tbody tr');
        assert.ok(rows >= 3, `Should have at least 3 rows, got ${rows}`);
    });

    it('2. Create percentage compensation type', async () => {
        await goto(page, '/compensation-types/create');
        await screenshot(page, 'ct-02-create-pct-form');

        // Fill form for percentage type
        await fillField(page, 'input[type="text"]:nth-of-type(1)', 'Prima Dominical');

        // Fill the code field (second text input in the grid)
        const inputs = await page.$$('.grid input[type="text"]');
        await inputs[0].click({ clickCount: 3 });
        await inputs[0].press('Backspace');
        await inputs[0].type('Prima Dominical', { delay: 20 });
        await inputs[1].click({ clickCount: 3 });
        await inputs[1].press('Backspace');
        await inputs[1].type('PDOM', { delay: 20 });

        // Default is already 'percentage', fill value
        const pctInput = await page.$('input[type="number"][step="0.01"]');
        await pctInput.click({ clickCount: 3 });
        await pctInput.press('Backspace');
        await pctInput.type('25.00', { delay: 20 });

        await screenshot(page, 'ct-02-create-pct-filled');

        // Submit
        await submitForm(page);

        // Should redirect to index
        const body = await getBodyText(page);
        assert.ok(body.includes('Prima Dominical'), 'New type should appear in index');
        assert.ok(body.includes('PDOM'), 'New code should appear in index');

        await screenshot(page, 'ct-02-create-pct-result');
    });

    it('3. Create fixed compensation type', async () => {
        await goto(page, '/compensation-types/create');

        // Fill form for fixed type
        const inputs = await page.$$('.grid input[type="text"]');
        await inputs[0].click({ clickCount: 3 });
        await inputs[0].press('Backspace');
        await inputs[0].type('Bono Alimentacion', { delay: 20 });
        await inputs[1].click({ clickCount: 3 });
        await inputs[1].press('Backspace');
        await inputs[1].type('ALIM', { delay: 20 });

        // Switch to fixed type
        await clickRadio(page, 'fixed');

        // Wait for the fixed amount field to appear
        await page.evaluate(() => new Promise(r => setTimeout(r, 300)));

        // Fill fixed amount
        const amtInput = await page.$('input[type="number"][step="0.01"]');
        await amtInput.click({ clickCount: 3 });
        await amtInput.press('Backspace');
        await amtInput.type('200.00', { delay: 20 });

        await screenshot(page, 'ct-03-create-fixed-filled');

        // Submit
        await submitForm(page);

        // Should redirect to index
        const body = await getBodyText(page);
        assert.ok(body.includes('Bono Alimentacion'), 'Fixed type should appear in index');
        assert.ok(body.includes('ALIM'), 'Fixed code should appear in index');

        await screenshot(page, 'ct-03-create-fixed-result');
    });

    it('4. Edit compensation type — change value', async () => {
        await goto(page, '/compensation-types');

        // Find the "Editar" link for "Hora Extra Doble" and click it
        const editLink = await page.evaluateHandle(() => {
            const rows = [...document.querySelectorAll('tbody tr')];
            const row = rows.find(r => r.textContent.includes('Hora Extra Doble'));
            return row?.querySelector('a[href*="edit"]');
        });
        assert.ok(editLink, 'Should find edit link for Hora Extra Doble');
        await editLink.click();
        await waitForInertia(page);

        await screenshot(page, 'ct-04-edit-form');

        // Change percentage value from 50 to 75
        const pctInput = await page.$('input[type="number"][step="0.01"]');
        await pctInput.click({ clickCount: 3 });
        await pctInput.press('Backspace');
        await pctInput.type('75.00', { delay: 20 });

        await screenshot(page, 'ct-04-edit-modified');

        // Submit
        await submitForm(page);

        // Should redirect to index and show updated value
        const body = await getBodyText(page);
        assert.ok(body.includes('75.00%'), 'Should show updated percentage 75.00%');

        await screenshot(page, 'ct-04-edit-result');
    });

    it('5. Index shows correct badges and values', async () => {
        await goto(page, '/compensation-types');
        await screenshot(page, 'ct-05-badges');

        const body = await getBodyText(page);

        // Check type badges
        assert.ok(body.includes('Fijo ($)'), 'Should show Fijo ($) badge');
        assert.ok(body.includes('Porcentaje (%)'), 'Should show Porcentaje (%) badge');

        // Check formatted values
        assert.ok(body.includes('$150.00'), 'Should show $150.00 for Bono Transporte');
        assert.ok(body.includes('100.00%'), 'Should show 100.00% for Dia Festivo');

        // Check type-specific badge colors
        const fixedBadges = await page.$$eval(
            'span.bg-blue-100',
            els => els.map(el => el.textContent.trim())
        );
        assert.ok(fixedBadges.some(b => b.includes('Fijo')), 'Fixed types should have blue badge');

        const pctBadges = await page.$$eval(
            'span.bg-green-100',
            els => els.map(el => el.textContent.trim())
        );
        assert.ok(pctBadges.some(b => b.includes('Porcentaje')), 'Percentage types should have green badge');
    });
});
