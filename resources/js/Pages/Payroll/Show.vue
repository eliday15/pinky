<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import TwoFactorModal from '@/Components/TwoFactorModal.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { formatDate as fmtDate } from '@/utils/date';
import { periodTypeInfo } from '@/utils/payrollPeriodType';

const props = defineProps({
    period: Object,
    entries: Array,
    summary: Object,
    can: Object,
});

const typeInfo = computed(() => periodTypeInfo(props.period.type));

// Las tarjetas de reparto llevan a su pantalla filtrada. Efectivo solo cuando ya
// se preparó el efectivo (hay desglose que ver); transferencias en cuanto hay
// asientos. Mismas condiciones que los botones de arriba.
const canGoCash = computed(() => !!props.can?.payCash && !!props.period.cash_closed_at);
const canGoTransfer = computed(() => !!props.can?.payCash && props.entries.length > 0);

const hasTwoFactor = computed(() => usePage().props.auth.has_two_factor);
const showApproveModal = ref(false);
const showMarkPaidModal = ref(false);

const search = ref('');
const deptFilter = ref('');
const showExportMenu = ref(false);

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

// Departamentos presentes en el periodo, para el autocomplete del filtro.
const departments = computed(() => {
    const names = props.entries.map(e => e.employee?.department?.name).filter(Boolean);
    return [...new Set(names)].sort((a, b) => a.localeCompare(b, 'es'));
});

// Tabla filtrada por departamento (autocomplete) y/o por nombre/número.
const visibleEntries = computed(() => {
    let list = props.entries;
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

const calculatePayroll = () => {
    if (confirm('¿Calcular/recalcular la nomina para este periodo? Esto actualizara todos los registros.')) {
        router.post(route('payroll.calculate', props.period.id));
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

        <!-- Period Header -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="flex items-center gap-3">
                        <h1 class="text-2xl font-bold text-gray-800">{{ period.name }}</h1>
                        <span :class="[statusColors[period.status], 'px-3 py-1 text-sm font-medium rounded-full']">
                            {{ statusLabels[period.status] }}
                        </span>
                    </div>
                    <p class="text-gray-600 mt-1">
                        {{ formatDate(period.start_date) }} - {{ formatDate(period.end_date) }}
                    </p>
                    <p class="text-sm text-gray-500 mt-1">
                        Fecha de pago: {{ formatDate(period.payment_date) }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-if="can?.calculate && (period.status === 'draft' || period.status === 'review')"
                        @click="calculatePayroll"
                        class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
                    >
                        <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                        </svg>
                        Calcular Nomina
                    </button>
                    <button
                        v-if="can?.approve && period.status === 'review'"
                        @click="approvePayroll"
                        class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700"
                    >
                        Aprobar Nomina
                    </button>
                    <button
                        v-if="can?.approve && period.status === 'approved'"
                        @click="markPaid"
                        :disabled="!period.cash_closed_at"
                        :title="period.cash_closed_at ? '' : 'Primero cierra y prepara el efectivo'"
                        class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-40 disabled:cursor-not-allowed"
                    >
                        Marcar como Pagada
                    </button>
                    <button
                        v-if="can?.payCash && period.status === 'approved'"
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
                                class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded-b-lg"
                            >
                                CSV
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
                No puedes marcar la nómina como pagada hasta cerrar el efectivo: presiona
                <span class="font-medium">&laquo;Cerrar y preparar efectivo&raquo;</span>, prepara la entrega y cobra.
            </p>
        </div>

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

        <!-- Reparto del pago: efectivo y transferencia. Cada tarjeta lleva a su
             pantalla filtrada (solo efectivo / solo transferencias). -->
        <div v-if="entries.length > 0" class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <component
                :is="canGoCash ? Link : 'div'"
                :href="canGoCash ? route('payroll.cash', period.id) : undefined"
                class="bg-white rounded-lg shadow border-l-4 border-pink-500 p-5 flex items-center justify-between"
                :class="canGoCash ? 'hover:shadow-md hover:bg-pink-50/40 transition cursor-pointer' : ''"
            >
                <div>
                    <p class="text-sm font-medium text-gray-500">Efectivo</p>
                    <p class="text-xs text-gray-400">Extras + base de quien cobra en efectivo</p>
                    <p v-if="canGoCash" class="text-xs font-medium text-pink-600 mt-1">Ver solo efectivo &rarr;</p>
                </div>
                <p class="text-3xl font-bold text-pink-600">{{ formatCurrency(summary.total_cash) }}</p>
            </component>
            <component
                :is="canGoTransfer ? Link : 'div'"
                :href="canGoTransfer ? route('payroll.transfers', period.id) : undefined"
                class="bg-white rounded-lg shadow border-l-4 border-indigo-500 p-5 flex items-center justify-between"
                :class="canGoTransfer ? 'hover:shadow-md hover:bg-indigo-50/40 transition cursor-pointer' : ''"
            >
                <div>
                    <p class="text-sm font-medium text-gray-500">Transferencia</p>
                    <p class="text-xs text-gray-400">Sueldo base por banco / CONTPAQi</p>
                    <p v-if="canGoTransfer" class="text-xs font-medium text-indigo-600 mt-1">Ver solo transferencias &rarr;</p>
                </div>
                <p class="text-3xl font-bold text-indigo-600">{{ formatCurrency(summary.total_transfer) }}</p>
            </component>
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
                v-if="deptFilter || search"
                type="button"
                @click="deptFilter = ''; search = ''"
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
                                :href="route('payroll.entry', entry.id)"
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
