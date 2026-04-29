/**
 * Format hours mimicking the paper format: "0" when zero, drop trailing zeros otherwise.
 */
export const formatHours = (value) => {
    const v = Number(value) || 0;
    if (v <= 0) return '0';
    return String(parseFloat(v.toFixed(2)));
};

/**
 * Format an ISO date as "DD/MM/YYYY".
 */
export const formatDate = (iso) => {
    if (!iso) return '';
    const [y, m, d] = iso.split('-');
    return `${d}/${m}/${y}`;
};

/**
 * Spanish day-of-week label (LUNES, MARTES, ...).
 */
export const dayLabel = (iso) => {
    const map = ['DOMINGO', 'LUNES', 'MARTES', 'MIERCOLES', 'JUEVES', 'VIERNES', 'SABADO'];
    const d = new Date(iso + 'T00:00:00');
    return map[d.getDay()];
};
