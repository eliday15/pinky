<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    startDate: String,
    endDate: String,
    byEmployee: Array,
    summary: Object,
});

const dateRange = ref({
    start: props.startDate,
    end: props.endDate,
});

const applyFilter = () => {
    router.get(route('reports.overtime'), {
        start_date: dateRange.value.start,
        end_date: dateRange.value.end,
    }, {
        preserveState: true,
        replace: true,
    });
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
</script>

<template>
    <Head title="Reporte de Horas Extra" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Reporte de Horas Extra
            </h2>
        </template>

        <div class="mb-6">
            <Link :href="route('reports.index')" class="text-pink-600 hover:text-pink-800">
                &larr; Volver a reportes
            </Link>
        </div>

        <!-- Date Range -->
        <div class="bg-white rounded-lg shadow p-4 mb-6">
            <div class="flex flex-wrap items-end gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Fecha Inicio</label>
                    <input
                        v-model="dateRange.start"
                        type="date"
                        class="rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                    />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Fecha Fin</label>
                    <input
                        v-model="dateRange.end"
                        type="date"
                        class="rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                    />
                </div>
                <button
                    @click="applyFilter"
                    class="px-4 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700"
                >
                    Aplicar
                </button>
            </div>
            <p class="mt-2 text-sm text-gray-500">
                Mostrando del {{ formatDate(startDate) }} al {{ formatDate(endDate) }}
            </p>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-3 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-gray-800">{{ summary.total_employees }}</p>
                <p class="text-xs text-gray-500">Empleados con Extras</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-green-600">{{ summary.total_overtime_hours }}h</p>
                <p class="text-xs text-gray-500">Total Horas Extra</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-blue-600">{{ summary.total_days_with_overtime }}</p>
                <p class="text-xs text-gray-500">Dias con Extras</p>
            </div>
        </div>

        <!-- Employee Table -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Empleado</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Dias con Extra</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Horas Extra</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Costo Estimado</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <tr v-for="row in byEmployee" :key="row.employee?.id" class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <div class="w-8 h-8 rounded-full bg-pink-100 flex items-center justify-center">
                                    <span class="text-pink-600 text-sm font-medium">
                                        {{ row.employee?.full_name?.charAt(0) || '?' }}
                                    </span>
                                </div>
                                <div class="ml-3">
                                    <p class="text-sm font-medium text-gray-900">{{ row.employee?.full_name }}</p>
                                    <p class="text-xs text-gray-500">{{ row.employee?.department?.name }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-900">
                            {{ row.days_with_overtime }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold text-green-600">
                            {{ row.total_overtime }}h
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium text-gray-900">
                            {{ formatCurrency(row.estimated_cost) }}
                        </td>
                    </tr>
                    <tr v-if="byEmployee.length === 0">
                        <td colspan="4" class="px-6 py-12 text-center text-gray-500">
                            No hay registros de horas extra para este periodo
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
