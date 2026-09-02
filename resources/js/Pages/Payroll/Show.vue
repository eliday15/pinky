<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import TwoFactorModal from '@/Components/TwoFactorModal.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { formatDate as fmtDate } from '@/utils/date';
import { periodInfo } from '@/utils/payrollPeriodType';

const props = defineProps({
    period: Object,
    entries: Array,
    summary: Object,
    can: Object,
    cfdi: Object, // { stamped, pending, error, canceled, stampable }
    // Nómina semanal con la que ESTA mensual se puede unificar (un solo pago),
    // o null si no hay ninguna que se pueda tocar. { id, name }
    unifiableWeek: { type: Object, default: null },
    // Conceptos capturados y aprobados que pagaron $0 porque el concepto no
    // tiene monto configurado. [{ employee, concept, quantity, date }]
    zeroAmountAlerts: { type: Array, default: () => [] },
    // Autorizaciones aprobadas que ninguna nómina paga (falta el periodo del
    // alcance que les toca). [{ employee, concept, date, kind, reason }]
    unpaidAuthorizationAlerts: { type: Array, default: () => [] },
    // Nuevos cuya semana se recortó por la fecha de alta aunque ya tenían días
    // aprobados antes. [{ employee, hire_date, approved_before, first_date }]
    hireDateAlerts: { type: Array, default: () => [] },
});

// Timbrado CFDI: disparar el timbrado del periodo aprobado.
const stampCfdi = () => {
    router.post(route('payroll.cfdi.stamp', props.period.id), {}, { preserveScroll: true });
};
// Unifica esta nómina mensual con la semana que se paga el mismo día: los
// extras del mes pasan a la semana y esta desaparece (un solo pago).
const unifying = ref(false);
const unifyWithWeek = () => {
    if (!props.unifiableWeek) return;
    if (!confirm(`Los extras de "${props.period.name}" se van a pagar junto con "${props.unifiableWeek.name}" (un solo pago) y esta nómina mensual desaparece. ¿Continuar?`)) {
        return;
    }
    unifying.value = true;
    router.post(route('payroll.unify', props.period.id), {}, {
        onFinish: () => { unifying.value = false; },
    });
};

const cancelCfdi = () => {
    router.post(route('payroll.cfdi.cancel', props.period.id), {}, { preserveScroll: true });
};

const typeInfo = computed(() => periodInfo(props.period));

const hasTwoFactor = computed(() => usePage().props.auth.has_two_factor);
const showApproveModal = ref(false);
const showMarkPaidModal = ref(false);

const search = ref('');
const deptFilter = ref('');
const channelFilter = ref(''); // '' | 'efectivo' | 'transfer'
const showExportMenu = ref(false);

// Alterna el filtro por canal (efectivo/transferencia) en la misma tabla.
const toggleChannel = (channel) => {
    channelFilter.value = channelFilter.value === channel ? '' : channel;
};

const statusColors = {
    draft: 'bg-gray-100 text-gray-800',
    calculating: 'bg-blue-100 text-blue-800',
    review: 'bg-yellow-100 text-yellow-800',
    approved: 'bg-green-100 text-green-800',
    paid: 'bg-purple-100 text-purple-800',
};

const statusLabels = {
    draft: 'Borrador',
    calculating: 'Calculando',
    review: 'En Revision',
    approved: 'Aprobada',
    paid: 'Pagada',
};

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

// Etiquetas de los rubros del costo patronal (summary.employer_cost_by_rubro).
const employerRubroLabels = {
    eym_fixed: 'EyM cuota fija',
    eym_excess: 'EyM excedente',
    eym_money_gmp: 'EyM dinero+GMP',
    iv: 'Invalidez y Vida',
    cyv: 'Cesantía y Vejez',
    guarderia: 'Guarderías',
    retiro: 'Retiro SAR',
    infonavit: 'Infonavit',
    riesgo_trabajo: 'Riesgo de Trabajo',
    absorbed_worker: 'Cuota obrera absorbida (mínimos)',
    isn: 'Impuesto s/ nómina',
};

// Departamentos presentes en el periodo, para el autocomplete del filtro.
const departments = computed(() => {
    const names = props.entries.map(e => e.employee?.department?.name).filter(Boolean);
    return [...new Set(names)].sort((a, b) => a.localeCompare(b, 'es'));
});

// Tabla filtrada por canal (efectivo/transferencia), departamento (autocomplete)
// y/o por nombre/número.
const visibleEntries = computed(() => {
    let list = props.entries;
    if (channelFilter.value === 'efectivo') {
        list = list.filter(e => Number(e.cash_amount) > 0);
    } else if (channelFilter.value === 'transfer') {
        list = list.filter(e => Number(e.bank_amount) > 0);
    }
    if (deptFilter.value) {
        const d = deptFilter.value.toLowerCase();
        list = list.filter(e => (e.employee?.department?.name || '').toLowerCase().includes(d));
    }
    if (search.value) {
        const s = search.value.toLowerCase();
        list = list.filter(e =>
            e.employee?.full_name?.toLowerCase().includes(s) ||
            e.employee?.employee_number?.toLowerCase().includes(s)
        );
    }
    return list;
});

// Recalcular tarda unos segundos para toda la plantilla: el botón y el banner
// muestran "calculando…" mientras corre y el toast global avisa al terminar.
const calculating = ref(false);

const calculatePayroll = () => {
    if (confirm('¿Calcular/recalcular la nomina para este periodo? Esto actualizara todos los registros.')) {
        router.post(route('payroll.calculate', props.period.id), {}, {
            preserveScroll: true,
            onStart: () => { calculating.value = true; },
            onFinish: () => { calculating.value = false; },
        });
    }
};

const approvePayroll = () => {
    if (hasTwoFactor.value) {
        showApproveModal.value = true;
    } else if (confirm('¿Aprobar esta nomina? Una vez aprobada no se podra recalcular.')) {
        router.post(route('payroll.approve', props.period.id));
    }
};

const markPaid = () => {
    if (hasTwoFactor.value) {
        showMarkPaidModal.value = true;
    } else if (confirm('¿Marcar esta nomina como pagada?')) {
        router.post(route('payroll.markPaid', props.period.id));
    }
};

const closeCash = () => {
    if (confirm('¿Cerrar y preparar el efectivo? Se calculara el desglose de billetes y el acumulado por empleado.')) {
        router.post(route('payroll.closeCash', props.period.id));
    }
};
</script>

<template>
    <Head :title="`Nomina: ${period.name}`" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Detalle de Nomina
            </h2>
        </template>

        <div class="mb-6">
            <Link
                :href="route('payroll.index')"
                class="text-pink-600 hover:text-pink-800"
            >
                &larr; Volver a nominas
            </Link>
        </div>

        <!-- Aviso mientras corre el recálculo: sin esto el usuario no sabe que
             el sistema está trabajando y busca resultados antes de tiempo. -->
        <div
            v-if="calculating"
            class="mb-6 flex items-center gap-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-blue-800"
        >
            <svg class="h-5 w-5 shrink-0 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
            </svg>
            <p class="text-sm font-medium">
                Recalculando la nómina de todos los empleados del periodo… los totales y conceptos se actualizarán solos al terminar.
            </p>
        </div>

        <!-- Period Header -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="flex items-center gap-3">
                        <h1 class="text-2xl font-bold text-gray-800">{{ period.name }}</h1>
                        <span :class="[statusColors[period.status], 'px-3 py-1 text-sm font-medium rounded-full']">
                            {{ statusLabels[period.status] }}
                        </span>
                        <span
                            v-if="period.department"
                            class="px-3 py-1 text-sm font-medium rounded-full bg-indigo-100 text-indigo-800"
                        >
                            {{ period.department.name }}
                        </span>
                        <span
                            v-else
                            class="px-3 py-1 text-sm font-medium rounded-full bg-gray-100 text-gray-600"
                        >
                            General
                        </span>
                    </div>
                    <p class="text-gray-600 mt-1">
                        {{ formatDate(period.extras_start_date || period.start_date) }} - {{ formatDate(period.end_date) }}
                    </p>
                    <!-- Pago unificado: el sueldo base corre sobre la semana -->
                    <p v-if="period.extras_start_date" class="text-pink-600 mt-1">
                        Incluye el sueldo de la semana {{ formatDate(period.start_date) }} - {{ formatDate(period.end_date) }}
                    </p>
                    <p class="text-sm text-gray-500 mt-1">
                        Fecha de pago: {{ formatDate(period.payment_date) }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-if="can?.calculate && (period.status === 'draft' || period.status === 'review')"
                        @click="calculatePayroll"
                        :disabled="calculating"
                        class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-60 disabled:cursor-wait"
                    >
                        <svg v-if="calculating" class="w-4 h-4 inline mr-1 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                        </svg>
                        <svg v-else class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                        </svg>
                        {{ calculating ? 'Calculando nómina…' : 'Calcular Nomina' }}
                    </button>
                    <button
                        v-if="can?.unify && unifiableWeek"
                        @click="unifyWithWeek"
                        :disabled="unifying"
                        class="px-4 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 disabled:opacity-60"
                        :title="`Los extras del mes se pagan junto con ${unifiableWeek.name}`"
                    >
                        {{ unifying ? 'Unificando…' : `Unificar con ${unifiableWeek.name}` }}
                    </button>
                    <button
                        v-if="can?.approve && period.status === 'review'"
                        @click="approvePayroll"
                        class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700"
                    >
                        Aprobar Nomina
                    </button>
                    <!-- Timbrado CFDI (nómina aprobada): timbra los recibos de los
                         formalizados ante el SAT vía Facturama. -->
                    <button
                        v-if="can?.approve && ['approved', 'paid'].includes(period.status) && cfdi && cfdi.stampable > 0 && cfdi.stamped < cfdi.stampable"
                        @click="stampCfdi"
                        class="px-4 py-2 bg-teal-600 text-white rounded-lg hover:bg-teal-700"
                        :title="`${cfdi.stamped} de ${cfdi.stampable} timbrados`"
                    >
                        Timbrar CFDI ({{ cfdi.stamped }}/{{ cfdi.stampable }})
                    </button>
                    <button
                        v-if="can?.approve && cfdi && cfdi.stamped > 0"
                        @click="cancelCfdi"
                        class="px-4 py-2 bg-white border border-red-300 text-red-600 rounded-lg hover:bg-red-50"
                        title="Cancela los CFDI ante el SAT (necesario para recalcular)"
                    >
                        Cancelar CFDI ({{ cfdi.stamped }})
                    </button>
                    <span
                        v-if="cfdi && cfdi.error > 0"
                        class="px-3 py-2 text-sm bg-red-100 text-red-700 rounded-lg"
                        title="Recibos con error del PAC: corrige los datos y vuelve a timbrar"
                    >
                        {{ cfdi.error }} con error
                    </span>
                    <button
                        v-if="can?.approve && period.status === 'approved'"
                        @click="markPaid"
                        :disabled="!period.cash_closed_at"
                        :title="period.cash_closed_at ? '' : 'Primero cierra y prepara el efectivo'"
                        class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-40 disabled:cursor-not-allowed"
                    >
                        Marcar como Pagada
                    </button>
                    <!-- Preparar el efectivo es exclusivo del custodio (superadmin) -->
                    <button
                        v-if="can?.deliverCash && period.status === 'approved'"
                        @click="closeCash"
                        class="px-4 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700"
                    >
                        {{ period.cash_closed_at ? 'Recalcular efectivo' : 'Cerrar y preparar efectivo' }}
                    </button>
                    <Link
                        v-if="can?.payCash && period.cash_closed_at"
                        :href="route('payroll.cash', period.id)"
                        class="px-4 py-2 bg-pink-100 text-pink-700 rounded-lg hover:bg-pink-200"
                    >
                        Ir a pago en efectivo
                    </Link>
                    <Link
                        v-if="can?.payCash && entries.length > 0"
                        :href="route('payroll.transfers', period.id)"
                        class="px-4 py-2 bg-indigo-100 text-indigo-700 rounded-lg hover:bg-indigo-200"
                    >
                        Transferencias
                    </Link>

                    <!-- Export CONTPAQi Dropdown -->
                    <div class="relative" v-if="can?.export && entries.length > 0">
                        <button
                            @click="showExportMenu = !showExportMenu"
                            class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 flex items-center gap-2"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            Exportar CONTPAQi
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>
                        <div
                            v-if="showExportMenu"
                            class="absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg z-10 border border-gray-200"
                        >
                            <a
                                :href="route('payroll.export.contpaqi', { payroll: period.id, format: 'xlsx' })"
                                class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded-t-lg"
                            >
                                Excel (.xlsx)
                            </a>
                            <a
                                :href="route('payroll.export.contpaqi', { payroll: period.id, format: 'csv' })"
                                class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"
                            >
                                CSV
                            </a>
                            <a
                                :href="route('payroll.export.contpaqi-import', { payroll: period.id })"
                                class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded-b-lg border-t border-gray-100"
                            >
                                Importación (movimientos)
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Flujo de pago: qué falta para poder pagar la nómina -->
        <div v-if="can?.payCash && period.status === 'approved' && !period.cash_closed_at" class="mb-6 rounded-lg border border-amber-300 bg-amber-50 p-3">
            <p class="text-sm text-amber-800">
                <span class="font-semibold">Falta preparar el efectivo.</span>
                No puedes marcar la nómina como pagada hasta cerrar el efectivo:
                <template v-if="can?.deliverCash">
                    presiona <span class="font-medium">&laquo;Cerrar y preparar efectivo&raquo;</span>, prepara la entrega y cobra.
                </template>
                <template v-else>
                    pídele al super admin que cierre y prepare el efectivo (es quien lo custodia).
                </template>
            </p>
        </div>

        <!-- Capturado pero NO pagado: el concepto está en $0 -->
        <div v-if="zeroAmountAlerts.length" class="border border-amber-300 bg-amber-50 rounded-lg p-4 mb-6">
            <p class="text-sm font-semibold text-amber-800">
                {{ zeroAmountAlerts.length }} concepto(s) capturado(s) NO se pagaron: el concepto está en $0
            </p>
            <p class="mt-1 text-sm text-amber-700">
                Se autorizaron pero su concepto no tiene monto configurado, así que sumaron cero.
                Corrige el monto en Compensaciones y vuelve a calcular la nómina.
            </p>
            <ul class="mt-2 space-y-1">
                <li v-for="(a, idx) in zeroAmountAlerts" :key="idx" class="text-sm text-amber-800">
                    <span class="font-medium">{{ a.employee }}</span> — {{ a.concept }}
                    <span v-if="a.quantity"> (cantidad capturada: {{ a.quantity }})</span>
                    <span v-if="a.date" class="text-amber-600"> · {{ formatDate(a.date) }}</span>
                </li>
            </ul>
        </div>

        <!-- Semana corta por la fecha de alta, con días ya aprobados antes -->
        <div v-if="hireDateAlerts.length" class="border border-amber-300 bg-amber-50 rounded-lg p-4 mb-6">
            <p class="text-sm font-semibold text-amber-800">
                {{ hireDateAlerts.length }} empleado(s) con días aprobados ANTES de su fecha de ingreso
            </p>
            <p class="mt-1 text-sm text-amber-700">
                La nómina solo paga desde la fecha de ingreso, así que su semana sale corta.
                Si de verdad entraron antes, corrige la fecha en su ficha y vuelve a calcular.
            </p>
            <ul class="mt-2 space-y-1">
                <li v-for="(a, idx) in hireDateAlerts" :key="idx" class="text-sm text-amber-800">
                    <span class="font-medium">{{ a.employee }}</span> — ingreso {{ formatDate(a.hire_date) }},
                    pero tiene {{ a.approved_before }} día(s) aprobado(s) desde el {{ formatDate(a.first_date) }}
                </li>
            </ul>
        </div>

        <!-- Aprobado y sin nómina que lo pague -->
        <details v-if="unpaidAuthorizationAlerts.length" class="group border border-amber-300 bg-amber-50 rounded-lg mb-6">
            <summary class="flex items-start justify-between gap-4 p-4 cursor-pointer list-none select-none [&::-webkit-details-marker]:hidden">
                <div>
                    <p class="text-sm font-semibold text-amber-800">
                        {{ unpaidAuthorizationAlerts.length }} autorización(es) aprobada(s) que ninguna nómina paga
                    </p>
                    <p class="mt-1 text-sm text-amber-700">
                        Genera la nómina que falta o corrige el periodo de pago del concepto y vuelve a calcular.
                    </p>
                </div>
                <span class="shrink-0 inline-flex items-center gap-1 text-sm font-medium text-amber-800">
                    <span class="group-open:hidden">Ver detalles</span>
                    <span class="hidden group-open:inline">Ocultar detalles</span>
                    <svg
                        class="w-4 h-4 transition-transform group-open:rotate-180"
                        viewBox="0 0 20 20"
                        fill="currentColor"
                        aria-hidden="true"
                    >
                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                    </svg>
                </span>
            </summary>
            <div class="border-t border-amber-200 px-4 py-3">
                <p class="text-sm text-amber-700">
                    Están aprobadas y caen en estas fechas, pero su concepto se paga en un periodo que no existe.
                </p>
                <ul class="mt-2 max-h-80 overflow-y-auto space-y-1 pr-2">
                    <li v-for="(a, idx) in unpaidAuthorizationAlerts" :key="idx" class="text-sm text-amber-800">
                        <span class="font-medium">{{ a.employee }}</span> — {{ a.concept }}
                        <span class="text-amber-600">· {{ formatDate(a.date) }}</span>
                        <span class="text-amber-600"> · {{ a.reason }}</span>
                    </li>
                </ul>
            </div>
        </details>

        <!-- What this period pays -->
        <div class="border rounded-lg p-4 mb-6" :class="typeInfo.tone.box">
            <div class="flex flex-wrap items-center gap-2">
                <span class="px-2 py-0.5 text-xs font-medium rounded-full" :class="typeInfo.tone.chip">{{ typeInfo.label }}</span>
                <p class="text-sm font-semibold" :class="typeInfo.tone.title">{{ typeInfo.title }}</p>
            </div>
            <p class="mt-1 text-sm" :class="typeInfo.tone.text">{{ typeInfo.description }}</p>
            <ul class="mt-2 flex flex-wrap gap-x-4 gap-y-1">
                <li
                    v-for="(item, idx) in typeInfo.pays"
                    :key="idx"
                    class="flex items-center text-sm"
                    :class="typeInfo.tone.text"
                >
                    <span class="w-1.5 h-1.5 rounded-full mr-2" :class="typeInfo.tone.dot"></span>
                    {{ item }}
                </li>
            </ul>
        </div>

        <!-- Reparto del pago: efectivo y transferencia. Cada tarjeta filtra la
             tabla de abajo a solo ese canal (clic de nuevo para quitar el filtro). -->
        <div v-if="entries.length > 0" class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <button
                type="button"
                @click="toggleChannel('efectivo')"
                class="text-left bg-white rounded-lg shadow border-l-4 border-pink-500 p-5 flex items-center justify-between hover:shadow-md hover:bg-pink-50/40 transition cursor-pointer"
                :class="channelFilter === 'efectivo' ? 'ring-2 ring-pink-400' : ''"
            >
                <div>
                    <p class="text-sm font-medium text-gray-500">Efectivo</p>
                    <p class="text-xs text-gray-400">Extras + base de quien cobra en efectivo</p>
                    <p class="text-xs font-medium text-pink-600 mt-1">
                        {{ channelFilter === 'efectivo' ? 'Mostrando solo efectivo — quitar filtro' : 'Ver solo efectivo →' }}
                    </p>
                </div>
                <p class="text-3xl font-bold text-pink-600">{{ formatCurrency(summary.total_cash) }}</p>
            </button>
            <button
                type="button"
                @click="toggleChannel('transfer')"
                class="text-left bg-white rounded-lg shadow border-l-4 border-indigo-500 p-5 flex items-center justify-between hover:shadow-md hover:bg-indigo-50/40 transition cursor-pointer"
                :class="channelFilter === 'transfer' ? 'ring-2 ring-indigo-400' : ''"
            >
                <div>
                    <p class="text-sm font-medium text-gray-500">Transferencia</p>
                    <p class="text-xs text-gray-400">Sueldo base por banco / CONTPAQi</p>
                    <!-- Bruto → retenciones → neto (como la Lista de Raya) -->
                    <div v-if="Number(summary.total_retentions) > 0" class="mt-2 text-xs space-y-0.5">
                        <p class="text-gray-500">Bruto: <span class="font-medium text-gray-700">{{ formatCurrency(summary.total_transfer_gross) }}</span></p>
                        <p class="text-red-500">− Retenciones (ISR/IMSS/Infonavit): {{ formatCurrency(summary.total_retentions) }}</p>
                    </div>
                    <p class="text-xs font-medium text-indigo-600 mt-1">
                        {{ channelFilter === 'transfer' ? 'Mostrando solo transferencias — quitar filtro' : 'Ver solo transferencias →' }}
                    </p>
                </div>
                <div class="text-right">
                    <p v-if="Number(summary.total_retentions) > 0" class="text-xs text-gray-400">Neto a transferir</p>
                    <p class="text-3xl font-bold text-indigo-600">{{ formatCurrency(summary.total_transfer) }}</p>
                </div>
            </button>
        </div>

        <!-- Costo patronal (informativo/provisión: IMSS empresa, SAR, Infonavit, ISN) -->
        <div v-if="can?.viewComplete && Number(summary.total_employer_cost) > 0" class="bg-white rounded-lg shadow border-l-4 border-amber-500 p-5 mb-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-500">Costo patronal (no se descuenta al empleado)</p>
                    <p class="text-xs text-gray-400">Cuotas IMSS de la empresa, Retiro SAR 2%, Infonavit 5%, Impuesto estatal 3% — lo que se provisiona para SUA/pagos</p>
                    <div class="mt-2 text-xs text-gray-500 flex flex-wrap gap-x-4 gap-y-0.5">
                        <span v-for="(label, key) in employerRubroLabels" :key="key">
                            <template v-if="Number(summary.employer_cost_by_rubro?.[key]) > 0">
                                {{ label }}: <span class="font-medium text-gray-700">{{ formatCurrency(summary.employer_cost_by_rubro[key]) }}</span>
                            </template>
                        </span>
                    </div>
                </div>
                <p class="text-3xl font-bold text-amber-600">{{ formatCurrency(summary.total_employer_cost) }}</p>
            </div>
        </div>

        <!-- Summary Cards -->
        <div v-if="entries.length > 0" class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6" :class="{ 'md:grid-cols-5': can?.viewComplete }">
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-gray-800">{{ summary.employee_count }}</p>
                <p class="text-sm text-gray-500">Empleados</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-gray-800">{{ formatCurrency(summary.total_gross) }}</p>
                <p class="text-sm text-gray-500">Total Bruto</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-red-600">{{ formatCurrency(summary.total_deductions) }}</p>
                <p class="text-sm text-gray-500">Deducciones</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-green-600">{{ formatCurrency(summary.total_net) }}</p>
                <p class="text-sm text-gray-500">Total Neto</p>
            </div>
            <!-- Solo visible para nomina completa -->
            <div v-if="can?.viewComplete" class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-blue-600">{{ formatCurrency(summary.total_overtime) }}</p>
                <p class="text-sm text-gray-500">Horas Extra</p>
            </div>
        </div>

        <!-- Search + filtro por departamento (autocomplete) -->
        <div class="bg-white rounded-lg shadow p-4 mb-6 flex flex-wrap items-center gap-3">
            <input
                v-model="search"
                type="text"
                placeholder="Buscar empleado..."
                class="flex-1 min-w-[200px] max-w-md rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
            />
            <input
                v-model="deptFilter"
                list="dept-options"
                type="text"
                placeholder="Todos los departamentos"
                class="w-64 rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
            />
            <datalist id="dept-options">
                <option v-for="d in departments" :key="d" :value="d" />
            </datalist>
            <button
                v-if="deptFilter || search || channelFilter"
                type="button"
                @click="deptFilter = ''; search = ''; channelFilter = ''"
                class="text-sm text-gray-500 hover:text-gray-700"
            >
                Limpiar
            </button>
            <span class="text-sm text-gray-400 ml-auto">
                {{ visibleEntries.length }} de {{ entries.length }} empleados
            </span>
        </div>

        <!-- Entries Table -->
        <div class="bg-white rounded-lg shadow overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Empleado</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Horas</th>
                        <th v-if="can?.viewComplete" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Extras</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Dias</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Faltas</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Retardos</th>
                        <th v-if="can?.viewComplete" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Bruto</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Deducciones</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Neto</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Efectivo</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Transfer.</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Detalle</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <tr v-for="entry in visibleEntries" :key="entry.id" class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <div class="w-8 h-8 rounded-full bg-pink-100 flex items-center justify-center">
                                    <span class="text-pink-600 text-sm font-medium">
                                        {{ entry.employee?.full_name?.charAt(0) || '?' }}
                                    </span>
                                </div>
                                <div class="ml-3">
                                    <p class="text-sm font-medium text-gray-900">{{ entry.employee?.full_name }}</p>
                                    <p class="text-xs text-gray-500">{{ entry.employee?.department?.name }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-900">
                            {{ entry.regular_hours }}h
                        </td>
                        <td v-if="can?.viewComplete" class="px-6 py-4 whitespace-nowrap text-right text-sm">
                            <span :class="entry.overtime_hours > 0 ? 'text-green-600 font-medium' : 'text-gray-500'">
                                {{ entry.overtime_hours }}h
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-900">
                            {{ entry.days_worked }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                            <span :class="entry.days_absent > 0 ? 'text-red-600 font-medium' : 'text-gray-500'">
                                {{ entry.days_absent }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                            <span :class="entry.days_late > 0 ? 'text-yellow-600 font-medium' : 'text-gray-500'">
                                {{ entry.days_late }}
                            </span>
                        </td>
                        <td v-if="can?.viewComplete" class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-900">
                            {{ formatCurrency(entry.gross_pay) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-red-600">
                            {{ entry.deductions > 0 ? '-' + formatCurrency(entry.deductions) : '-' }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium text-gray-900">
                            {{ formatCurrency(entry.net_pay) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                            <span :class="entry.cash_amount > 0 ? 'text-pink-600 font-medium' : 'text-gray-400'">
                                {{ entry.cash_amount > 0 ? formatCurrency(entry.cash_amount) : '-' }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                            <span :class="entry.bank_amount > 0 ? 'text-indigo-600 font-medium' : 'text-gray-400'">
                                {{ entry.bank_amount > 0 ? formatCurrency(entry.bank_amount) : '-' }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                            <Link
                                :href="channelFilter ? route('payroll.entry', { entry: entry.id, canal: channelFilter }) : route('payroll.entry', entry.id)"
                                class="text-pink-600 hover:text-pink-900"
                            >
                                Ver
                            </Link>
                        </td>
                    </tr>
                    <tr v-if="entries.length === 0">
                        <td :colspan="can?.viewComplete ? 12 : 10" class="px-6 py-12 text-center text-gray-500">
                            No hay registros de nomina. Presiona "Calcular Nomina" para generar.
                        </td>
                    </tr>
                    <tr v-else-if="visibleEntries.length === 0">
                        <td :colspan="can?.viewComplete ? 12 : 10" class="px-6 py-12 text-center text-gray-500">
                            Ningun empleado coincide con el filtro.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Department Breakdown -->
        <div v-if="summary.by_department && Object.keys(summary.by_department).length > 0" class="mt-6 bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Resumen por Departamento</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <div
                    v-for="(data, dept) in summary.by_department"
                    :key="dept"
                    class="bg-gray-50 rounded-lg p-4"
                >
                    <h4 class="font-medium text-gray-800">{{ dept }}</h4>
                    <div class="mt-2 text-sm space-y-1">
                        <p class="text-gray-600">
                            <span class="font-medium">{{ data.count }}</span> empleados
                        </p>
                        <p class="text-gray-600">
                            Bruto: <span class="font-medium">{{ formatCurrency(data.total_gross) }}</span>
                        </p>
                        <p class="text-green-600">
                            Neto: <span class="font-medium">{{ formatCurrency(data.total_net) }}</span>
                        </p>
                        <p class="text-pink-600">
                            Efectivo: <span class="font-medium">{{ formatCurrency(data.total_cash) }}</span>
                        </p>
                        <p class="text-indigo-600">
                            Transferencia: <span class="font-medium">{{ formatCurrency(data.total_transfer) }}</span>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Approve 2FA Modal -->
        <TwoFactorModal
            :show="showApproveModal"
            :action="route('payroll.approve', period.id)"
            method="post"
            title="Aprobar Nomina"
            message="Ingresa tu codigo de verificacion para aprobar esta nomina. Una vez aprobada no se podra recalcular."
            @close="showApproveModal = false"
        />

        <!-- Mark Paid 2FA Modal -->
        <TwoFactorModal
            :show="showMarkPaidModal"
            :action="route('payroll.markPaid', period.id)"
            method="post"
            title="Marcar como Pagada"
            message="Ingresa tu codigo de verificacion para marcar esta nomina como pagada."
            @close="showMarkPaidModal = false"
        />
    </AppLayout>
</template>
