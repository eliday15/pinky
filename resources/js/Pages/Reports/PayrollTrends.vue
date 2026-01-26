<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    trendData: Array,
    summary: Object,
});

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(amount || 0);
};

const formatDate = (date) => new Date(date).toLocaleDateString('es-MX', { day: 'numeric', month: 'short', year: 'numeric' });

const getMaxValue = () => {
    if (!props.trendData || props.trendData.length === 0) return 0;
    return Math.max(...props.trendData.map(d => d.total_net));
};

const getBarHeight = (value) => {
    const max = getMaxValue();
    if (max === 0) return 0;
    return (value / max) * 100;
};

const getTrendIndicator = (current, previous) => {
    if (!previous) return { icon: '-', class: 'text-gray-400' };
    const diff = ((current - previous) / previous) * 100;
    if (diff > 2) return { icon: '+', class: 'text-green-600', value: diff.toFixed(1) };
    if (diff < -2) return { icon: '-', class: 'text-red-600', value: Math.abs(diff).toFixed(1) };
    return { icon: '=', class: 'text-gray-400', value: '0' };
};
</script>

<template>
    <Head title="Tendencias de Nomina" />
    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Tendencias de Nomina</h2>
        </template>

        <div class="mb-6">
            <Link :href="route('reports.index')" class="text-pink-600 hover:text-pink-800">&larr; Volver a reportes</Link>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-gray-800">{{ summary.periods_count }}</p>
                <p class="text-xs text-gray-500">Periodos</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-lg font-bold text-emerald-600">{{ formatCurrency(summary.total_paid) }}</p>
                <p class="text-xs text-gray-500">Total Pagado</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-lg font-bold text-blue-600">{{ formatCurrency(summary.avg_total_net) }}</p>
                <p class="text-xs text-gray-500">Promedio/Periodo</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-lg font-bold text-green-600">{{ formatCurrency(summary.max_total_net) }}</p>
                <p class="text-xs text-gray-500">Mayor Periodo</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-lg font-bold text-orange-600">{{ formatCurrency(summary.min_total_net) }}</p>
                <p class="text-xs text-gray-500">Menor Periodo</p>
            </div>
        </div>

        <!-- Chart -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-6">Evolucion de Nomina (Ultimos {{ trendData.length }} periodos)</h3>

            <div v-if="trendData.length > 0" class="relative">
                <!-- Bar Chart -->
                <div class="flex items-end justify-around h-64 px-4 border-b border-gray-200">
                    <div v-for="(data, idx) in trendData" :key="idx" class="flex flex-col items-center group relative">
                        <!-- Tooltip -->
                        <div class="absolute bottom-full mb-2 hidden group-hover:block bg-gray-800 text-white text-xs rounded px-2 py-1 whitespace-nowrap z-10">
                            {{ data.period }}<br>
                            {{ formatCurrency(data.total_net) }}<br>
                            {{ data.employee_count }} empleados
                        </div>
                        <!-- Bar -->
                        <div class="w-8 md:w-12 bg-emerald-500 hover:bg-emerald-600 rounded-t transition-all cursor-pointer"
                             :style="{ height: getBarHeight(data.total_net) + '%', minHeight: '4px' }">
                        </div>
                    </div>
                </div>
                <!-- Labels -->
                <div class="flex justify-around mt-2">
                    <div v-for="(data, idx) in trendData" :key="idx" class="text-xs text-gray-500 text-center w-8 md:w-12 truncate">
                        {{ data.period.split(' ')[0] }}
                    </div>
                </div>
            </div>
            <div v-else class="h-64 flex items-center justify-center text-gray-500">
                No hay datos de nomina
            </div>
        </div>

        <!-- Detailed Table -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-800">Detalle por Periodo</h3>
            </div>
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Periodo</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Empleados</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Bruto</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Extras</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Deducciones</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Neto</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Prom/Emp</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Tendencia</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <tr v-for="(data, idx) in trendData" :key="idx" class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <p class="text-sm font-medium text-gray-900">{{ data.period }}</p>
                            <p class="text-xs text-gray-500">{{ formatDate(data.start_date) }}</p>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-900 font-medium">
                            {{ data.employee_count }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-900">
                            {{ formatCurrency(data.total_gross) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-green-600 font-medium">
                            {{ formatCurrency(data.total_overtime) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-red-600">
                            {{ formatCurrency(data.total_deductions) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold text-emerald-600">
                            {{ formatCurrency(data.total_net) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-500">
                            {{ formatCurrency(data.avg_pay) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <span v-if="idx > 0" :class="getTrendIndicator(data.total_net, trendData[idx-1]?.total_net).class" class="text-sm font-bold">
                                {{ getTrendIndicator(data.total_net, trendData[idx-1]?.total_net).icon }}{{ getTrendIndicator(data.total_net, trendData[idx-1]?.total_net).value }}%
                            </span>
                            <span v-else class="text-gray-400 text-sm">-</span>
                        </td>
                    </tr>
                    <tr v-if="trendData.length === 0">
                        <td colspan="8" class="px-6 py-12 text-center text-gray-500">
                            No hay periodos de nomina aprobados o pagados
                        </td>
                    </tr>
                </tbody>
                <tfoot v-if="trendData.length > 0" class="bg-gray-100">
                    <tr>
                        <td class="px-6 py-4 font-bold text-gray-900">TOTAL</td>
                        <td class="px-6 py-4 text-right text-sm text-gray-500">-</td>
                        <td class="px-6 py-4 text-right text-sm font-bold text-gray-900">
                            {{ formatCurrency(trendData.reduce((sum, d) => sum + d.total_gross, 0)) }}
                        </td>
                        <td class="px-6 py-4 text-right text-sm font-bold text-green-600">
                            {{ formatCurrency(trendData.reduce((sum, d) => sum + d.total_overtime, 0)) }}
                        </td>
                        <td class="px-6 py-4 text-right text-sm font-bold text-red-600">
                            {{ formatCurrency(trendData.reduce((sum, d) => sum + d.total_deductions, 0)) }}
                        </td>
                        <td class="px-6 py-4 text-right text-sm font-bold text-emerald-600">
                            {{ formatCurrency(summary.total_paid) }}
                        </td>
                        <td colspan="2" class="px-6 py-4"></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-emerald-50 border border-emerald-200 rounded-lg p-4">
                <h4 class="font-medium text-emerald-800 mb-2">Nomina Total</h4>
                <p class="text-2xl font-bold text-emerald-600">{{ formatCurrency(summary.total_paid) }}</p>
                <p class="text-sm text-emerald-700">en {{ summary.periods_count }} periodos</p>
            </div>
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <h4 class="font-medium text-blue-800 mb-2">Variacion Promedio</h4>
                <p class="text-2xl font-bold text-blue-600">
                    {{ summary.max_total_net && summary.min_total_net ? formatCurrency(summary.max_total_net - summary.min_total_net) : '$0' }}
                </p>
                <p class="text-sm text-blue-700">entre min y max</p>
            </div>
            <div class="bg-purple-50 border border-purple-200 rounded-lg p-4">
                <h4 class="font-medium text-purple-800 mb-2">Promedio por Periodo</h4>
                <p class="text-2xl font-bold text-purple-600">{{ formatCurrency(summary.avg_total_net) }}</p>
                <p class="text-sm text-purple-700">neto promedio</p>
            </div>
        </div>
    </AppLayout>
</template>
