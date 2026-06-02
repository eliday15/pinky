/**
 * E2E Tests — Authorization bulk row editor.
 *
 * Regression coverage for the per-employee row editor on the bulk create page:
 *
 *  1. per_hour velada: a manually added row that crosses midnight (start on
 *     day 20 22:00, end on day 21 06:00) must keep its start date when the end
 *     datetime is set — setting the end used to clobber the shared row date —
 *     and the hours must compute to 8 across the day boundary.
 *  2. weekend attendance-pull: the "+ Agregar fila" button (which had gone
 *     missing) adds a manual row.
 *  3. per_day / one_time: every application mode exposes "+ Agregar fila", and
 *     one_time rows carry no date (quantity only).
 *
 * All flows run against the E2E-seeded employee "Ana Martinez Diaz" (EMP-0004),
 * who is enabled for the Velada (per_hour), Permiso por Dia (per_day), Bono
 * Unico (one_time) and Fin de Semana (weekend) compensation types.
 */

import { describe, it, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { launchBrowser, login, goto, screenshot } from './helpers.mjs';

const settle = (page, ms = 250) => page.evaluate((d) => new Promise(r => setTimeout(r, d)), ms);

/** Pick a <select> option by visible text across all selects on the page. */
async function selectType(page, text) {
    const ok = await page.evaluate((t) => {
        for (const sel of document.querySelectorAll('select')) {
            const opt = [...sel.options].find(o => o.textContent.trim() === t || o.textContent.includes(t));
            if (opt) {
                sel.value = opt.value;
                sel.dispatchEvent(new Event('change', { bubbles: true }));
                return true;
            }
        }
        return false;
    }, text);
    assert.ok(ok, `Type option "${text}" should exist`);
    await settle(page);
}

/** Tick the checkbox of the employee-list row containing the given name. */
async function selectEmployee(page, name) {
    const ok = await page.evaluate((nm) => {
        const row = [...document.querySelectorAll('div.cursor-pointer')]
            .find(d => d.querySelector('input[type="checkbox"]') && d.textContent.includes(nm));
        if (!row) return false;
        row.querySelector('input[type="checkbox"]').click();
        return true;
    }, name);
    assert.ok(ok, `Employee "${name}" should be selectable`);
    await settle(page);
}

async function clickButtonByText(page, text) {
    const ok = await page.evaluate((t) => {
        const btn = [...document.querySelectorAll('button')].find(b => b.textContent.trim().includes(t));
        if (!btn) return false;
        btn.click();
        return true;
    }, text);
    return ok;
}

/** Set the Nth datetime-local input via the native setter so Vue's @input fires. */
async function setDatetimeLocal(page, index, value) {
    await page.evaluate((idx, val) => {
        const input = [...document.querySelectorAll('input[type="datetime-local"]')][idx];
        const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
        setter.call(input, val);
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }, index, value);
    await settle(page);
}

describe('Authorization bulk row editor', () => {
    let browser;
    let page;

    before(async () => {
        browser = await launchBrowser();
        page = await browser.newPage();
        await page.setViewport({ width: 1280, height: 1000 });
        await login(page);
    });

    after(async () => {
        if (browser) await browser.close();
    });

    it('1. velada manual row keeps its start date across midnight and computes 8h', async () => {
        await goto(page, '/authorizations/create-bulk');
        await selectType(page, 'Velada');
        await selectEmployee(page, 'Ana Martinez');

        // Per-hour step renders with the "+ Agregar fila" button.
        const added = await clickButtonByText(page, 'Agregar fila');
        assert.ok(added, '"+ Agregar fila" should be present for per_hour velada');
        await settle(page);

        await page.waitForSelector('input[type="datetime-local"]', { timeout: 5000 });
        // Start on the 20th at 22:00, end on the 21st at 06:00.
        await setDatetimeLocal(page, 0, '2026-06-20T22:00');
        await setDatetimeLocal(page, 1, '2026-06-21T06:00');
        await screenshot(page, 'rows-01-velada-crossmidnight');

        const startVal = await page.$eval('input[type="datetime-local"]', el => el.value);
        assert.ok(
            startVal.startsWith('2026-06-20T22:00'),
            `Start should stay on 2026-06-20 after setting the end; got "${startVal}"`
        );

        // The escalonado hours <select> should have settled on 8h.
        const hoursVal = await page.evaluate(() => {
            const sel = [...document.querySelectorAll('select')].find(s =>
                [...s.options].some(o => o.textContent.trim() === '8'));
            return sel ? sel.value : null;
        });
        assert.equal(hoursVal, '8.00', 'Cross-midnight 22:00->06:00 should compute to 8h');
    });

    it('2. weekend (attendance-pull) exposes "+ Agregar fila" and adds a row', async () => {
        await goto(page, '/authorizations/create-bulk');
        await selectType(page, 'Fin de Semana');
        await selectEmployee(page, 'Ana Martinez');

        const beforeText = await page.evaluate(() => document.body.innerText);
        assert.ok(beforeText.includes('0 fin(es) de semana'), 'Should start with zero weekend rows');

        const added = await clickButtonByText(page, 'Agregar fila');
        assert.ok(added, '"+ Agregar fila" should be present for the weekend pull type');
        await settle(page);
        await screenshot(page, 'rows-02-weekend-addrow');

        const afterText = await page.evaluate(() => document.body.innerText);
        assert.ok(
            afterText.includes('1 fin(es) de semana'),
            'Adding a manual row should bump the weekend count to 1'
        );
    });

    it('3. per_day and one_time both expose "+ Agregar fila"', async () => {
        // per_day: row carries an editable date input.
        await goto(page, '/authorizations/create-bulk');
        await selectType(page, 'Permiso por Dia');
        await selectEmployee(page, 'Ana Martinez');
        // The range form already has date inputs; a manual row adds one more.
        const perDayDatesBefore = await page.$$eval('input[type="date"]', els => els.length);
        assert.ok(await clickButtonByText(page, 'Agregar fila'), 'per_day should expose "+ Agregar fila"');
        await settle(page);
        const perDayDatesAfter = await page.$$eval('input[type="date"]', els => els.length);
        assert.ok(perDayDatesAfter > perDayDatesBefore, 'per_day manual row should add an editable date input');
        await screenshot(page, 'rows-03-perday-addrow');

        // one_time: quantity-only row, no per-row date input added.
        await goto(page, '/authorizations/create-bulk');
        await selectType(page, 'Bono Unico');
        await selectEmployee(page, 'Ana Martinez');
        const dateInputsBefore = await page.$$eval('input[type="date"]', els => els.length);
        assert.ok(await clickButtonByText(page, 'Agregar fila'), 'one_time should expose "+ Agregar fila"');
        await settle(page);
        const numberInputs = await page.$$eval('input[type="number"]', els => els.length);
        const dateInputsAfter = await page.$$eval('input[type="date"]', els => els.length);
        assert.ok(numberInputs > 0, 'one_time manual row should render a quantity (number) input');
        assert.equal(dateInputsAfter, dateInputsBefore, 'one_time row should NOT add a date input');
        await screenshot(page, 'rows-04-onetime-addrow');
    });
});
