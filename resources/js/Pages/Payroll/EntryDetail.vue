<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { formatDate as fmtDate } from '@/utils/date';
import { periodTypeInfo } from '@/utils/payrollPeriodType';

const props = defineProps({
    entry: Object,
    cashSplit: { type: Object, default: () => ({}) },
    canal: { type: String, default: null },
    // CFDI timbrado del entry: { uuid, stamped_at, has_xml, has_pdf } | null.
    cfdi: { type: Object, default: null },
});

// Modo de vista: al llegar desde "solo efectivo" / "solo transferencia" se
// muestra únicamente ese canal; sin canal, el detalle completo.
const viewingCash = computed(() => props.canal === 'efectivo');
const viewingTransfer = computed(() => props.canal === 'transfer');
const fullView = computed(() => !viewingCash.value && !viewingTransfer.value);

const typeInfo = computed(() => {
    const scope = props.entry.calculation_breakdown?.scope ?? {};
    // Pago unificado: el recibo trae la semana y los extras del mes juntos.
    if (scope.unified) {
        return periodTypeInfo('unified');
    }

    return periodTypeInfo(scope.period_type || props.entry.payroll_period?.type);
});

const formatDate = (date) => fmtDate(date, {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
});

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN',
    }).format(amount || 0);
};

const breakdown = props.entry.calculation_breakdown || {};

// Formatea una cantidad sin ceros de más: 600.00→"600", 3.50→"3.5".
const num = (n) => Number(n || 0).toFixed(2).replace(/\.?0+$/, '');

// Mismo ruteo que el backend (PayrollCalculatorService): un concepto es "Otros
// conceptos" (cena, comida, dominical, recurrentes, desayunos, etc.) cuando NO
// cae en horas extra / velada / festivo / fin de semana. Los recurrentes SIEMPRE
// son "otros" (el backend no los rutea por tipo). Así lo itemizamos, igual que el
// modal de cobro, en lugar del renglón agrupado.
const isOtroConcepto = (c) => {
    if (c.source === 'recurring') return true;
    const code = c.code || '';
    const authType = c.authorization_type ?? null;
    const pull = c.attendance_pull_rule ?? null;
    if (['HE', 'HED', 'HET'].includes(code)) return false;          // horas extra
    if (code === 'VEL' || authType === 'night_shift') return false; // velada
    if (authType === 'holiday_worked') return false;                // festivo
    if (pull === 'weekend') return false;                           // fin de semana
    return true;
};

// Solo los positivos: su suma es exactamente entry.other_compensation_pay (un
// recurrente negativo es una deducción, no parte de "Otros conceptos").
const otrosConceptos = computed(() =>
    (breakdown.compensation_concepts ?? []).filter((c) => isOtroConcepto(c) && Number(c.amount) > 0)
);

// Detalle "cuántos hubo" — mismo formato que el modal de cobro.
const conceptDetail = (c) => {
    const qty = Number(c.quantity || 0);
    const hours = Number(c.hours || 0);
    const days = Number(c.days || 0);
    const fixed = Number(c.rate?.fixed_amount || 0);
    if (qty > 1) {
        const unit = fixed > 0 ? fixed : (qty !== 0 ? Number(c.amount) / qty : 0);
        return `${num(qty)} × ${formatCurrency(unit)}`;
    }
    if (hours > 0) return `${num(hours)} h`;
    if (days > 0) return `${num(days)} día(s)`;
    return '';
};

// --- Reparto por canal de pago (dos tablas) ---
// El sueldo base (y sus deducciones por falta) van por TRANSFERENCIA para quien
// cobra base en banco (IMSS); para quien cobra base en efectivo TODO va en
// efectivo. Los extras (horas extra, festivo, velada, finde, otros conceptos,
// vacaciones, bonos) siempre van en efectivo. Coincide con el reparto del
// backend: transferLines suma bank_amount y efectivoLines suma cash_amount.
const money = (n) => Number(n || 0);
const paysBaseInCash = computed(() => !!props.cashSplit?.pays_base_in_cash);
// Sueldo base: "N días × $sueldo diario" para que se entienda de dónde sale.
const baseDetail = computed(() => {
    const days = breakdown.base?.base_paid_days ?? 0;
    const daily = Number(breakdown.rates?.daily_salary ?? props.entry.daily_salary ?? 0);
    let detail = daily > 0 ? `${num(days)} días × ${formatCurrency(daily)}` : `${num(days)} días`;

    // Semana recortada por alta o baja: la razón más común de un sueldo base
    // "corto", y antes había que adivinarla.
    if (breakdown.base?.starts_on) {
        detail += ` — entró el ${formatShortDay(breakdown.base.starts_on)}`;
    }
    if (breakdown.base?.ends_on) {
        detail += ` — baja el ${formatShortDay(breakdown.base.ends_on)}`;
    }

    return detail;
});

// --- Detalle de la deducción por falta (qué días y por qué) ---
// Cada falta descuenta el sueldo diario × factor del séptimo día (7/6). Se toma
// el detalle exacto del breakdown (fechas) si existe; si es un asiento viejo sin
// ese detalle, se arma por categoría desde los conteos que sí trae el breakdown.
const perDayDeduction = computed(() =>
    Number(breakdown.rates?.daily_salary ?? props.entry.daily_salary ?? 0)
    * Number(breakdown.rates?.rest_day_factor ?? (7 / 6))
);

const formatDayLabel = (date) => fmtDate(date, { weekday: 'short', day: 'numeric', month: 'short' });
const formatShortDay = (date) => fmtDate(date, { day: 'numeric', month: 'short' });

// '2026-06' -> 'junio 2026'
const monthName = (ym) => {
    const [y, m] = String(ym ?? '').split('-').map(Number);
    if (!y || !m) return ym || 'el mes';
    return new Date(Date.UTC(y, m - 1, 1)).toLocaleDateString('es-MX', { month: 'long', year: 'numeric' });
};

// Detalle legible de una falta por acumulación de retardos: qué mes y cuáles
// retardos (fechas) la originaron.
const lateAccumulationText = (lateDetail) => {
    if (!Array.isArray(lateDetail) || !lateDetail.length) return '';
    return lateDetail
        .map((f) => {
            const dates = (f.late_dates || []).map((dt) => formatShortDay(dt)).join(', ');
            const count = f.late_dates?.length ?? f.days ?? 0;
            return `${monthName(f.month)}: ${count} retardos${dates ? ` (${dates})` : ''}`;
        })
        .join('; ');
};

const deductionDetail = computed(() => {
    const per = perDayDeduction.value;
    const detailed = breakdown.deduction_detail;
    if (Array.isArray(detailed) && detailed.length) {
        return detailed.map((d) => {
            let label = d.reason + (d.date ? ` — ${formatDayLabel(d.date)}` : '') + (d.days > 1 ? ` (${d.days} días)` : '');
            const lateText = lateAccumulationText(d.late_detail);
            if (lateText) label += ` — ${lateText}`;
            return { label, amount: -(Number(d.days || 0) * per) };
        });
    }
    // Fallback por categoría (asiento sin detalle de fechas): usa los conteos.
    const inc = Number(breakdown.incidents?.absence_incident_deduction_days ?? 0);
    const frt = Number(breakdown.late_accumulation?.late_absences_generated ?? 0);
    const total = Number(breakdown.incidents?.absence_deduction_days ?? 0);
    const unjust = Math.max(0, total - inc - frt);
    const rows = [];
    if (unjust > 0) rows.push({ label: `Faltas injustificadas (${unjust})`, amount: -(unjust * per) });
    if (frt > 0) rows.push({ label: `Faltas por acumulación de retardos (${frt})`, amount: -(frt * per) });
    if (inc > 0) rows.push({ label: `Faltas por incidencia (${inc})`, amount: -(inc * per) });
    return rows;
});

// Detalle de la incapacidad: días pagados × sueldo diario (el resto de los días
// de incapacidad no se pagan, los cubre el IMSS).
const sickLeaveDetail = computed(() => {
    const days = Number(breakdown.incidents?.sick_leave_paid_days ?? props.entry.sick_leave_days ?? 0);
    const daily = Number(breakdown.rates?.daily_salary ?? props.entry.daily_salary ?? 0);
    if (!days) return '';
    return daily > 0 ? `${num(days)} día(s) × ${formatCurrency(daily)}` : `${num(days)} día(s)`;
});

const vacationDetail = computed(() => {
    const days = Number(props.entry.vacation_days_paid ?? 0);
    return days > 0 ? `${num(days)} día(s)` : '';
});

// Qué hay DENTRO de "Bonos" (el campo bonuses es la suma de todos). Se desglosa
// para que el renglón se explique solo; el sobrante sin identificar se muestra
// como "Otros bonos" en vez de esconderse.
const bonusLines = computed(() => {
    const b = breakdown.bonuses ?? {};
    const ns = breakdown.night_shifts ?? {};
    const rows = [];
    const punctuality = money(b.punctuality ?? props.entry.punctuality_bonus);
    const punctualDays = Number(props.entry.punctuality_days ?? breakdown.attendance?.punctual_days ?? 0);
    if (punctuality > 0) {
        rows.push({ label: 'Puntualidad', detail: punctualDays > 0 ? `${num(punctualDays)} día(s)` : '', amount: punctuality });
    }
    if (money(b.weekly ?? props.entry.weekly_bonus) > 0) {
        rows.push({ label: 'Bono semanal (asistencia perfecta)', detail: '', amount: money(b.weekly ?? props.entry.weekly_bonus) });
    }
    if (money(b.monthly ?? props.entry.monthly_bonus) > 0) {
        rows.push({ label: 'Bono mensual (asistencia perfecta)', detail: '', amount: money(b.monthly ?? props.entry.monthly_bonus) });
    }
    if (money(ns.bonus ?? props.entry.night_shift_bonus) > 0) {
        rows.push({ label: 'Bono nocturno', detail: '', amount: money(ns.bonus ?? props.entry.night_shift_bonus) });
    }
    if (money(ns.dinner_allowance ?? props.entry.dinner_allowance) > 0) {
        rows.push({ label: 'Cena', detail: '', amount: money(ns.dinner_allowance ?? props.entry.dinner_allowance) });
    }
    const shown = rows.reduce((s, r) => s + r.amount, 0);
    const rest = money(props.entry.bonuses) - shown;
    if (Math.abs(rest) >= 0.005) rows.push({ label: 'Otros bonos', detail: '', amount: rest });
    return rows;
});

const transferLines = computed(() => {
    if (paysBaseInCash.value) return [];
    const lines = [];
    if (money(props.entry.regular_pay) !== 0) {
        lines.push({ label: 'Sueldo base', detail: baseDetail.value, amount: money(props.entry.regular_pay) });
    }
    // Prima vacacional del formalizado: Contpaq la paga en la semana por
    // transferencia, junto con el sueldo (no en la mensual/efectivo).
    if (money(props.entry.vacation_premium_pay) > 0) {
        lines.push({ label: 'Prima vacacional', detail: '', amount: money(props.entry.vacation_premium_pay) });
    }
    // Percepciones marcadas via_transfer (cumpleaños, aguinaldo, etc.): Contpaq
    // las paga por transferencia junto con el sueldo. Se muestran aquí, no en el
    // efectivo (allá se saltan por el mismo flag).
    for (const c of (breakdown.compensation_concepts ?? [])) {
        if (c.via_transfer && money(c.amount) > 0) {
            lines.push({ label: c.name, detail: conceptDetail(c), amount: money(c.amount) });
        }
    }
    if (money(props.entry.deductions) > 0) {
        // isDeduction dispara el detalle de faltas — solo aquí, no bajo ISR/IMSS.
        lines.push({ label: 'Deducciones (faltas)', detail: '', amount: -money(props.entry.deductions), isDeduction: true });
    }
    // Retenciones fiscales del trabajador (formalizado): reducen la transferencia,
    // igual que en la Lista de Raya de Contpaq (ISR, IMSS, Infonavit). Se pintan
    // en rojo por amount<0; NO llevan isDeduction (no repiten el detalle de faltas).
    if (money(props.entry.isr_amount) > 0) {
        lines.push({ label: 'ISR (retención)', detail: '', amount: -money(props.entry.isr_amount) });
    }
    if (money(props.entry.imss_amount) > 0) {
        lines.push({ label: 'IMSS (retención)', detail: '', amount: -money(props.entry.imss_amount) });
    }
    if (money(props.entry.infonavit_amount) > 0) {
        lines.push({ label: 'Infonavit (crédito)', detail: '', amount: -money(props.entry.infonavit_amount) });
    }
    if (money(props.entry.subsidy_amount) > 0) {
        lines.push({ label: 'Subsidio al empleo', detail: 'se acredita a favor', amount: money(props.entry.subsidy_amount) });
    }
    // Ajuste al neto (concepto 99 de Contpaq): centavos que redondean la
    // transferencia a múltiplos de $0.20 — sin esta línea las filas no
    // sumarían el total del banco.
    if (money(props.entry.net_adjustment) !== 0) {
        lines.push({ label: 'Ajuste al neto', detail: 'redondeo a $0.20', amount: money(props.entry.net_adjustment) });
    }
    return lines;
});

const efectivoLines = computed(() => {
    const lines = [];
    if (paysBaseInCash.value && money(props.entry.regular_pay) !== 0) {
        lines.push({ label: 'Sueldo base', detail: baseDetail.value, amount: money(props.entry.regular_pay) });
    }
    if (money(props.entry.overtime_pay) > 0) {
        lines.push({
            label: 'Horas extra',
            detail: num(props.entry.overtime_authorized_hours) > 0 ? `${num(props.entry.overtime_authorized_hours)} h pagadas` : '',
            amount: money(props.entry.overtime_pay),
            overtimeNote: true,
        });
    }
    if (money(props.entry.holiday_pay) > 0) lines.push({ label: 'Días festivos', detail: '', amount: money(props.entry.holiday_pay) });
    if (money(props.entry.velada_pay) > 0) lines.push({ label: 'Velada', detail: '', amount: money(props.entry.velada_pay) });
    if (money(props.entry.weekend_pay) > 0) {
        lines.push({
            label: 'Fin de semana',
            detail: weekendUnits.value > 0 ? `${weekendUnits.value} fin(es) de semana` : '',
            amount: money(props.entry.weekend_pay),
            weekendNote: true,
        });
    }
    for (const c of otrosConceptos.value) {
        // Las percepciones por transferencia (cumpleaños, aguinaldo) van en la
        // transferencia del formalizado, no aquí.
        if (c.via_transfer) continue;
        lines.push({ label: c.name, detail: conceptDetail(c), amount: money(c.amount) });
    }
    if (money(props.entry.vacation_pay) > 0) lines.push({ label: 'Vacaciones', detail: vacationDetail.value, amount: money(props.entry.vacation_pay) });
    // Prima vacacional de los empleados de EFECTIVO: se paga en la mensual (los
    // formalizados la cobran por transferencia, ver transferLines).
    if (paysBaseInCash.value && money(props.entry.vacation_premium_pay) > 0) lines.push({ label: 'Prima vacacional', detail: '', amount: money(props.entry.vacation_premium_pay) });
    // Incapacidad con goce: días pagados × sueldo diario. Sin este renglón su
    // importe caía en el residual y se leía como "redondeo" (caso Guadalupe,
    // 26 días de incapacidad = $7,248.80 sin explicar).
    if (money(props.entry.sick_leave_pay) > 0) {
        lines.push({ label: 'Incapacidad', detail: sickLeaveDetail.value, amount: money(props.entry.sick_leave_pay) });
    }
    if (money(props.entry.bonuses) > 0) {
        lines.push({ label: 'Bonos', detail: '', amount: money(props.entry.bonuses), children: bonusLines.value });
    }
    if (paysBaseInCash.value && money(props.entry.deductions) > 0) {
        lines.push({ label: 'Deducciones (faltas)', detail: '', amount: -money(props.entry.deductions), isDeduction: true });
    }
    return lines;
});

// Suma exacta de los renglones de efectivo (= entry.cash_amount). El cobro se
// redondea al peso (period_amount), por eso el redondeo se muestra aparte.
// Conceptos "es sueldo" que NO se pagaron porque el recibo ya trae el sueldo
// base (personal en periodo de prueba). Se listan para que se vea por qué.
const suppressedSalaryConcepts = computed(() => breakdown.suppressed_base_salary_concepts ?? []);

// Capturado y aprobado, pero pagó $0 porque el concepto no tiene monto: se
// muestra para que no se pierda en silencio (caso Descuento Infonavit).
const unpaidZeroAmountConcepts = computed(() => breakdown.unpaid_zero_amount_concepts ?? []);

// Prima vacacional automática que NO se sumó porque hay una capturada a mano
// (aprobada, en $0 o rechazada) en el rango — se explica para que no parezca
// que "sigue apareciendo" (Luis 2026-08-28).
const suppressedVacationPremium = computed(() => breakdown.suppressed_vacation_premium ?? null);

// Fines de semana AUTORIZADOS que no contaron porque el día no llegó al mínimo
// de horas corridas. Se explican debajo del renglón de Fin de semana para que
// no quede la duda de "tenía dos aprobados y solo aparece uno".
const weekendNotCounted = computed(() => breakdown.weekend?.not_counted ?? []);
const weekendUnits = computed(() => Number(breakdown.weekend?.units ?? 0));

// Días con tiempo extra APROBADO que no se paga completo, con el motivo: la
// ventana encimada se paga una sola vez, y lo que la checada no respalda no se
// paga. Se explica debajo del renglón de Horas extra.
const overtimeShortfalls = computed(() => breakdown.overtime?.shortfalls ?? []);

const efectivoSubtotal = computed(() => efectivoLines.value.reduce((s, l) => s + l.amount, 0));
const pesoRounding = computed(() => Number(props.cashSplit?.period_amount ?? 0) - efectivoSubtotal.value);
// El cobro se redondea al peso, así que el residual normal son centavos. Un
// residual de pesos significa que a los renglones les falta un concepto: se
// nombra "Sin desglosar" y se pinta en ámbar en vez de llamarle "redondeo".
const roundingIsCents = computed(() => Math.abs(pesoRounding.value) < 1);
</script>

<template>
    <Head :title="`Nomina: ${entry.employee?.full_name}`" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Detalle de Nomina Individual
            </h2>
        </template>

        <div class="mb-6 flex items-center justify-between flex-wrap gap-3">
            <Link
                :href="route('payroll.show', entry.payroll_period_id)"
                class="text-pink-600 hover:text-pink-800"
            >
                &larr; Volver al periodo
            </Link>

            <!-- Recibo CFDI timbrado ante el SAT: folio fiscal + descargas -->
            <div v-if="cfdi" class="flex items-center gap-2 text-sm bg-teal-50 border border-teal-200 rounded-lg px-3 py-1.5">
                <span class="text-teal-700 font-medium">CFDI timbrado</span>
                <span class="text-gray-500 font-mono text-xs" :title="`Timbrado: ${cfdi.stamped_at}`">{{ cfdi.uuid }}</span>
                <a
                    v-if="cfdi.has_xml"
                    :href="route('payroll.cfdi.xml', entry.id)"
                    class="text-teal-700 underline hover:text-teal-900"
                >XML</a>
                <a
                    v-if="cfdi.has_pdf"
                    :href="route('payroll.cfdi.pdf', entry.id)"
                    class="text-teal-700 underline hover:text-teal-900"
                >PDF</a>
            </div>
        </div>

        <!-- Banner de canal: se está viendo solo un canal de pago -->
        <div
            v-if="!fullView"
            class="mb-6 rounded-lg border p-3 flex items-center justify-between gap-3 flex-wrap"
            :class="viewingCash ? 'border-pink-300 bg-pink-50' : 'border-indigo-300 bg-indigo-50'"
        >
            <p class="text-sm font-medium" :class="viewingCash ? 'text-pink-700' : 'text-indigo-700'">
                Mostrando solo lo que se paga en {{ viewingCash ? 'efectivo' : 'transferencia' }}.
            </p>
            <Link
                :href="route('payroll.entry', entry.id)"
                class="text-sm font-medium underline"
                :class="viewingCash ? 'text-pink-700' : 'text-indigo-700'"
            >
                Ver detalle completo
            </Link>
        </div>

        <!-- Employee Header -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <div class="flex items-center">
                <div class="w-16 h-16 rounded-full bg-pink-100 flex items-center justify-center">
                    <span class="text-2xl text-pink-600 font-bold">
                        {{ entry.employee?.full_name?.charAt(0) || '?' }}
                    </span>
                </div>
                <div class="ml-4">
                    <h1 class="text-2xl font-bold text-gray-800">{{ entry.employee?.full_name }}</h1>
                    <p class="text-gray-500">{{ entry.employee?.employee_number }}</p>
                    <p class="text-sm text-gray-500">
                        {{ entry.employee?.department?.name }} | {{ entry.employee?.position?.name }}
                    </p>
                </div>
                <div class="ml-auto text-right">
                    <p class="text-3xl font-bold text-green-600">{{ formatCurrency(entry.net_pay) }}</p>
                    <p class="text-sm text-gray-500">Pago Neto</p>
                </div>
            </div>
        </div>

        <!-- Period Info -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Periodo</h3>
            <div class="grid grid-cols-3 gap-4 text-sm">
                <div>
                    <span class="text-gray-500">Periodo:</span>
                    <p class="font-medium">{{ entry.payroll_period?.name }}</p>
                </div>
                <div>
                    <span class="text-gray-500">Fechas:</span>
                    <p class="font-medium">
                        {{ formatDate(entry.payroll_period?.start_date) }} - {{ formatDate(entry.payroll_period?.end_date) }}
                    </p>
                </div>
                <div>
                    <span class="text-gray-500">Horario:</span>
                    <p class="font-medium">{{ entry.employee?.schedule?.name || 'N/A' }}</p>
                </div>
            </div>
        </div>

        <!-- What this period pays -->
        <div v-if="fullView" class="border rounded-lg p-4 mb-6" :class="typeInfo.tone.box">
            <div class="flex flex-wrap items-center gap-2">
                <span class="px-2 py-0.5 text-xs font-medium rounded-full" :class="typeInfo.tone.chip">{{ typeInfo.label }}</span>
                <p class="text-sm font-semibold" :class="typeInfo.tone.title">{{ typeInfo.title }}</p>
            </div>
            <p class="mt-1 text-sm" :class="typeInfo.tone.text">{{ typeInfo.description }}</p>
        </div>

        <div v-if="fullView" class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Hours Breakdown -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Desglose de Horas</h3>
                <div class="space-y-3">
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Horas regulares</span>
                        <span class="font-medium">{{ entry.regular_hours }}h</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Horas extra</span>
                        <span class="font-medium text-green-600">{{ entry.overtime_hours }}h</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Horas en dias festivos</span>
                        <span class="font-medium text-blue-600">{{ entry.holiday_hours }}h</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Horas en fin de semana</span>
                        <span class="font-medium text-purple-600">{{ entry.weekend_hours }}h</span>
                    </div>
                    <div class="border-t pt-3 flex justify-between items-center">
                        <span class="text-gray-800 font-medium">Total de horas</span>
                        <span class="font-bold text-gray-800">
                            {{ (parseFloat(entry.regular_hours) + parseFloat(entry.overtime_hours) + parseFloat(entry.holiday_hours) + parseFloat(entry.weekend_hours)).toFixed(2) }}h
                        </span>
                    </div>
                </div>
            </div>

            <!-- Days Breakdown -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Desglose de Dias</h3>
                <div class="space-y-3">
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Dias trabajados</span>
                        <span class="font-medium text-green-600">{{ entry.days_worked }}</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Dias con retardo</span>
                        <span class="font-medium text-yellow-600">{{ entry.days_late }}</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Dias ausente</span>
                        <span class="font-medium text-red-600">{{ entry.days_absent }}</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Dias de vacaciones pagados</span>
                        <span class="font-medium text-purple-600">{{ entry.vacation_days_paid }}</span>
                    </div>
                    <div v-if="entry.velada_days > 0" class="flex justify-between items-center">
                        <span class="text-gray-600">Veladas</span>
                        <span class="font-medium text-indigo-600">{{ entry.velada_days }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Rates Applied -->
        <div v-if="fullView" class="bg-white rounded-lg shadow p-6 mt-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Tasas Aplicadas</h3>
            <div class="grid grid-cols-3 gap-4 text-sm">
                <div class="bg-gray-50 rounded-lg p-4 text-center">
                    <p class="text-2xl font-bold text-gray-800">{{ formatCurrency(entry.daily_salary) }}</p>
                    <p class="text-gray-500">Sueldo diario</p>
                </div>
                <template v-if="!breakdown.rates?.uses_compensation_types">
                    <div class="bg-gray-50 rounded-lg p-4 text-center">
                        <p class="text-2xl font-bold text-green-600">{{ entry.overtime_multiplier }}x</p>
                        <p class="text-gray-500">Multiplicador horas extra</p>
                    </div>
                    <div class="bg-gray-50 rounded-lg p-4 text-center">
                        <p class="text-2xl font-bold text-blue-600">{{ entry.holiday_multiplier }}x</p>
                        <p class="text-gray-500">Multiplicador dias festivos</p>
                    </div>
                </template>
                <div v-else class="col-span-2 bg-green-50 rounded-lg p-4 text-center">
                    <p class="text-sm font-medium text-green-700">Tarifas calculadas por conceptos de compensacion</p>
                </div>
            </div>
        </div>

        <!-- Compensation Concepts Breakdown (when using comp types) -->
        <div v-if="fullView && breakdown.compensation_concepts?.length" class="bg-white rounded-lg shadow p-6 mt-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Conceptos de Compensacion</h3>
            <div class="space-y-3">
                <div
                    v-for="(concept, idx) in breakdown.compensation_concepts"
                    :key="idx"
                    class="flex justify-between items-center py-2 border-b"
                >
                    <div>
                        <span class="text-gray-600">{{ concept.name }}</span>
                        <span class="ml-2 px-2 py-0.5 bg-gray-100 rounded text-xs text-gray-500">{{ concept.code }}</span>
                        <span v-if="concept.hours > 0" class="text-xs text-gray-400 ml-2">
                            ({{ concept.hours }}h)
                        </span>
                        <span v-if="concept.days > 0" class="text-xs text-gray-400 ml-2">
                            ({{ concept.days }}d)
                        </span>
                        <span v-if="concept.quantity > 1" class="text-xs text-gray-400 ml-2">
                            ({{ concept.quantity }} x {{ formatCurrency(concept.rate?.fixed_amount ?? 0) }})
                        </span>
                    </div>
                    <span class="font-medium text-green-600">{{ formatCurrency(concept.amount) }}</span>
                </div>
            </div>
        </div>

        <!-- Cómo se paga: el desglose de pago va SIEMPRE dividido por canal en las
             dos tablas de abajo (transferencia / efectivo), para no mezclar lo que
             va al banco con lo que se cobra en efectivo. -->
        <div v-if="fullView" class="mt-8 mb-2">
            <h3 class="text-lg font-semibold text-gray-800">Cómo se paga</h3>
            <p class="text-sm text-gray-500">
                Bruto {{ formatCurrency(entry.gross_pay) }} · Neto
                <span class="font-medium text-green-600">{{ formatCurrency(entry.net_pay) }}</span>.
                Se reparte en dos: lo que va por transferencia y lo que se paga en efectivo.
            </p>
        </div>

        <!-- Reparto del pago en DOS TABLAS: qué va por transferencia (banco) y qué
             en efectivo. El efectivo separa lo de este periodo del acumulado que
             se recorrió sin cobrar de semanas anteriores. -->
        <div class="grid grid-cols-1 gap-6" :class="{ 'lg:grid-cols-2': fullView }">
            <!-- Tabla: Transferencia (banco) -->
            <div v-if="fullView || viewingTransfer" class="bg-white rounded-lg shadow overflow-hidden border-l-4 border-indigo-500">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h3 class="text-lg font-semibold text-gray-800">Transferencia</h3>
                    <p class="text-xs text-gray-400">Sueldo base por banco / CONTPAQi</p>
                </div>
                <div class="p-6">
                    <table v-if="transferLines.length" class="min-w-full text-sm">
                        <tbody>
                            <template v-for="(l, i) in transferLines" :key="i">
                                <tr class="border-b last:border-0">
                                    <td class="py-2">
                                        <span class="text-gray-600">{{ l.label }}</span>
                                        <span v-if="l.detail" class="text-xs text-gray-400 ml-2">({{ l.detail }})</span>
                                    </td>
                                    <td class="py-2 text-right font-medium" :class="l.amount < 0 ? 'text-red-600' : 'text-gray-800'">
                                        {{ l.amount < 0 ? '-' : '' }}{{ formatCurrency(Math.abs(l.amount)) }}
                                    </td>
                                </tr>
                                <!-- Qué hay dentro del renglón (ej. los bonos) -->
                                <tr v-for="(c, ci) in (l.children || [])" :key="`c-${i}-${ci}`" class="text-xs text-gray-500">
                                    <td class="py-1 pl-6">
                                        {{ c.label }}
                                        <span v-if="c.detail" class="text-gray-400 ml-1">({{ c.detail }})</span>
                                    </td>
                                    <td class="py-1 text-right">{{ formatCurrency(c.amount) }}</td>
                                </tr>
                                <!-- Detalle de la falta: qué días y por qué se descuenta -->
                                <template v-if="l.isDeduction">
                                    <tr v-for="(d, di) in deductionDetail" :key="`d-${i}-${di}`" class="text-xs text-red-500/80">
                                        <td class="py-1 pl-6">{{ d.label }}</td>
                                        <td class="py-1 text-right">-{{ formatCurrency(Math.abs(d.amount)) }}</td>
                                    </tr>
                                </template>
                            </template>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-gray-200">
                                <td class="py-3 font-semibold text-gray-800">Total a transferir</td>
                                <td class="py-3 text-right font-bold text-indigo-600 text-lg">{{ formatCurrency(cashSplit.bank_amount) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                    <p v-else class="text-sm text-gray-400">
                        Este empleado no recibe nada por transferencia &mdash; todo su pago va en efectivo.
                    </p>
                </div>
            </div>

            <!-- Tabla: Efectivo -->
            <div v-if="fullView || viewingCash" class="bg-white rounded-lg shadow overflow-hidden border-l-4 border-pink-500">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800">Efectivo</h3>
                        <p class="text-xs text-gray-400">Extras + base de quien cobra en efectivo</p>
                    </div>
                    <span
                        v-if="cashSplit.status"
                        class="px-2 py-1 rounded-full text-xs font-medium"
                        :class="cashSplit.status === 'paid' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'"
                    >
                        {{ cashSplit.status === 'paid' ? 'Cobrado' : 'Pendiente' }}
                    </span>
                </div>
                <div class="p-6">
                    <table class="min-w-full text-sm">
                        <tbody>
                            <template v-for="(l, i) in efectivoLines" :key="i">
                                <tr class="border-b last:border-0">
                                    <td class="py-2">
                                        <span class="text-gray-600">{{ l.label }}</span>
                                        <span v-if="l.detail" class="text-xs text-gray-400 ml-2">({{ l.detail }})</span>
                                    </td>
                                    <td class="py-2 text-right font-medium" :class="l.amount < 0 ? 'text-red-600' : 'text-gray-800'">
                                        {{ l.amount < 0 ? '-' : '' }}{{ formatCurrency(Math.abs(l.amount)) }}
                                    </td>
                                </tr>
                                <!-- Qué hay dentro del renglón (ej. los bonos) -->
                                <tr v-for="(c, ci) in (l.children || [])" :key="`c-${i}-${ci}`" class="text-xs text-gray-500">
                                    <td class="py-1 pl-6">
                                        {{ c.label }}
                                        <span v-if="c.detail" class="text-gray-400 ml-1">({{ c.detail }})</span>
                                    </td>
                                    <td class="py-1 text-right">{{ formatCurrency(c.amount) }}</td>
                                </tr>
                                <!-- Detalle de la falta: qué días y por qué se descuenta -->
                                <template v-if="l.isDeduction">
                                    <tr v-for="(d, di) in deductionDetail" :key="`d-${i}-${di}`" class="text-xs text-red-500/80">
                                        <td class="py-1 pl-6">{{ d.label }}</td>
                                        <td class="py-1 text-right">-{{ formatCurrency(Math.abs(d.amount)) }}</td>
                                    </tr>
                                </template>
                                <!-- Tiempo extra aprobado que no se paga completo: por qué -->
                                <template v-if="l.overtimeNote">
                                    <tr v-for="(o, oi) in overtimeShortfalls" :key="`o-${i}-${oi}`" class="text-xs text-amber-700">
                                        <td class="py-1 pl-6" colspan="2">
                                            {{ formatDayLabel(o.date) }}: aprobadas {{ o.authorized_hours }} h, pagadas {{ o.paid_hours }} h —
                                            la checada solo respalda {{ o.paid_hours }} h.
                                            <template v-if="o.overlapping">
                                                Además, ese día hay autorizaciones con el horario encimado: revisa las ventanas.
                                            </template>
                                        </td>
                                    </tr>
                                </template>
                                <!-- Fin de semana aprobado que NO contó: por qué -->
                                <template v-if="l.weekendNote">
                                    <tr v-for="(w, wi) in weekendNotCounted" :key="`w-${i}-${wi}`" class="text-xs text-amber-700">
                                        <td class="py-1 pl-6" colspan="2">
                                            {{ formatDayLabel(w.date) }}: {{ w.gross_hours }} h corridas — no cuenta como fin de semana
                                            (el mínimo son {{ w.threshold }} h); esas horas se pagan como tiempo extra.
                                        </td>
                                    </tr>
                                </template>
                            </template>
                            <tr v-if="!efectivoLines.length">
                                <td colspan="2" class="py-2 text-sm text-gray-400">Sin efectivo este periodo.</td>
                            </tr>
                            <!-- Capturado pero sin monto configurado: pagó $0 -->
                            <tr v-for="(z, zi) in unpaidZeroAmountConcepts" :key="`z-${zi}`" class="text-xs text-amber-700 bg-amber-50">
                                <td class="py-1">
                                    ⚠ {{ z.name }}
                                    <span v-if="z.quantity"> ({{ z.quantity }})</span>
                                    — capturado pero NO pagado: {{ z.reason }}
                                </td>
                                <td class="py-1 text-right">{{ formatCurrency(0) }}</td>
                            </tr>
                            <!-- Prima automática por vacaciones NO sumada: hay una prima capturada a mano -->
                            <tr v-if="suppressedVacationPremium" class="text-xs text-gray-400">
                                <td class="py-1">
                                    Prima vacacional automática — no se suma: {{ suppressedVacationPremium.reason }}
                                </td>
                                <td class="py-1 text-right">{{ formatCurrency(0) }}</td>
                            </tr>
                            <!-- Concepto de sueldo no pagado: ya viene en el sueldo base -->
                            <tr v-for="(c, si) in suppressedSalaryConcepts" :key="`s-${si}`" class="text-xs text-gray-400">
                                <td class="py-1">
                                    {{ c.name }} — no se paga: {{ c.reason }}
                                </td>
                                <td class="py-1 text-right">{{ formatCurrency(0) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-gray-200">
                                <td class="py-3 font-semibold text-gray-800">Efectivo de este periodo</td>
                                <td class="py-3 text-right font-semibold text-gray-800">{{ formatCurrency(efectivoSubtotal) }}</td>
                            </tr>
                            <tr v-if="Math.abs(pesoRounding) >= 0.005" :class="roundingIsCents ? 'text-gray-400' : 'text-amber-600 font-medium'">
                                <td class="py-1">{{ roundingIsCents ? 'Redondeo al peso' : 'Sin desglosar (revisar)' }}</td>
                                <td class="py-1 text-right">{{ pesoRounding >= 0 ? '+' : '-' }}{{ formatCurrency(Math.abs(pesoRounding)) }}</td>
                            </tr>
                            <tr v-if="cashSplit.opening_balance > 0" class="text-amber-600">
                                <td class="py-1">Acumulado (no cobrado de la semana anterior)</td>
                                <td class="py-1 text-right font-medium">+{{ formatCurrency(cashSplit.opening_balance) }}</td>
                            </tr>
                            <tr v-if="cashSplit.amount_paid > 0" class="text-gray-400">
                                <td class="py-1">Ya cobrado</td>
                                <td class="py-1 text-right">-{{ formatCurrency(cashSplit.amount_paid) }}</td>
                            </tr>
                            <tr class="border-t border-gray-200">
                                <td class="py-3 font-bold text-gray-800">
                                    {{ cashSplit.amount_paid > 0 ? 'Pendiente de cobrar' : 'Total a cobrar en efectivo' }}
                                </td>
                                <td class="py-3 text-right font-bold text-pink-600 text-lg">
                                    {{ formatCurrency(cashSplit.amount_paid > 0 ? cashSplit.outstanding : cashSplit.total_due) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                    <p v-if="!cashSplit.is_closed" class="mt-3 text-xs text-gray-400">
                        Estimado &mdash; el acumulado y el total se congelan al cerrar y preparar el efectivo del periodo.
                    </p>
                </div>
            </div>
        </div>

        <!-- Incidents Summary (from breakdown) -->
        <div v-if="fullView && breakdown.incidents" class="bg-white rounded-lg shadow p-6 mt-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Incidencias en el Periodo</h3>
            <div class="grid grid-cols-5 gap-4">
                <div class="text-center">
                    <p class="text-xl font-bold text-purple-600">{{ breakdown.incidents.vacation_days || 0 }}</p>
                    <p class="text-xs text-gray-500">Vacaciones</p>
                </div>
                <div class="text-center">
                    <p class="text-xl font-bold text-pink-600">{{ breakdown.incidents.sick_leave_days || 0 }}</p>
                    <p class="text-xs text-gray-500">Incapacidad</p>
                </div>
                <div class="text-center">
                    <p class="text-xl font-bold text-blue-600">{{ breakdown.incidents.permission_days || 0 }}</p>
                    <p class="text-xs text-gray-500">Permisos</p>
                </div>
                <div class="text-center">
                    <p class="text-xl font-bold text-red-600">{{ breakdown.incidents.absence_days || 0 }}</p>
                    <p class="text-xs text-gray-500">Faltas</p>
                </div>
                <div class="text-center">
                    <p class="text-xl font-bold text-orange-600">{{ breakdown.incidents.unpaid_days || 0 }}</p>
                    <p class="text-xs text-gray-500">Sin Goce</p>
                </div>
            </div>
        </div>

        <!-- Back Button -->
        <div class="mt-6">
            <Link
                :href="route('payroll.show', entry.payroll_period_id)"
                class="px-6 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200"
            >
                Volver al Periodo
            </Link>
        </div>
    </AppLayout>
</template>
