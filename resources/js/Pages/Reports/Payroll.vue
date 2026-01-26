<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    periods: Array,
    selectedPeriod: Object,
    entries: Array,
    summary: Object,
});

const periodId = ref(props.selectedPeriod?.id || '');

watch(periodId, (newId) => {
    if (newId) {
        router.get(route('reports.payroll'), { period: newId }, {
            preserveState: true,
            replace: true,
        });
    }
});

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
</script>

<template>
    <Head title="Reporte de Nomina" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Reporte de Nomina
            </h2>
        </template>

        <div class="mb-6">
            <Link :href="route('reports.index')" class="text-pink-600 hover:text-pink-800">
                &larr; Volver a reportes
            </Link>
        </div>

        <!-- Period Selector -->
        <div class="bg-white rounded-lg shadow p-4 mb-6">
            <label class="block text-sm font-medium text-gray-700 mb-2">Seleccionar Periodo</label>
            <select
                v-model="periodId"
                class="w-full max-w-md rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
            >
                <option value="">Seleccionar periodo...</option>
                <option v-for="period in periods" :key="period.id" :value="period.id">
                    {{ period.name }} ({{ formatDate(period.start_date) }} - {{ formatDate(period.end_date) }})
                </option>
            </select>
        </div>

        <template v-if="selectedPeriod && summary">
            <!-- Period Info -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-xl font-bold text-gray-800">{{ selectedPeriod.name }}</h3>
                        <p class="text-gray-500">
                            {{ formatDate(selectedPeriod.start_date) }} - {{ formatDate(selectedPeriod.end_date) }}
                        </p>
                    </div>
                    <span :class="[statusColors[selectedPeriod.status], 'px-3 py-1 text-sm font-medium rounded-full']">
                        {{ statusLabels[selectedPeriod.status] }}
                    </span>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
                <div class="bg-white rounded-lg shadow p-4 text-center">
                    <p class="text-2xl font-bold text-gray-800">{{ summary.total_employees }}</p>
                    <p class="text-xs text-gray-500">Empleados</p>
                </div>
                <div class="bg-white rounded-lg shadow p-4 text-center">
                    <p class="text-2xl font-bold text-blue-600">{{ formatCurrency(summary.total_gross) }}</p>
                    <p class="text-xs text-gray-500">Total Bruto</p>
                </div>
                <div class="bg-white rounded-lg shadow p-4 text-center">
                    <p class="text-2xl font-bold text-green-600">{{ formatCurrency(summary.total_net) }}</p>
                    <p class="text-xs text-gray-500">Total Neto</p>
                </div>
                <div class="bg-white rounded-lg shadow p-4 text-center">
                    <p class="text-2xl font-bold text-orange-600">{{ formatCurrency(summary.total_overtime) }}</p>
                    <p class="text-xs text-gray-500">Horas Extra</p>
                </div>
                <div class="bg-white rounded-lg shadow p-4 text-center">
                    <p class="text-2xl font-bold text-red-600">{{ formatCurrency(summary.total_deductions) }}</p>
                    <p class="text-xs text-gray-500">Deducciones</p>
                </div>
                <div class="bg-white rounded-lg shadow p-4 text-center">
                    <p class="text-2xl font-bold text-purple-600">{{ formatCurrency(summary.avg_pay) }}</p>
                    <p class="text-xs text-gray-500">Promedio</p>
                </div>
            </div>

            <!-- Entries Table -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Empleado</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Horas</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Extras</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Bruto</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Deducciones</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Neto</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <tr v-for="entry in entries" :key="entry.id" class="hover:bg-gray-50">
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
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                <span :class="entry.overtime_hours > 0 ? 'text-green-600 font-medium' : 'text-gray-500'">
                                    {{ entry.overtime_hours }}h
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-900">
                                {{ formatCurrency(entry.gross_pay) }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                <span :class="entry.deductions > 0 ? 'text-red-600 font-medium' : 'text-gray-500'">
                                    {{ entry.deductions > 0 ? '-' + formatCurrency(entry.deductions) : '-' }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold text-gray-900">
                                {{ formatCurrency(entry.net_pay) }}
                            </td>
                        </tr>
                    </tbody>
                    <tfoot class="bg-gray-50">
                        <tr>
                            <td class="px-6 py-4 font-bold text-gray-800" colspan="3">TOTALES</td>
                            <td class="px-6 py-4 text-right font-bold text-gray-800">
                                {{ formatCurrency(summary.total_gross) }}
                            </td>
                            <td class="px-6 py-4 text-right font-bold text-red-600">
                                -{{ formatCurrency(summary.total_deductions) }}
                            </td>
                            <td class="px-6 py-4 text-right font-bold text-green-600">
                                {{ formatCurrency(summary.total_net) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </template>

        <div v-else class="bg-white rounded-lg shadow p-12 text-center">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            <h3 class="mt-4 text-lg font-medium text-gray-900">Selecciona un periodo</h3>
            <p class="mt-2 text-gray-500">Elige un periodo de nomina para ver el reporte.</p>
        </div>
    </AppLayout>
</template>
