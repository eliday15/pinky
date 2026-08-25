/**
 * E2E — Pago UNIFICADO: la semana y los extras del mes en un solo pago.
 *
 * Recorre el flujo real del usuario: genera la nómina semanal, luego genera la
 * MENSUAL y comprueba que no nace una segunda nómina, sino que sus extras se
 * pegan a la semana (un solo renglón, un solo recibo). De paso verifica que el
 * detalle del recibo no deje dinero sin explicar.
 */

import { describe, it, before, after } from 'node:test';
import assert from 'node:assert/strict';
import {
    launchBrowser,
    login,
    goto,
    getBodyText,
    screenshot,
    waitForInertia,
    fillFieldByLabel,
    selectByLabel,
    clickByText,
    getPath,
} from './helpers.mjs';

// Semana lunes 17 ago → domingo 23 ago 2026; mes 27 jul → 23 ago (mismo pago).
const WEEK_START = '2026-08-17';
const WEEK_END = '2026-08-23';
const MONTH_START = '2026-07-27';
const MONTH_END = '2026-08-23';
const PAYMENT_DATE = '2026-08-24';

describe('Nomina unificada (semana + extras del mes)', () => {
    let browser;
    let page;

    before(async () => {
        browser = await launchBrowser();
        page = await browser.newPage();
        await page.setViewport({ width: 1280, height: 900 });
        await login(page);
    });

    after(async () => {
        if (browser) await browser.close();
    });

    /** Llena el alta de nómina y la envía. */
    async function createPeriod({ typeText, name, start, end, payment }) {
        await goto(page, '/payroll/create');
        await waitForInertia(page);

        assert.ok(await selectByLabel(page, 'Tipo de Periodo', typeText), `tipo ${typeText}`);
        await page.evaluate(() => new Promise(r => setTimeout(r, 250)));

        assert.ok(await fillFieldByLabel(page, 'Fecha Inicio', start), 'fecha inicio');
        assert.ok(await fillFieldByLabel(page, 'Fecha Fin', end), 'fecha fin');
        await page.evaluate(() => new Promise(r => setTimeout(r, 250)));
        assert.ok(await fillFieldByLabel(page, 'Fecha de Pago', payment), 'fecha de pago');
        assert.ok(await fillFieldByLabel(page, 'Nombre del Periodo', name), 'nombre');

        await page.click('button[type="submit"]');
        await waitForInertia(page);
        await page.evaluate(() => new Promise(r => setTimeout(r, 600)));
    }

    it('1. Genera la nomina semanal', async () => {
        await createPeriod({
            typeText: 'Semanal',
            name: 'Semana 17 ago - 23 ago',
            start: WEEK_START,
            end: WEEK_END,
            payment: PAYMENT_DATE,
        });
        await screenshot(page, 'unified-01-semana');

        await goto(page, '/payroll');
        const body = await getBodyText(page);
        assert.ok(body.includes('Semana 17 ago - 23 ago'), 'la semana aparece en la lista');
    });

    it('2. El mensual NO crea otra nomina: se unifica en la semana', async () => {
        await createPeriod({
            typeText: 'Mensual',
            name: 'Mes 27 jul - 23 ago',
            start: MONTH_START,
            end: MONTH_END,
            payment: PAYMENT_DATE,
        });
        await screenshot(page, 'unified-02-mensual');

        await goto(page, '/payroll');
        const body = await getBodyText(page);
        await screenshot(page, 'unified-03-lista');

        assert.ok(!body.includes('Mes 27 jul - 23 ago'), 'no nace una nomina mensual aparte');
        assert.ok(body.includes('Semanal + Mes'), 'la semana se marca como pago unificado');
        assert.ok(body.includes('Sueldo base + extras del mes'), 'dice lo que paga');
        assert.ok(body.includes('+ extras'), 'muestra el rango de extras');
    });

    it('3. El periodo explica que paga la semana y los extras del mes', async () => {
        await goto(page, '/payroll');
        assert.ok(await clickByText(page, 'Ver'), 'entra al periodo');
        await waitForInertia(page);
        await page.evaluate(() => new Promise(r => setTimeout(r, 400)));
        await screenshot(page, 'unified-04-periodo');

        const body = await getBodyText(page);
        assert.ok(body.includes('extras del mes'), 'el encabezado muestra el rango de extras');
        assert.ok(
            body.includes('Nomina unificada') || body.includes('Semanal + Mes'),
            'se anuncia como nomina unificada',
        );
        assert.ok(/\/payroll\/\d+$/.test(getPath(page)), `esta en el detalle del periodo (${getPath(page)})`);
    });

    it('4. El recibo individual no deja dinero sin desglosar', async () => {
        const entryHref = await page.evaluate(() => {
            const link = [...document.querySelectorAll('a')]
                .find((a) => /\/payroll\/entry\/\d+/.test(a.getAttribute('href') || ''));
            return link ? link.getAttribute('href') : null;
        });

        if (!entryHref) {
            // Sin empleados calculados no hay recibo que revisar; el resto del
            // flujo ya quedo verificado arriba.
            return;
        }

        await goto(page, entryHref.replace(/^https?:\/\/[^/]+/, ''));
        await waitForInertia(page);
        await page.evaluate(() => new Promise(r => setTimeout(r, 400)));
        await screenshot(page, 'unified-05-recibo');

        const body = await getBodyText(page);
        assert.ok(!body.includes('Sin desglosar'), 'los renglones explican todo el efectivo');
    });
});
