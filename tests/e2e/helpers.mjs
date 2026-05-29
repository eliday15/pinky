/**
 * E2E Test Helpers.
 *
 * Provides reusable Puppeteer utilities for login, navigation,
 * form interaction, and screenshots.
 */

import puppeteer from 'puppeteer';
import { createHmac } from 'node:crypto';
import { mkdirSync, existsSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const SCREENSHOTS_DIR = resolve(__dirname, 'screenshots');
const BASE_URL = process.env.E2E_BASE_URL || 'http://127.0.0.1:8787';

/**
 * Base32 TOTP secret for the E2E admin's confirmed 2FA device.
 *
 * Must stay in sync with E2ETestSeeder::ADMIN_TOTP_SECRET so generated codes
 * verify against the seeded device.
 */
export const ADMIN_TOTP_SECRET = 'JBSWY3DPEHPK3PXP';

// Ensure screenshots directory exists
if (!existsSync(SCREENSHOTS_DIR)) {
    mkdirSync(SCREENSHOTS_DIR, { recursive: true });
}

/**
 * Decode an RFC 4648 base32 string to a Buffer.
 *
 * Args:
 *     b32: Base32-encoded string (case-insensitive, padding optional)
 *
 * Returns:
 *     Decoded bytes as a Buffer
 */
function base32Decode(b32) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const ch of b32.replace(/=+$/, '').toUpperCase()) {
        const idx = alphabet.indexOf(ch);
        if (idx < 0) continue;
        bits += idx.toString(2).padStart(5, '0');
    }
    const bytes = [];
    for (let i = 0; i + 8 <= bits.length; i += 8) {
        bytes.push(parseInt(bits.slice(i, i + 8), 2));
    }
    return Buffer.from(bytes);
}

/**
 * Generate a 6-digit TOTP code (RFC 6238, SHA-1, 30s step).
 *
 * Matches PragmaRX\Google2FA so the seeded admin device accepts the code.
 *
 * Args:
 *     secret: Base32 TOTP secret (defaults to the seeded admin secret)
 *     forTimeMs: Epoch milliseconds to compute the code for (defaults to now)
 *
 * Returns:
 *     A zero-padded 6-digit code string
 */
export function totp(secret = ADMIN_TOTP_SECRET, forTimeMs = Date.now()) {
    const key = base32Decode(secret);
    let counter = Math.floor(forTimeMs / 1000 / 30);
    const buf = Buffer.alloc(8);
    for (let i = 7; i >= 0; i--) {
        buf[i] = counter & 0xff;
        counter = Math.floor(counter / 256);
    }
    const hmac = createHmac('sha1', key).update(buf).digest();
    const offset = hmac[hmac.length - 1] & 0xf;
    const binary = ((hmac[offset] & 0x7f) << 24)
        | ((hmac[offset + 1] & 0xff) << 16)
        | ((hmac[offset + 2] & 0xff) << 8)
        | (hmac[offset + 3] & 0xff);
    return String(binary % 1000000).padStart(6, '0');
}

/**
 * Launch a headless Chromium browser instance.
 *
 * Returns:
 *     Puppeteer Browser instance
 */
export async function launchBrowser() {
    return puppeteer.launch({
        headless: true,
        // --disable-dev-shm-usage routes Chromium's shared memory to /tmp instead
        // of the small /dev/shm, which otherwise exhausts late in a multi-spec run
        // (each spec launches its own browser) and makes pages hang on load.
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage',
            '--disable-gpu',
        ],
    });
}

/**
 * Log in as a user via the login form.
 *
 * Args:
 *     page: Puppeteer Page instance
 *     email: User email address
 *     password: User password
 */
export async function login(page, email = 'admin@test.com', password = 'password') {
    // domcontentloaded fires fast and reliably even when the box is under load;
    // networkidle0 can stall past its timeout late in the suite. The #email wait
    // in fillField then ensures the form is actually interactive.
    await page.goto(`${BASE_URL}/login`, { waitUntil: 'domcontentloaded', timeout: 30000 });

    await fillField(page, '#email', email);
    await fillField(page, '#password', password);
    await submitLoginForm(page);
    await waitForInertia(page);
}

/**
 * Submit the login form.
 *
 * The login button is a Breeze PrimaryButton (a plain <button> with no explicit
 * type attribute), so `button[type="submit"]` does not match it. Submit by
 * pressing Enter in the password field, which natively submits the form.
 *
 * Args:
 *     page: Puppeteer Page instance
 */
export async function submitLoginForm(page) {
    const pwd = await page.$('#password');
    if (pwd) {
        await pwd.press('Enter');
        return;
    }
    // Fallback: click the only button inside the login form.
    await page.evaluate(() => {
        const form = document.querySelector('form');
        form?.querySelector('button')?.click();
    });
}

/**
 * Navigate to a route and wait for the page to settle.
 *
 * Args:
 *     page: Puppeteer Page instance
 *     path: URL path (e.g., '/employees')
 */
export async function goto(page, path) {
    // domcontentloaded won't stall under load; then best-effort wait for the
    // Inertia/XHR data to settle so assertions see a rendered page.
    await page.goto(`${BASE_URL}${path}`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.waitForNetworkIdle({ idleTime: 350, timeout: 15000 }).catch(() => {});
    await page.evaluate(() => new Promise(r => setTimeout(r, 200)));
}

/**
 * Wait for an Inertia page transition to complete.
 *
 * Uses networkidle0 to detect when all requests have finished,
 * then adds a small delay for Vue reactivity.
 *
 * Args:
 *     page: Puppeteer Page instance
 *     timeout: Maximum wait time in ms
 */
export async function waitForInertia(page, timeout = 20000) {
    // Inertia visits are XHR/fetch, not full navigations, so waitForNavigation
    // never resolves and would burn the whole timeout. Race a real navigation
    // (full page load, e.g. after logout) against network-idle detection (SPA
    // visit) so whichever happens first wins — fast and reliable for both.
    await Promise.race([
        page.waitForNavigation({ waitUntil: 'networkidle0', timeout }).catch(() => {}),
        page.waitForNetworkIdle({ idleTime: 350, timeout }).catch(() => {}),
    ]);
    // Allow Vue reactivity to settle
    await page.evaluate(() => new Promise(r => setTimeout(r, 300)));
}

/**
 * Clear a field and type a new value.
 *
 * Uses triple-click to select all text, then replaces it.
 *
 * Args:
 *     page: Puppeteer Page instance
 *     selector: CSS selector for the input field
 *     value: Value to type into the field
 */
export async function fillField(page, selector, value) {
    const el = await page.waitForSelector(selector, { timeout: 20000 });
    await el.click({ clickCount: 3 });
    await el.press('Backspace');
    await el.type(String(value), { delay: 20 });
}

/**
 * Select an option from a <select> element by value.
 *
 * Args:
 *     page: Puppeteer Page instance
 *     selector: CSS selector for the select element
 *     value: Option value to select
 */
export async function selectOption(page, selector, value) {
    await page.waitForSelector(selector, { timeout: 20000 });
    await page.select(selector, String(value));
    // Trigger Vue change detection
    await page.evaluate((sel) => {
        const el = document.querySelector(sel);
        el.dispatchEvent(new Event('change', { bubbles: true }));
    }, selector);
    await page.evaluate(() => new Promise(r => setTimeout(r, 200)));
}

/**
 * Click a radio button by its value attribute.
 *
 * Args:
 *     page: Puppeteer Page instance
 *     name: Radio group name or common selector attribute
 *     value: Radio button value to click
 */
export async function clickRadio(page, value) {
    const selector = `input[type="radio"][value="${value}"]`;
    await page.waitForSelector(selector, { timeout: 20000 });
    await page.click(selector);
    await page.evaluate(() => new Promise(r => setTimeout(r, 200)));
}

/**
 * Toggle a checkbox element.
 *
 * Args:
 *     page: Puppeteer Page instance
 *     selector: CSS selector for the checkbox
 *     checked: Desired state (true = checked, false = unchecked)
 */
export async function setCheckbox(page, selector, checked) {
    await page.waitForSelector(selector, { timeout: 20000 });
    const isChecked = await page.$eval(selector, el => el.checked);
    if (isChecked !== checked) {
        await page.click(selector);
        await page.evaluate(() => new Promise(r => setTimeout(r, 200)));
    }
}

/**
 * Get visible text content of an element.
 *
 * Args:
 *     page: Puppeteer Page instance
 *     selector: CSS selector
 *
 * Returns:
 *     Trimmed text content, or empty string if not found
 */
export async function getText(page, selector) {
    try {
        await page.waitForSelector(selector, { timeout: 20000 });
        return page.$eval(selector, el => el.textContent.trim());
    } catch {
        return '';
    }
}

/**
 * Get the full text content of the page body.
 *
 * Args:
 *     page: Puppeteer Page instance
 *
 * Returns:
 *     Full body text
 */
export async function getBodyText(page) {
    return page.evaluate(() => document.body.innerText);
}

/**
 * Count the number of elements matching a selector.
 *
 * Args:
 *     page: Puppeteer Page instance
 *     selector: CSS selector
 *
 * Returns:
 *     Number of matching elements
 */
export async function countElements(page, selector) {
    return page.$$eval(selector, els => els.length);
}

/**
 * Take a screenshot and save it to the screenshots directory.
 *
 * Args:
 *     page: Puppeteer Page instance
 *     name: Screenshot file name (without extension)
 */
export async function screenshot(page, name) {
    const path = resolve(SCREENSHOTS_DIR, `${name}.png`);
    await page.screenshot({ path, fullPage: true });
}

/**
 * Submit a form and wait for Inertia navigation.
 *
 * Args:
 *     page: Puppeteer Page instance
 *     buttonSelector: CSS selector for the submit button
 */
export async function submitForm(page, buttonSelector = 'button[type="submit"]') {
    await page.click(buttonSelector);
    await waitForInertia(page);
}

/**
 * Wait for a specific text to appear in the page body.
 *
 * Args:
 *     page: Puppeteer Page instance
 *     text: Text to search for
 *     timeout: Maximum wait time in ms
 *
 * Returns:
 *     true if found, false if timed out
 */
export async function waitForText(page, text, timeout = 5000) {
    try {
        await page.waitForFunction(
            (t) => document.body.innerText.includes(t),
            { timeout },
            text
        );
        return true;
    } catch {
        return false;
    }
}

/**
 * Get the current page path (pathname only, no origin).
 *
 * Args:
 *     page: Puppeteer Page instance
 *
 * Returns:
 *     The pathname of the current URL (e.g. '/login')
 */
export function getPath(page) {
    return new URL(page.url()).pathname;
}

/**
 * Fill an input by matching the text of its associated <label>.
 *
 * Uses the native value setter so Vue's reactivity picks up the change.
 * Falls back to typing into the element if the setter path fails.
 *
 * Args:
 *     page: Puppeteer Page instance
 *     labelText: Substring of the label text to match
 *     value: Value to set
 *
 * Returns:
 *     true if a matching field was filled, false otherwise
 */
export async function fillFieldByLabel(page, labelText, value) {
    const filled = await page.evaluate((label, val) => {
        const labels = document.querySelectorAll('label');
        for (const lbl of labels) {
            if (lbl.textContent.trim().includes(label)) {
                const container = lbl.closest('div');
                const input = container?.querySelector(
                    'input[type="text"], input[type="number"], input[type="email"], input[type="date"], input[type="time"], textarea'
                );
                if (input) {
                    const proto = input.tagName === 'TEXTAREA'
                        ? window.HTMLTextAreaElement.prototype
                        : window.HTMLInputElement.prototype;
                    const setter = Object.getOwnPropertyDescriptor(proto, 'value').set;
                    setter.call(input, val);
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                    return true;
                }
            }
        }
        return false;
    }, labelText, value);
    return filled;
}

/**
 * Click the first <button> (or <a>) whose visible text includes the given string.
 *
 * Args:
 *     page: Puppeteer Page instance
 *     text: Substring of the button/link text to match
 *
 * Returns:
 *     true if an element was found and clicked, false otherwise
 */
export async function clickByText(page, text) {
    const handle = await page.evaluateHandle((t) => {
        const els = [...document.querySelectorAll('button, a')];
        return els.find(el => el.textContent.trim().includes(t)) || null;
    }, text);
    const el = handle.asElement();
    if (!el) return false;
    await el.click();
    return true;
}

/**
 * Select an option in a native <select> located by its label text.
 *
 * Picks the first option whose text includes optionText. Dispatches change
 * + input so Vue reacts.
 *
 * Args:
 *     page: Puppeteer Page instance
 *     labelText: Substring of the label text identifying the select
 *     optionText: Substring of the option text to choose
 *
 * Returns:
 *     true if an option was selected, false otherwise
 */
export async function selectByLabel(page, labelText, optionText) {
    return page.evaluate((label, optText) => {
        const selects = document.querySelectorAll('select');
        for (const sel of selects) {
            const lbl = sel.closest('div')?.querySelector('label');
            if (lbl?.textContent.includes(label)) {
                for (const opt of sel.options) {
                    if (opt.textContent.includes(optText)) {
                        sel.value = opt.value;
                        sel.dispatchEvent(new Event('change', { bubbles: true }));
                        sel.dispatchEvent(new Event('input', { bubbles: true }));
                        return true;
                    }
                }
            }
        }
        return false;
    }, labelText, optionText);
}
