/**
 * Shared descriptions for the payroll period split.
 *
 * The nómina is split in two: a WEEKLY period pays the base salary minus
 * absences/lates, and a MONTHLY period pays the extras (overtime, velada,
 * holiday, weekend, cena/comida, vacations and bonuses). The legacy BIWEEKLY
 * type pays everything together.
 *
 * Tailwind tone classes are written out in full so they survive purging.
 */
const TONES = {
    indigo: {
        box: 'bg-indigo-50 border-indigo-200',
        title: 'text-indigo-800',
        text: 'text-indigo-700',
        dot: 'bg-indigo-400',
        chip: 'bg-indigo-100 text-indigo-700',
    },
    green: {
        box: 'bg-green-50 border-green-200',
        title: 'text-green-800',
        text: 'text-green-700',
        dot: 'bg-green-400',
        chip: 'bg-green-100 text-green-700',
    },
    gray: {
        box: 'bg-gray-50 border-gray-200',
        title: 'text-gray-800',
        text: 'text-gray-600',
        dot: 'bg-gray-400',
        chip: 'bg-gray-100 text-gray-600',
    },
};

const PERIOD_TYPE_INFO = {
    weekly: {
        label: 'Semanal',
        short: 'Paga el sueldo base',
        title: 'Nomina semanal — Sueldo base',
        description:
            'Paga el sueldo base de los dias trabajados y descuenta las faltas y los retardos. No incluye extras.',
        pays: ['Sueldo base (dias trabajados)', 'Descuento por faltas y retardos'],
        tone: TONES.indigo,
    },
    monthly: {
        label: 'Mensual',
        short: 'Paga los extras',
        title: 'Nomina mensual — Extras',
        description:
            'Paga los conceptos extra del mes y NO incluye el sueldo base ni descuentos por faltas.',
        pays: [
            'Horas extra y velada',
            'Festivos y fin de semana',
            'Cena, comida y otros conceptos',
            'Vacaciones',
            'Bonos (puntualidad, nocturno, etc.)',
        ],
        tone: TONES.green,
    },
    biweekly: {
        label: 'Quincenal',
        short: 'Paga todo junto (modo anterior)',
        title: 'Nomina quincenal — Todo junto (modo anterior)',
        description:
            'Modo anterior: paga el sueldo base y los extras juntos, menos las deducciones. Se conserva por compatibilidad.',
        pays: ['Sueldo base + extras', 'Menos deducciones'],
        tone: TONES.gray,
    },
};

/**
 * Get the descriptor for a payroll period type, falling back to biweekly.
 *
 * @param {string} type weekly | monthly | biweekly
 * @returns {{label: string, short: string, title: string, description: string, pays: string[], tone: object}}
 */
export function periodTypeInfo(type) {
    return PERIOD_TYPE_INFO[type] || PERIOD_TYPE_INFO.biweekly;
}
