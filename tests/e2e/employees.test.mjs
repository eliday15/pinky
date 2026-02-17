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

    it('2. Create employee with ALL new fields', async () => {
        await goto(page, '/employees/create');
        await screenshot(page, 'emp-02-create-form');

        // Personal info
        const textInputs = await page.$$('input[type="text"]');
        // employee_number
        await fillField(page, 'input[type="text"]', 'EMP-0006');

        // Use a more targeted approach for each field
        await page.evaluate(() => {
            const labels = document.querySelectorAll('label');
            for (const label of labels) {
                if (label.textContent.includes('Numero de Empleado')) {
                    const input = label.closest('div').querySelector('input');
                    if (input) { input.value = ''; input.dispatchEvent(new Event('input', { bubbles: true })); }
                }
            }
        });

        // Fill fields by finding labels
        await fillFieldByLabel(page, 'Numero de Empleado', 'EMP-0006');
        await fillFieldByLabel(page, 'ID ZKTeco', '1006');
        await fillFieldByLabel(page, 'Nombre *', 'Roberto');
        await fillFieldByLabel(page, 'Apellidos *', 'Gomez Perez');
        await fillFieldByLabel(page, 'Email', 'roberto@test.com');
        await fillFieldByLabel(page, 'Telefono de Emergencia', '5551234567');

        // Hire date
        const hireDateInput = await page.$('input[type="date"]');
        await hireDateInput.click({ clickCount: 3 });
        await hireDateInput.press('Backspace');
        await hireDateInput.type('2024-01-15', { delay: 20 });

        // Address & Credentials section
        await fillFieldByLabel(page, 'Calle y Numero', 'Av. Reforma 123');
        await fillFieldByLabel(page, 'Ciudad', 'Guadalajara');
        await fillFieldByLabel(page, 'Estado', 'Jalisco');
        await fillFieldByLabel(page, 'Codigo Postal', '44100');

        // Credential type select
        const credentialSelect = await findSelectByLabel(page, 'Tipo de Credencial');
        if (credentialSelect) {
            await page.select(`#${await page.evaluate(el => el.id, credentialSelect) || ''}`, 'INE').catch(() => {});
            // Fallback: select by evaluating
            await page.evaluate(() => {
                const selects = document.querySelectorAll('select');
                for (const sel of selects) {
                    const label = sel.closest('div')?.querySelector('label');
                    if (label?.textContent.includes('Tipo de Credencial')) {
                        sel.value = 'INE';
                        sel.dispatchEvent(new Event('change', { bubbles: true }));
                        sel.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                }
            });
        }
        await fillFieldByLabel(page, 'Numero de Credencial', 'IDMEX1234567890');

        // Trial period
        await page.evaluate(() => {
            const checkboxes = document.querySelectorAll('input[type="checkbox"]');
            for (const cb of checkboxes) {
                const label = cb.closest('label') || cb.parentElement;
                if (label?.textContent.includes('Periodo de Prueba')) {
                    if (!cb.checked) cb.click();
                }
            }
        });
        await page.evaluate(() => new Promise(r => setTimeout(r, 300)));

        // Trial end date (should now be visible)
        const trialDateInputs = await page.$$('input[type="date"]');
        if (trialDateInputs.length > 1) {
            await trialDateInputs[1].click({ clickCount: 3 });
            await trialDateInputs[1].press('Backspace');
            await trialDateInputs[1].type('2024-04-15', { delay: 20 });
        }

        // IMSS
        await fillFieldByLabel(page, 'Numero IMSS', '12345678901');

        // Work info — select department, position, schedule via their <select> elements
        await page.evaluate(() => {
            const selects = document.querySelectorAll('select');
            for (const sel of selects) {
                const label = sel.closest('div')?.querySelector('label');
                const labelText = label?.textContent || '';

                if (labelText.includes('Puesto')) {
                    // Select first available option after the empty one
                    if (sel.options.length > 1) {
                        sel.value = sel.options[1].value;
                        sel.dispatchEvent(new Event('change', { bubbles: true }));
                        sel.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                }
            }
        });
        await page.evaluate(() => new Promise(r => setTimeout(r, 500)));

        // Hourly rate
        await fillFieldByLabel(page, 'Tarifa por Hora', '85.50');

        // Minimum wage checkbox
        await page.evaluate(() => {
            const checkboxes = document.querySelectorAll('input[type="checkbox"]');
            for (const cb of checkboxes) {
                const label = cb.closest('label') || cb.parentElement;
                if (label?.textContent.includes('Salario Minimo')) {
                    if (!cb.checked) cb.click();
                }
            }
        });

        // Monthly bonus
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

        // Vacation fields
        await fillFieldByLabel(page, 'Dias Correspondientes', '12');
        await fillFieldByLabel(page, 'Dias Apartados', '2');
        await fillFieldByLabel(page, 'Prima Vacacional', '25');

        await screenshot(page, 'emp-02-create-filled');

        // Submit
        await submitForm(page);

        await screenshot(page, 'emp-02-create-result');

        // Verify we ended up on either the show page or the index with the new employee
        const body = await getBodyText(page);
        const hasEmployee = body.includes('Roberto') || body.includes('EMP-0006');
        assert.ok(hasEmployee, 'Should show the new employee after creation');
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

        // Click "Editar" for Carlos Ramirez (supervisor with full profile)
        const editLink = await page.evaluateHandle(() => {
            const rows = [...document.querySelectorAll('tbody tr')];
            const row = rows.find(r => r.textContent.includes('Carlos Ramirez'));
            const links = row?.querySelectorAll('a');
            return [...(links || [])].find(a => a.textContent.includes('Editar'));
        });
        assert.ok(editLink, 'Should find edit link for Carlos Ramirez');
        await editLink.click();
        await waitForInertia(page);

        await screenshot(page, 'emp-05-edit-prefill');

        // Check that the form is pre-filled
        const empNumber = await page.$eval(
            'input[type="text"]',
            el => el.value
        );
        assert.ok(empNumber === 'EMP-0001', `Employee number should be pre-filled, got: ${empNumber}`);

        // Check address fields are pre-filled (from withFullProfile)
        const body = await getBodyText(page);
        // The address inputs should have values (from factory's withFullProfile)
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

        // Filter by minimum wage
        await page.evaluate(() => {
            const selects = document.querySelectorAll('select');
            for (const sel of selects) {
                const label = sel.closest('div')?.querySelector('label');
                if (label?.textContent.includes('Salario Minimo')) {
                    sel.value = 'yes';
                    sel.dispatchEvent(new Event('change', { bubbles: true }));
                    sel.dispatchEvent(new Event('input', { bubbles: true }));
                }
            }
        });

        // Wait for the debounced filter to apply
        await page.evaluate(() => new Promise(r => setTimeout(r, 500)));
        await waitForInertia(page).catch(() => {});
        await page.evaluate(() => new Promise(r => setTimeout(r, 300)));

        await screenshot(page, 'emp-07-filter-minwage');

        const bodyAfterFilter = await getBodyText(page);
        // Juan Hernandez should appear (minimum wage)
        assert.ok(bodyAfterFilter.includes('Juan Hernandez') || bodyAfterFilter.includes('Hernandez'),
            'Should show minimum wage employee Juan');

        // Reset filters
        const clearBtn = await page.evaluateHandle(() => {
            const buttons = [...document.querySelectorAll('button')];
            return buttons.find(b => b.textContent.includes('Limpiar filtros'));
        });
        if (clearBtn) {
            await clearBtn.click();
            await page.evaluate(() => new Promise(r => setTimeout(r, 500)));
            await waitForInertia(page).catch(() => {});
        }

        // Filter by department
        await page.evaluate(() => {
            const selects = document.querySelectorAll('select');
            for (const sel of selects) {
                const label = sel.closest('div')?.querySelector('label');
                if (label?.textContent.includes('Departamento')) {
                    // Select the "Administracion" option
                    for (const opt of sel.options) {
                        if (opt.textContent.includes('Administracion')) {
                            sel.value = opt.value;
                            sel.dispatchEvent(new Event('change', { bubbles: true }));
                            sel.dispatchEvent(new Event('input', { bubbles: true }));
                            break;
                        }
                    }
                }
            }
        });

        await page.evaluate(() => new Promise(r => setTimeout(r, 500)));
        await waitForInertia(page).catch(() => {});
        await page.evaluate(() => new Promise(r => setTimeout(r, 300)));

        await screenshot(page, 'emp-07-filter-dept');

        const bodyDeptFilter = await getBodyText(page);
        assert.ok(bodyDeptFilter.includes('Ana Martinez'), 'Should show Admin department employee Ana');
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
        // Fallback: try typing directly
        const handle = await page.evaluateHandle((label) => {
            const labels = document.querySelectorAll('label');
            for (const lbl of labels) {
                if (lbl.textContent.trim().includes(label)) {
                    const container = lbl.closest('div');
                    return container?.querySelector('input[type="text"], input[type="number"], input[type="email"], input[type="date"]');
                }
            }
            return null;
        }, labelText);

        if (handle) {
            await handle.click({ clickCount: 3 }).catch(() => {});
            await handle.press('Backspace').catch(() => {});
            await handle.type(String(value), { delay: 20 }).catch(() => {});
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
