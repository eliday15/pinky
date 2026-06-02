/**
 * Company rounding ladder for authorizable overtime hours.
 * Mirrors App\Services\OvertimeRoundingService::roundMinutes.
 *
 *   <30 min   -> 0   (not OT)
 *   30-49 min -> 0.5h
 *   50-59 min -> 1h
 *   ... then repeats every hour: hh:00-29 -> hh, hh:30-49 -> hh+0.5, hh:50-59 -> hh+1
 */
export function roundOvertimeMinutes(minutes) {
    const m = Math.max(0, Math.floor(minutes || 0));
    if (m < 30) return 0;
    const h = Math.floor(m / 60);
    const rem = m % 60;
    if (rem < 30) return h;
    if (rem < 50) return h + 0.5;
    return h + 1;
}

/**
 * Total minutes between two HH:MM strings. Assumes both on the same day;
 * if end < start (would be negative), returns 0 instead of wrapping.
 */
export function diffMinutes(startHHMM, endHHMM) {
    if (!startHHMM || !endHHMM) return 0;
    const [sh, sm] = startHHMM.split(':').map(Number);
    const [eh, em] = endHHMM.split(':').map(Number);
    if ([sh, sm, eh, em].some(Number.isNaN)) return 0;
    return Math.max(0, (eh * 60 + em) - (sh * 60 + sm));
}

/**
 * Total minutes between two full datetimes (date + HH:MM each), so a range
 * that crosses midnight (e.g. a velada 22:00 -> next-day 06:00) is measured
 * correctly. Negative spans (end before start) clamp to 0.
 */
export function minutesBetweenDates(startDate, startHHMM, endDate, endHHMM) {
    if (!startDate || !startHHMM || !endDate || !endHHMM) return 0;
    const start = new Date(`${startDate}T${startHHMM}`);
    const end = new Date(`${endDate}T${endHHMM}`);
    const diff = (end - start) / 60000;
    return Number.isFinite(diff) ? Math.max(0, diff) : 0;
}

/**
 * Convenience: hours formatted to two decimals as a string, matching
 * the backend's number_format($v, 2, '.', '') output.
 */
export function formatRoundedHours(minutes) {
    return roundOvertimeMinutes(minutes).toFixed(2);
}
