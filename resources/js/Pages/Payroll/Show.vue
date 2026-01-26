<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    period: Object,
    entries: Array,
    summary: Object,
    can: Object,
});

const search = ref('');
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

const formatDate = (date) => {
    return new Date(date).toLocaleDateString('es-MX', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
};

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN',
    }).format(amount || 0);
};

const filteredEntries = () => {
    if (!search.value) return props.entries;
    const s = search.value.toLowerCase();
    return props.entries.filter(e =>
        e.employee?.full_name?.toLowerCase().includes(s) ||
        e.employee?.employee_number?.toLowerCase().includes(s)
    );
};

const calculatePayroll = () => {
    if (confirm('¿Calcular/recalcular la nomina para este periodo? Esto actualizara todos los registros.')) {
        router.post(route('payroll.calculate', props.period.id));
    }
};

const approvePayroll = () => {
    if (confirm('¿Aprobar esta nomina? Una vez aprobada no se podra recalcular.')) {
        router.post(route('payroll.approve', props.period.id));
    }
};

const markPaid = () => {
    if (confirm('¿Marcar esta nomina como pagada?')) {
        router.post(route('payroll.markPaid', props.period.id));
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
                        class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700"
                    >
                        Marcar como Pagada
                    </button>

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

        <!-- Search -->
        <div class="bg-white rounded-lg shadow p-4 mb-6">
            <input
                v-model="search"
                type="text"
                placeholder="Buscar empleado..."
                class="w-full max-w-md rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
            />
        </div>

        <!-- Entries Table -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
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
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Detalle</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <tr v-for="entry in filteredEntries()" :key="entry.id" class="hover:bg-gray-50">
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
                            <Link
                                :href="route('payroll.entry', entry.id)"
                                class="text-pink-600 hover:text-pink-900"
                            >
                                Ver
                            </Link>
                        </td>
                    </tr>
                    <tr v-if="entries.length === 0">
                        <td :colspan="can?.viewComplete ? 10 : 8" class="px-6 py-12 text-center text-gray-500">
                            No hay registros de nomina. Presiona "Calcular Nomina" para generar.
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
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
