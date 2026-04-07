/**
 * Date formatting utilities.
 *
 * These helpers work around the off-by-one-day bug that appears when a
 * date-only field (e.g. Authorization.date) is serialized by Laravel as
 * "2026-04-07T00:00:00.000000Z" (UTC midnight) and then rendered in the
 * browser with `new Date(value).toLocaleDateString('es-MX')`. In Mexico
 * City (UTC-6) that moment is April 6 at 18:00, so the display shows
 * the previous day.
 *
 * For pure-date fields, use `formatDate`. It parses the string with no
 * timezone conversion at all, so "2026-04-07" always displays as April 7.
 *
 * For datetime fields (e.g. created_at, approved_at), use `formatDateTime`.
 * It forces the es-MX locale to render in America/Mexico_City, regardless
 * of the browser's local timezone.
 */

const DEFAULT_TIME_ZONE = 'America/Mexico_City';

/**
 * Parse a date-only value as a local Date (no UTC conversion).
 *
 * Accepts both the clean format ("YYYY-MM-DD") and Laravel's legacy
 * datetime serialization ("YYYY-MM-DDTHH:mm:ss.uuuuuuZ") by stripping
 * anything after the "T". The Date is built with the 3-arg constructor,
 * which interprets its arguments in local time rather than UTC.
 *
 * Returns null for empty/invalid input so callers can render a placeholder.
 */
function parseLocalDate(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }
    const datePart = String(value).split('T')[0];
    const parts = datePart.split('-');
    if (parts.length !== 3) {
        return null;
    }
    const year = Number(parts[0]);
    const month = Number(parts[1]);
    const day = Number(parts[2]);
    if (!Number.isFinite(year) || !Number.isFinite(month) || !Number.isFinite(day)) {
        return null;
    }
    return new Date(year, month - 1, day);
}

/**
 * Format a pure date field for display in es-MX.
 *
 * Default format is dd/mm/yyyy. Pass Intl.DateTimeFormat options to
 * customize (e.g. { day: 'numeric', month: 'short', year: 'numeric' }).
 */
export function formatDate(value, options = {}) {
    const date = parseLocalDate(value);
    if (!date) {
        return '-';
    }
    return date.toLocaleDateString('es-MX', options);
}

/**
 * Format a datetime field for display in Mexico City time.
 *
 * Always forces timeZone: 'America/Mexico_City' so the rendered time is
 * independent of the browser's local timezone. Accepts any Date-parseable
 * value Laravel might emit.
 */
export function formatDateTime(value, options = {}) {
    if (value === null || value === undefined || value === '') {
        return '-';
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '-';
    }
    return date.toLocaleString('es-MX', {
        timeZone: DEFAULT_TIME_ZONE,
        ...options,
    });
}

/**
 * Return today's date as a "YYYY-MM-DD" string in the browser's local
 * timezone. Used to initialize date and datetime-local input fields.
 *
 * Naive `new Date().toISOString().split('T')[0]` returns the UTC date,
 * which in Mexico (UTC-6) jumps to tomorrow any time after 18:00 local.
 * This helper reads the local getFullYear/getMonth/getDate values so the
 * returned date always matches what the user considers "today".
 */
export function todayLocal() {
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}
