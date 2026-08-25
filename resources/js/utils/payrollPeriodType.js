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
    pink: {
        box: 'bg-pink-50 border-pink-200',
        title: 'text-pink-800',
        text: 'text-pink-700',
        dot: 'bg-pink-400',
        chip: 'bg-pink-100 text-pink-700',
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
    unified: {
        label: 'Semanal + Mes',
        short: 'Sueldo base + extras del mes',
        title: 'Nomina unificada — Sueldo base + extras del mes',
        description:
            'Un solo pago: el sueldo base de la semana y los extras del mes juntos en el mismo recibo.',
        pays: [
            'Sueldo base (dias trabajados) y descuento por faltas',
            'Horas extra y velada del mes',
            'Festivos y fin de semana',
            'Cena, comida y otros conceptos',
            'Vacaciones y bonos',
        ],
        tone: TONES.pink,
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

/**
 * Descriptor de un periodo concreto: una nomina semanal con rango de extras es
 * el pago UNIFICADO (semana + mes en un solo recibo).
 *
 * @param {{type?: string, extras_start_date?: string|null, extras_end_date?: string|null}} period
 * @returns {{label: string, short: string, title: string, description: string, pays: string[], tone: object}}
 */
export function periodInfo(period) {
    if (period?.extras_start_date && period?.extras_end_date) {
        return PERIOD_TYPE_INFO.unified;
    }

    return periodTypeInfo(period?.type);
}
