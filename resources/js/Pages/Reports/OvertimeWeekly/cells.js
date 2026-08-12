/** Shared helpers for building approved/pending hour totals from a day cell. */

/** Hours that were authorized AND approved for a given day. */
export const cellApproved = (day) =>
    // SOLO tiempo extra (decisión de Luis 2026-08-12): la velada no se mezcla
    // en la celda — tiene su propia columna. Celda = lo que aprobó el encargado.
    (day?.overtime_hours || 0);

/** Hours that were detected from real punches but not yet approved. */
export const cellPending = (day) => day?.pending_overtime_hours || 0;
