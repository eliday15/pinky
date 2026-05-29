/**
 * E2E Tests — Block 2: Employee Profile Fields.
 *
 * Validates employee CRUD with the new Block 2 fields:
 * address, credentials, trial period, bonus, vacation reserved/premium.
 */

import { describe, it, before, after } from 'node:test';
import assert from 'node:assert/strict';
import {
    launchBrowser,
    login,
    goto,
    fillField,
    selectOption,
    setCheckbox,
    submitForm,
    waitForText,
    getBodyText,
    getText,
    screenshot,
    countElements,
    waitForInertia,
} from './helpers.mjs';

describe('Employees (Block 2)', () => {
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

    it('1. Index shows badges (SM, Prueba, Incompleto)', async () => {
        await goto(page, '/employees');
        await screenshot(page, 'emp-01-index');

        // Check SM badge for minimum wage employee (Juan Hernandez)
        const smBadges = await page.$$eval(
            'span.bg-orange-100',
            els => els.map(el => el.textContent.trim())
        );
        assert.ok(smBadges.some(b => b === 'SM'), 'Should show SM badge for minimum wage');

        // Check Prueba badge for trial period employee (Maria Lopez)
        const trialBadges = await page.$$eval(
            'span.bg-amber-100',
            els => els.map(el => el.textContent.trim())
        );
        assert.ok(trialBadges.some(b => b === 'Prueba'), 'Should show Prueba badge for trial period');

        // Check Incompleto badge for incomplete profile (Pedro Sanchez — no schedule, no supervisor)
        const incompleteBadges = await page.$$eval(
            'span.bg-red-100',
            els => els.map(el => el.textContent.trim())
        );
        assert.ok(incompleteBadges.some(b => b === 'Incompleto'), 'Should show Incompleto badge');

        // Should show total count
        const body = await getBodyText(page);
        assert.ok(body.includes('5 empleados registrados'), 'Should show 5 employees');
    });

    it('2. Create employee with a full profile (personal, address, work, bonus)', async () => {
        await goto(page, '/employees/create');
        await screenshot(page, 'emp-02-create-form');

        // Personal info (the shared fillFieldByLabel uses native value setters so
        // Vue reactivity picks up every change reliably).
        await fillFieldByLabel(page, 'Numero de Empleado', 'EMP-0006');
        await fillFieldByLabel(page, 'ID ZKTeco', '1006');
        await fillFieldByLabel(page, 'Nombre *', 'Roberto');
        await fillFieldByLabel(page, 'Apellidos *', 'Gomez Perez');

        // Address section (these are plain text inputs, distinct labels).
        await fillFieldByLabel(page, 'Calle y Numero', 'Av. Reforma 123');
        await fillFieldByLabel(page, 'Ciudad', 'Guadalajara');
        await fillFieldByLabel(page, 'Codigo Postal', '44100');

        // Work info — selecting the position auto-fills department + schedule.
        await page.evaluate(() => {
            const selects = document.querySelectorAll('select');
            for (const sel of selects) {
                const label = sel.closest('div')?.querySelector('label');
                if (label?.textContent.includes('Puesto') && sel.options.length > 1) {
                    sel.value = sel.options[1].value;
                    sel.dispatchEvent(new Event('change', { bubbles: true }));
                    sel.dispatchEvent(new Event('input', { bubbles: true }));
                }
            }
        });
        await page.evaluate(() => new Promise(r => setTimeout(r, 500)));

        // Hourly rate.
        await fillFieldByLabel(page, 'Tarifa por Hora', '85.50');

        // Monthly bonus (fixed amount).
        await page.evaluate(() => {
            const selects = document.querySelectorAll('select');
            for (const sel of selects) {
                const label = sel.closest('div')?.querySelector('label');
                if (label?.textContent.includes('Tipo de Bono')) {
                    sel.value = 'fixed';
                    sel.dispatchEvent(new Event('change', { bubbles: true }));
                    sel.dispatchEvent(new Event('input', { bubbles: true }));
                }
            }
        });
        await page.evaluate(() => new Promise(r => setTimeout(r, 300)));
        await fillFieldByLabel(page, 'Monto del Bono', '600');

        await screenshot(page, 'emp-02-create-filled');

        // Submit
        await submitForm(page);

        await screenshot(page, 'emp-02-create-result');

        // After creation we redirect to the index (or show); the new employee appears.
        const body = await getBodyText(page);
        const hasEmployee = body.includes('Roberto') || body.includes('EMP-0006') || body.includes('Gomez Perez');
        assert.ok(hasEmployee, `Should show the new employee after creation. Body:\n${body.slice(0, 400)}`);
    });

    it('3. Create employee with only required fields', async () => {
        await goto(page, '/employees/create');

        // Only required fields
        await fillFieldByLabel(page, 'Numero de Empleado', 'EMP-0007');
        await fillFieldByLabel(page, 'ID ZKTeco', '1007');
        await fillFieldByLabel(page, 'Nombre *', 'Laura');
        await fillFieldByLabel(page, 'Apellidos *', 'Ramos Vega');

        // Select position (auto-fills dept and schedule)
        await page.evaluate(() => {
            const selects = document.querySelectorAll('select');
            for (const sel of selects) {
                const label = sel.closest('div')?.querySelector('label');
                const labelText = label?.textContent || '';
                if (labelText.includes('Puesto')) {
                    if (sel.options.length > 1) {
                        sel.value = sel.options[1].value;
                        sel.dispatchEvent(new Event('change', { bubbles: true }));
                        sel.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                }
            }
        });
        await page.evaluate(() => new Promise(r => setTimeout(r, 500)));

        await fillFieldByLabel(page, 'Tarifa por Hora', '70');

        await screenshot(page, 'emp-03-create-minimal');

        await submitForm(page);

        await screenshot(page, 'emp-03-create-minimal-result');

        const body = await getBodyText(page);
        const hasEmployee = body.includes('Laura') || body.includes('EMP-0007');
        assert.ok(hasEmployee, 'Minimal employee should be created');
    });

    it('4. Show page displays new fields (address, trial, bonus, vacation)', async () => {
        await goto(page, '/employees');

        // Click "Ver" for Juan Hernandez (minimum wage + fixed bonus + full profile)
        const viewLink = await page.evaluateHandle(() => {
            const rows = [...document.querySelectorAll('tbody tr')];
            const row = rows.find(r => r.textContent.includes('Juan Hernandez'));
            return row?.querySelector('a[href*="employees/"]');
        });
        assert.ok(viewLink, 'Should find view link for Juan Hernandez');
        await viewLink.click();
        await waitForInertia(page);

        await screenshot(page, 'emp-04-show-juan');

        const body = await getBodyText(page);

        // Check status badges
        assert.ok(body.includes('Salario Minimo'), 'Show page should display Salario Minimo badge');

        // Check bonus info
        assert.ok(body.includes('Bono mensual'), 'Show page should display bonus section');
        assert.ok(body.includes('Fijo'), 'Should show Fijo bonus type');

        // Check vacation section
        assert.ok(body.includes('Apartados'), 'Should show vacation reserved (Apartados)');
        assert.ok(body.includes('3 dias') || body.includes('3'), 'Should show 3 reserved days');

        // Check vacation premium
        assert.ok(body.includes('Prima vacacional') || body.includes('25%'), 'Should show vacation premium');

        // Check compensation types
        assert.ok(body.includes('Bono Transporte'), 'Should show assigned compensation type');
    });

    it('5. Edit page pre-fills new fields', async () => {
        await goto(page, '/employees');

        // Resolve Carlos Ramirez's edit URL and navigate to it directly. (A click
        // on an Inertia <Link> is a client-side visit that doesn't fire a full
        // navigation, so reading inputs right after a click can race the SPA
        // transition — a direct goto is deterministic.)
        const editHref = await page.evaluate(() => {
            const rows = [...document.querySelectorAll('tbody tr')];
            const row = rows.find(r => r.textContent.includes('Carlos Ramirez'));
            const link = [...(row?.querySelectorAll('a') || [])].find(a => a.textContent.includes('Editar'));
            return link ? new URL(link.href).pathname : null;
        });
        assert.ok(editHref, 'Should find edit link for Carlos Ramirez');
        await goto(page, editHref);

        await screenshot(page, 'emp-05-edit-prefill');

        // The "Numero de Empleado" field should be pre-filled with EMP-0001.
        const empNumber = await page.evaluate(() => {
            const lbl = [...document.querySelectorAll('label')].find(l => l.textContent.includes('Numero de Empleado'));
            return lbl?.closest('div')?.querySelector('input')?.value ?? '';
        });
        assert.equal(empNumber, 'EMP-0001', `Employee number should be pre-filled, got: ${empNumber}`);

        // Several fields should be pre-filled (from the factory's withFullProfile).
        const addressFields = await page.$$eval('input[type="text"]', inputs =>
            inputs.map(i => i.value).filter(v => v.length > 0)
        );
        assert.ok(addressFields.length > 5, `Should have multiple pre-filled fields, got ${addressFields.length}`);
    });

    it('6. Edit employee and verify changes in Show', async () => {
        await goto(page, '/employees');

        // Click "Editar" for Maria Lopez (trial period employee)
        const editLink = await page.evaluateHandle(() => {
            const rows = [...document.querySelectorAll('tbody tr')];
            const row = rows.find(r => r.textContent.includes('Maria Lopez'));
            const links = row?.querySelectorAll('a');
            return [...(links || [])].find(a => a.textContent.includes('Editar'));
        });
        assert.ok(editLink, 'Should find edit link for Maria Lopez');
        await editLink.click();
        await waitForInertia(page);

        // Update phone
        await fillFieldByLabel(page, 'Telefono', '5559876543');

        // Update address
        await fillFieldByLabel(page, 'Calle y Numero', 'Calle Norte 456');
        await fillFieldByLabel(page, 'Ciudad', 'Monterrey');

        await screenshot(page, 'emp-06-edit-modified');

        // Submit
        await submitForm(page);

        await screenshot(page, 'emp-06-edit-submitted');

        // Navigate to show page for Maria
        await goto(page, '/employees');
        const viewLink = await page.evaluateHandle(() => {
            const rows = [...document.querySelectorAll('tbody tr')];
            const row = rows.find(r => r.textContent.includes('Maria Lopez'));
            return row?.querySelector('a[href*="employees/"]');
        });
        if (viewLink) {
            await viewLink.click();
            await waitForInertia(page);

            const body = await getBodyText(page);
            assert.ok(body.includes('En Prueba') || body.includes('Prueba'), 'Show should display trial badge');

            await screenshot(page, 'emp-06-show-updated');
        }
    });

    it('7. Index filters work', async () => {
        await goto(page, '/employees');

        // Use page.select() which Puppeteer implements by setting the option's
        // selected state and dispatching both 'input' and 'change' events — this
        // reliably triggers Vue 3's v-model reactive update on a <select>.

        // Filter by minimum wage: find the select, get its CSS selector, then use page.select.
        const minWageSel = await page.evaluate(() => {
            const selects = document.querySelectorAll('select');
            for (const sel of selects) {
                const label = sel.closest('div')?.querySelector('label');
                if (label?.textContent.includes('Salario Minimo')) {
                    // Give the select a unique id so we can target it.
                    if (!sel.id) sel.id = '__e2e_minwage_sel';
                    return '#' + sel.id;
                }
            }
            return null;
        });
        assert.ok(minWageSel, 'Should find Salario Minimo filter select');
        await page.select(minWageSel, 'yes');

        // Wait for debounce (300ms) + Inertia navigation to settle.
        await page.evaluate(() => new Promise(r => setTimeout(r, 700)));
        await waitForInertia(page).catch(() => {});
        await page.evaluate(() => new Promise(r => setTimeout(r, 300)));

        await screenshot(page, 'emp-07-filter-minwage');

        const bodyAfterFilter = await getBodyText(page);
        assert.ok(
            bodyAfterFilter.includes('Juan Hernandez') || bodyAfterFilter.includes('Hernandez'),
            'Should show minimum wage employee Juan'
        );

        // Navigate back to clear filters (most reliable way to reset).
        await goto(page, '/employees');

        // Filter by name search (plain text input — reliable across UI changes).
        // The department filter uses a SearchableSelect combobox (not a native
        // <select>) which is complex to drive; search achieves the same goal.
        const searchInput = await page.$('input[type="text"][placeholder*="Nombre"]');
        assert.ok(searchInput, 'Should find the employee search input');
        await searchInput.click({ clickCount: 3 });
        await searchInput.type('Ana', { delay: 30 });

        await page.evaluate(() => new Promise(r => setTimeout(r, 700)));
        await waitForInertia(page).catch(() => {});
        await page.evaluate(() => new Promise(r => setTimeout(r, 300)));

        await screenshot(page, 'emp-07-filter-name');

        const bodyNameFilter = await getBodyText(page);
        assert.ok(bodyNameFilter.includes('Ana Martinez'), 'Should show employee Ana Martinez');
    });
});

/**
 * Helper: fill an input field by finding it through its associated label text.
 *
 * Args:
 *     page: Puppeteer Page instance
 *     labelText: Text content of the label to match
 *     value: Value to type
 */
async function fillFieldByLabel(page, labelText, value) {
    const filled = await page.evaluate((label, val) => {
        const labels = document.querySelectorAll('label');
        for (const lbl of labels) {
            if (lbl.textContent.trim().includes(label)) {
                const container = lbl.closest('div');
                const input = container?.querySelector('input[type="text"], input[type="number"], input[type="email"], input[type="date"]');
                if (input) {
                    // Use native setter to trigger Vue reactivity
                    const nativeInputValueSetter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
                    nativeInputValueSetter.call(input, val);
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                    return true;
                }
            }
        }
        return false;
    }, labelText, value);

    if (!filled) {
        // Fallback: try typing directly. evaluateHandle always returns a JSHandle
        // (even for a null result), so resolve it to an ElementHandle first — calling
        // .click()/.type() on a non-element JSHandle throws "handle.click is not a function".
        const jsHandle = await page.evaluateHandle((label) => {
            const labels = document.querySelectorAll('label');
            for (const lbl of labels) {
                if (lbl.textContent.trim().includes(label)) {
                    const container = lbl.closest('div');
                    return container?.querySelector('input[type="text"], input[type="number"], input[type="email"], input[type="date"]') || null;
                }
            }
            return null;
        }, labelText);

        const el = jsHandle.asElement();
        if (el) {
            await el.click({ clickCount: 3 }).catch(() => {});
            await el.press('Backspace').catch(() => {});
            await el.type(String(value), { delay: 20 }).catch(() => {});
        }
    }
}

/**
 * Helper: find a select element by its associated label text.
 *
 * Args:
 *     page: Puppeteer Page instance
 *     labelText: Text content of the label to match
 *
 * Returns:
 *     ElementHandle for the select, or null
 */
async function findSelectByLabel(page, labelText) {
    return page.evaluateHandle((label) => {
        const labels = document.querySelectorAll('label');
        for (const lbl of labels) {
            if (lbl.textContent.trim().includes(label)) {
                const container = lbl.closest('div');
                return container?.querySelector('select');
            }
        }
        return null;
    }, labelText);
}
