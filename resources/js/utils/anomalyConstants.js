/**
 * Shared constants for the Anomalies module (Index, Show and the resolution
 * modal). Single source of truth for labels, colors, icons and type groups —
 * keys match the backend enums exactly (AttendanceAnomaly TYPE_* / SEVERITY_*
 * / STATUS_* / METHOD_* constants).
 */

export const severityColors = {
    critical: 'bg-red-100 text-red-800',
    warning: 'bg-yellow-100 text-yellow-800',
    info: 'bg-blue-100 text-blue-800',
};

export const severityBorderColors = {
    critical: 'bg-red-100 text-red-800 border-red-300',
    warning: 'bg-yellow-100 text-yellow-800 border-yellow-300',
    info: 'bg-blue-100 text-blue-800 border-blue-300',
};

export const severityLabels = {
    critical: 'Crítica',
    warning: 'Advertencia',
    info: 'Informativa',
};

export const statusColors = {
    open: 'bg-yellow-100 text-yellow-800',
    resolved: 'bg-green-100 text-green-800',
    dismissed: 'bg-gray-100 text-gray-800',
    linked_to_authorization: 'bg-blue-100 text-blue-800',
};

export const statusBorderColors = {
    open: 'bg-yellow-100 text-yellow-800 border-yellow-300',
    resolved: 'bg-green-100 text-green-800 border-green-300',
    dismissed: 'bg-gray-100 text-gray-800 border-gray-300',
    linked_to_authorization: 'bg-blue-100 text-blue-800 border-blue-300',
};

export const statusLabels = {
    open: 'Abierta',
    resolved: 'Resuelta',
    dismissed: 'Descartada',
    linked_to_authorization: 'Vinculada',
};

export const resolutionMethodLabels = {
    justified: 'Justificada',
    false_positive: 'Falso positivo',
    record_corrected: 'Checadas corregidas',
    linked_authorization: 'Autorización',
    linked_incident: 'Incidencia',
};

export const resolutionMethodColors = {
    justified: 'bg-green-100 text-green-800',
    false_positive: 'bg-gray-100 text-gray-700',
    record_corrected: 'bg-indigo-100 text-indigo-800',
    linked_authorization: 'bg-blue-100 text-blue-800',
    linked_incident: 'bg-teal-100 text-teal-800',
};

// SVG path per real anomaly_type enum value (heroicons outline).
export const typeIcons = {
    missing_checkout: 'M17 16l4-4m0 0l-4-4m4 4H3',
    missing_checkin: 'M11 16l-4-4m0 0l4-4m-4 4h14',
    unauthorized_overtime: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
    unauthorized_velada: 'M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z',
    velada_missing_confirmation: 'M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    excessive_break: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
    excessive_overtime: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
    missing_lunch: 'M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636',
    late_arrival: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
    early_departure: 'M17 16l4-4m0 0l-4-4m4 4H7',
    schedule_deviation: 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
    duplicate_punches: 'M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z',
};

export const fallbackTypeIcon = 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z';

export const typeLabels = {
    missing_checkout: 'Salida no registrada',
    missing_checkin: 'Entrada no registrada',
    unauthorized_overtime: 'Horas extra sin autorizar',
    unauthorized_velada: 'Velada sin autorizar',
    velada_missing_confirmation: 'Velada sin confirmación post-medianoche',
    excessive_break: 'Comida excesiva',
    missing_lunch: 'Sin checada de comida',
    late_arrival: 'Retardo significativo',
    early_departure: 'Salida anticipada',
    schedule_deviation: 'Desviación de horario',
    duplicate_punches: 'Checadas duplicadas',
    excessive_overtime: 'Horas extra excesivas',
};

// Groups for the type filter <optgroup>.
export const TYPE_GROUPS = [
    { label: 'Checadas incompletas', types: ['missing_checkout', 'missing_checkin', 'duplicate_punches'] },
    { label: 'Tiempo no autorizado', types: ['unauthorized_overtime', 'unauthorized_velada', 'velada_missing_confirmation'] },
    { label: 'Puntualidad', types: ['late_arrival', 'early_departure', 'schedule_deviation'] },
    { label: 'Comidas', types: ['excessive_break', 'missing_lunch'] },
];
