/** Shared helpers for building approved/pending hour totals from a day cell. */

/** Hours that were authorized AND approved for a given day. */
export const cellApproved = (day) =>
    (day?.overtime_hours || 0) + (day?.velada_hours || 0);

/** Hours that were detected from real punches but not yet approved. */
export const cellPending = (day) => day?.pending_overtime_hours || 0;
