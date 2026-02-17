/**
 * E2E Test Helpers.
 *
 * Provides reusable Puppeteer utilities for login, navigation,
 * form interaction, and screenshots.
 */

import puppeteer from 'puppeteer';
import { mkdirSync, existsSync, writeFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const SCREENSHOTS_DIR = resolve(__dirname, 'screenshots');
const BASE_URL = process.env.E2E_BASE_URL || 'http://127.0.0.1:8787';

// Ensure screenshots directory exists
if (!existsSync(SCREENSHOTS_DIR)) {
    mkdirSync(SCREENSHOTS_DIR, { recursive: true });
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
        args: ['--no-sandbox', '--disable-setuid-sandbox'],
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
    await page.goto(`${BASE_URL}/login`, { waitUntil: 'networkidle0', timeout: 15000 });

    // Debug: dump page HTML to file
    const html = await page.content();
    writeFileSync(resolve(SCREENSHOTS_DIR, '_debug-page.html'), html);

    await screenshot(page, '_debug-login-page');
    await fillField(page, '#email', email);
    await fillField(page, '#password', password);
    await page.click('button[type="submit"]');
    await waitForInertia(page);
}

/**
 * Navigate to a route and wait for the page to settle.
 *
 * Args:
 *     page: Puppeteer Page instance
 *     path: URL path (e.g., '/employees')
 */
export async function goto(page, path) {
    await page.goto(`${BASE_URL}${path}`, { waitUntil: 'networkidle0' });
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
export async function waitForInertia(page, timeout = 10000) {
    await page.waitForNavigation({ waitUntil: 'networkidle0', timeout }).catch(() => {});
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
    const el = await page.waitForSelector(selector, { timeout: 5000 });
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
    await page.waitForSelector(selector, { timeout: 5000 });
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
    await page.waitForSelector(selector, { timeout: 5000 });
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
    await page.waitForSelector(selector, { timeout: 5000 });
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
        await page.waitForSelector(selector, { timeout: 5000 });
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
